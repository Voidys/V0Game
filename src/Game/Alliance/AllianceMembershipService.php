<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Lifecycle of an alliance and its membership: founding, leaving, kicking, handing the leadership over and
 * disbanding. The rules are the ones of the alliance pages: the leader can neither leave nor be kicked (he has to
 * transfer or disband), nobody can kick a member holding a right he lacks himself, and a successor must be a member
 * with the TRANSFER right.
 *
 * Side effects: writes users.ally_id/ally_rank_id/ally_register_time, statpoints.id_ally, alliance rows and the
 * member counter; leaving notifies the managers through AllianceMessenger. Early exits are AllianceException.
 */
class AllianceMembershipService
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
        private readonly AllianceMessenger $messenger,
    ) {}

    /**
     * Checks that the acting user has enough points to found an alliance.
     *
     * @throws AllianceException message page with the missing points
     */
    public function assertMayFound(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        // a member has to leave first (a leader cannot found a second alliance and stay owner of the first)
        if (!empty($USER['ally_id'])) {
            throw AllianceException::message($LNG['al_already_member'], $LNG['sys_back'], 'game_alliance_show');
        }

        $userPoints = $this->repository->userPoints($USER['id']);
        $min_points = $this->configs->get()->alliance_create_min_points;

        if ($userPoints < $min_points) {
            $diff_points = $min_points - $userPoints;
            $messageText = AllianceText::html(
                $LNG,
                'al_make_ally_insufficient_points',
                $this->viewHelpers()->prettyNumber($min_points),
                $this->viewHelpers()->prettyNumber($diff_points),
            );

            throw AllianceException::message($messageText, $LNG['sys_back'], 'game_alliance_show');
        }
    }

    /**
     * Founds an alliance with the acting user as leader: validates tag/name (allowed characters, length 8/30, name
     * rules, unique per universe), inserts the alliance and moves the user and his statistics row into it.
     *
     * @return string "name [tag]" for the confirmation message
     * @throws AllianceException message page (back to the create form) on invalid input
     */
    public function found(string $allianceTag, string $allianceName): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $back = 'game_alliance_create';

        $allianceTag = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $allianceTag);
        $allianceName = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $allianceName);

        if (empty($allianceTag)) {
            throw AllianceException::message($LNG['al_tag_required'], $LNG['sys_back'], $back);
        }

        if (empty($allianceName)) {
            throw AllianceException::message($LNG['al_name_required'], $LNG['sys_back'], $back);
        }

        if (mb_strlen($allianceTag) > 8 || mb_strlen($allianceName) > 30) {
            throw AllianceException::message($LNG['al_name_tag_too_long'], $LNG['sys_back'], $back);
        }

        if (!$this->playerRegistration()->isNameValid($allianceName) || !$this->playerRegistration()->isNameValid($allianceTag)) {
            throw AllianceException::message($LNG['al_newname_specialchar'], $LNG['sys_back'], $back);
        }

        $db = $this->db;

        $sql = 'SELECT COUNT(*) as count FROM %%ALLIANCE%% WHERE ally_universe = :universe
        AND (ally_tag = :allianceTag OR ally_name = :allianceName);';

        $allianceCount = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':allianceTag' => $allianceTag,
            ':allianceName' => $allianceName,
        ], 'count');

        if ($allianceCount != 0) {
            throw AllianceException::message(AllianceText::html($LNG, 'al_already_exists', $allianceName), $LNG['sys_back'], $back);
        }

        $sql = "INSERT INTO %%ALLIANCE%% SET ally_name = :allianceName, ally_tag = :allianceTag, ally_owner = :userId,"
            . " ally_owner_range = :allianceOwnerRange, ally_members = 1, ally_register_time = :time,"
            . " ally_universe = :universe;";
        $db->insert($sql, [
            ':allianceName' => $allianceName,
            ':allianceTag' => $allianceTag,
            ':userId' => $USER['id'],
            ':allianceOwnerRange' => $LNG['al_default_leader_name'],
            ':time' => $this->state->runtime->timestamp,
            ':universe' => $this->universe()->current(),
        ]);

        $allianceId = $db->lastInsertId();

        $sql = "UPDATE %%USERS%% SET ally_id	= :allianceId, ally_rank_id	= 0, ally_register_time = :time"
            . " WHERE id = :userId;";
        $db->update($sql, [
            ':allianceId' => $allianceId,
            ':time' => $this->state->runtime->timestamp,
            ':userId' => $USER['id'],
        ]);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = :allianceId WHERE id_owner = :userId AND stat_type = 1;";
        $db->update($sql, [
            ':allianceId' => $allianceId,
            ':userId' => $USER['id'],
        ]);

        return $allianceName . ' [' . $allianceTag . ']';
    }

    /**
     * The acting user leaves his alliance: the managers are told, user and statistics row are detached and the
     * member counter recomputed.
     *
     * @param array<string, mixed> $alliance
     * @throws AllianceException home for the leader (he must transfer or disband, otherwise the alliance would stay
     *         without a manager)
     */
    public function leave(array $alliance): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        if ($alliance['ally_owner'] == $USER['id']) {
            throw AllianceException::home();
        }

        $this->messenger->memberLeft($alliance['id']);

        $sql = "UPDATE %%USERS%% SET ally_id = 0, ally_register_time = 0, ally_rank_id = 0 WHERE id = :UserID;";
        $db->update($sql, [
            ':UserID' => $USER['id'],
        ]);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = 0 WHERE id_owner = :UserID AND stat_type = 1;";
        $db->update($sql, [
            ':UserID' => $USER['id'],
        ]);

        $this->repository->recountMembers($alliance['id']);
    }

    /**
     * Removes a member from the acting user's alliance.
     *
     * Rules: the target must be a member of this alliance, neither the leader nor the acting user himself; unless
     * the actor is the leader he must hold every right the target's rank carries.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @throws AllianceException home when a rule is violated
     */
    public function kick(array $alliance, array $rights, int|string $id): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT ally_id, ally_rank_id FROM %%USERS%% WHERE id = :id;";
        $kickUser = $db->selectSingle($sql, [
            ':id' => $id,
        ]);

        # Check, if user is in alliance, see #205
        if (empty($kickUser['ally_id']) || $kickUser['ally_id'] != $alliance['id']) {
            throw AllianceException::home();
        }

        // the owner cannot be kicked and nobody can kick a member that holds a right they lack
        if ($id == $alliance['ally_owner'] || $id == $USER['id']) {
            throw AllianceException::home();
        }

        if ($alliance['ally_owner'] != $USER['id'] && $kickUser['ally_rank_id'] != 0) {
            $kickRank = $db->fetchRow(
                $db->qb()
                    ->select(...array_values(AllianceRights::AVAILABLE))
                    ->from($db->table('ALLIANCE_RANK'))
                    ->where('allianceID = :allianceId')
                    ->andWhere('rankID = :rankId')
                    ->setParameter('allianceId', $alliance['id'])
                    ->setParameter('rankId', $kickUser['ally_rank_id']),
            );
            if ($kickRank && !AllianceRights::canHandOut($kickRank, $rights)) {
                throw AllianceException::home();
            }
        }

        $sql = "UPDATE %%USERS%% SET ally_id = 0, ally_register_time = 0, ally_rank_id = 0 WHERE id = :id;";
        $db->update($sql, [
            ':id' => $id,
        ]);

        $this->messenger->memberKicked($id, '[' . $alliance['ally_tag'] . '] ' . $alliance['ally_name']);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = 0 WHERE id_owner = :id AND stat_type = 1;";
        $db->update($sql, [
            ':id' => $id,
        ]);

        $this->repository->recountMembers($alliance['id']);
    }

    /**
     * Hands the leadership to another member: the new leader needs a rank with the TRANSFER right; the old leader
     * takes over that rank, the new leader's rank is cleared (the leader has no rank).
     *
     * @param array<string, mixed> $alliance
     * @throws AllianceException home when the user is not the leader or the successor is not eligible
     */
    public function transfer(array $alliance, int|string $newLeaderId): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        if ($alliance['ally_owner'] != $USER['id']) {
            throw AllianceException::home();
        }

        // the new leader must be another member of this alliance with the TRANSFER right
        $sql = "SELECT u.ally_rank_id FROM %%USERS%% u INNER JOIN %%ALLIANCE_RANK%% r"
            . " ON r.rankID = u.ally_rank_id AND r.allianceID = u.ally_id AND r.TRANSFER = 1"
            . " WHERE u.id = :LeaderID AND u.ally_id = :allianceId AND u.id != :ownerId;";
        $Rank = $db->selectSingle($sql, [
            ':LeaderID' => (int) $newLeaderId,
            ':allianceId' => $alliance['id'],
            ':ownerId' => $USER['id'],
        ]);

        if (!$Rank) {
            throw AllianceException::home();
        }

        $sql = "UPDATE %%USERS%% SET ally_rank_id = :AllyRank WHERE id = :UserID;";
        $db->update($sql, [
            ':UserID' => $USER['id'],
            ':AllyRank' => $Rank['ally_rank_id'],
        ]);

        $sql = "UPDATE %%USERS%% SET ally_rank_id = 0 WHERE id = :LeaderID;";
        $db->update($sql, [
            ':LeaderID' => $newLeaderId,
        ]);

        $sql = "UPDATE %%ALLIANCE%% SET ally_owner = :LeaderID WHERE id = :AllianceID;";
        $db->update($sql, [
            ':LeaderID' => $newLeaderId,
            ':AllianceID' => $alliance['id'],
        ]);
    }

    /**
     * Members that may become the new leader (rank with TRANSFER right, not the leader).
     *
     * @param array<string, mixed> $alliance
     * @return array<int|string, string> user id => "username [rank]"
     */
    public function transferCandidates(array $alliance): array
    {
        $sql = "SELECT u.id, r.rankName, u.username FROM %%USERS%% u INNER JOIN %%ALLIANCE_RANK%% r"
            . " ON r.rankID = u.ally_rank_id AND r.TRANSFER = 1 WHERE u.ally_id = :allianceId"
            . " AND id != :allianceOwner;";
        $transferUserResult = $this->db->select($sql, [
            ':allianceOwner' => $alliance['ally_owner'],
            ':allianceId' => $alliance['id'],
        ]);

        $transferUserList = [];
        foreach ($transferUserResult as $transferUserRow) {
            $transferUserList[$transferUserRow['id']] = $transferUserRow['username'] . " ["
                . $transferUserRow['rankName'] . "]";
        }

        return $transferUserList;
    }

    /**
     * Disbands the alliance (leader only): all members are released, the alliance statistics, requests, ranks and
     * treaties are deleted.
     *
     * @param array<string, mixed> $alliance
     * @return bool false (and nothing changed) when the acting user is not the leader
     */
    public function disband(array $alliance): bool
    {
        $USER = $this->state->USER;
        if ($alliance['ally_owner'] != $USER['id']) {
            return false;
        }

        $db = $this->db;
        $params = [':AllianceID' => $alliance['id']];

        $this->messenger->allianceDisbanded($alliance);

        $db->update("UPDATE %%USERS%% SET ally_id = 0, ally_rank_id = 0, ally_register_time = 0 WHERE ally_id = :AllianceID;", $params);
        $db->update("UPDATE %%STATPOINTS%% SET id_ally = '0' WHERE id_ally = :AllianceID;", $params);
        $db->delete("DELETE FROM %%STATPOINTS%% WHERE id_owner = :AllianceID AND stat_type = 2;", $params);
        $db->delete("DELETE FROM %%STATPOINTS_HISTORY%% WHERE id_owner = :AllianceID AND stat_type = 2;", $params);
        $db->delete("DELETE FROM %%ALLIANCE%% WHERE id = :AllianceID;", $params);
        $db->delete("DELETE FROM %%ALLIANCE_REQUEST%% WHERE allianceId = :AllianceID;", $params);
        $db->delete("DELETE FROM %%ALLIANCE_RANK%% WHERE allianceID = :AllianceID;", $params);
        $db->delete("DELETE FROM %%DIPLO%% WHERE owner_1 = :AllianceID OR owner_2 = :AllianceID;", $params);

        return true;
    }
}
