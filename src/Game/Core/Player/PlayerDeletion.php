<?php

namespace App\Game\Core\Player;

use Exception;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Removes players and their data: alliance membership (leadership hand-over or dissolving the alliance),
 * buddies, fleets, messages, planets, statistics and settings. Collaborators: FleetFunctions (sending fleets
 * back), Database.
 */
class PlayerDeletion
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Removes a player from their alliance and drops pending applications.
     *
     * Last member: the alliance is dissolved. Leader with other members: leadership is handed over. Otherwise
     * the member count is reduced. Side effects: updates/deletes alliance, rank, request and statpoints rows,
     * resets the user's ally_id/ally_rank_id/ally_register_time.
     *
     * @param array<mixed> $userData user row with at least `id` and `ally_id`
     */
    public function removeFromAlliance(array $userData): void
    {
        $db = $this->db;
        if (!empty($userData['ally_id'])) {
            $sql = 'SELECT `ally_owner`, `ally_members` FROM %%ALLIANCE%% WHERE `id` = :allianceId;';
            $allyData = $db->selectSingle($sql, [
                ':allianceId'   => $userData['ally_id'],
            ]);

            if ($allyData['ally_members'] > 1) {
                if ($allyData['ally_owner'] == $userData['id']) { // Removed player is ally leader
                    $this->handOverAllianceLeadership($userData);
                } else {
                    // Removed player is not leader, just reduce member amount
                    $sql = 'UPDATE %%ALLIANCE%% SET `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
                    $db->update($sql, [
                        ':allianceId'   => $userData['ally_id'],
                    ]);
                }
            } else {
                $this->dissolveAlliance((int) $userData['ally_id']);
            }

            $sql = 'UPDATE %%USERS%% SET `ally_id` = 0, `ally_register_time` = 0, `ally_rank_id` = 0 WHERE `id` = :UserID;';
            $db->update($sql, [
                ':UserID' => $userData['id'],
            ]);

            $sql = 'UPDATE %%STATPOINTS%% SET `id_ally` = :resetId WHERE `stat_type` = :type AND `id_owner` = :UserID;';
            $db->update($sql, [
                ':UserID'   => $userData['id'],
                ':resetId'  => 0,
                ':type'     => 1,
            ]);
        }

        $sql = 'DELETE FROM %%ALLIANCE_REQUEST%% WHERE `userID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userData['id'],
        ]);
    }

    /**
     * Passes the alliance leadership on when the leader is removed and other members remain.
     *
     * New leader: the most privileged active member (rank rights transfer/admin/kick/diplomatic/manageusers/
     * ranks, online within 72 hours), else the most recently online member. Without any candidate only the
     * member count is reduced. Side effects: UPDATE users (rank 0 of the new leader), UPDATE alliance
     * (owner, ally_members - 1).
     *
     * @param array<mixed> $userData removed player (id, ally_id)
     */
    private function handOverAllianceLeadership(array $userData): void
    {
        $db = $this->db;
        // Find new leader by respecting rank settings
        $sql = 'SELECT u.`id` FROM %%ALLIANCE_RANK%% r
            JOIN %%USERS%% u ON u.`ally_rank_id` = r.`rankID` AND u.`ally_id` = r.`allianceID`
            WHERE r.`allianceID` = :allianceId
              AND u.`id` != :removedId
              AND u.`onlinetime` > :onlinetime
              AND ( r.`transfer` = 1 OR r.`admin` = 1 OR r.`kick` = 1 OR r.`diplomatic` = 1 OR r.`manageusers` = 1 OR r.`ranks` = 1 )
              ORDER BY r.`transfer` DESC, r.`admin` DESC, r.`kick` DESC, r.`diplomatic` DESC, r.`manageusers` DESC, r.`ranks` DESC, u.`onlinetime` DESC
              LIMIT 1;';
        $newLeaders = $db->select($sql, [
            ':allianceId'   => $userData['ally_id'],
            ':removedId'    => $userData['id'],
            ':onlinetime'   => $this->state->runtime->timestamp - TIME_72_HOURS,
        ]);

        $newLeader = 0;
        if (!empty($newLeaders)) { // New leader by rank found?
            $newLeader = $newLeaders[0]['id'];
        } else {
            // Find new leader by onlinetime
            // (any other member of the alliance, ranked or not)
            $sql = 'SELECT u.`id` FROM %%USERS%% u
                WHERE u.`ally_id` = :allianceId AND u.`id` != :removedId
                ORDER BY u.`onlinetime` DESC LIMIT 1;';
            $newLeader = $db->selectSingle($sql, [
                ':allianceId'   => $userData['ally_id'],
                ':removedId'    => $userData['id'],
            ], 'id');
        }

        if (!empty($newLeader)) { // New leader found?
            // Update rank of new leader
            $sql = 'UPDATE %%USERS%% SET `ally_rank_id` = 0 WHERE `id` = :UserID;';
            $db->update($sql, [
                ':UserID' => $newLeader,
            ]);

            // Update ally_owner to new leader and reduce member amount
            $sql = 'UPDATE %%ALLIANCE%% SET `ally_owner` = :UserID, `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
            $db->update($sql, [
                ':allianceId'   => $userData['ally_id'],
                ':UserID' => $newLeader,
            ]);
        } else {
            // No new leader found, just reduce member amount
            $sql = 'UPDATE %%ALLIANCE%% SET `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
            $db->update($sql, [
                ':allianceId'   => $userData['ally_id'],
            ]);
        }
    }

    /**
     * Deletes an alliance whose last member is removed: alliance, ranks, applications and its statistics.
     *
     * @param int $allianceId
     */
    private function dissolveAlliance(int $allianceId): void
    {
        $db = $this->db;
        // Delete alliance, since there are no members
        $sql = 'DELETE FROM %%ALLIANCE%% WHERE `id` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
        ]);

        $db->delete('DELETE FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` = :allianceId;', [':allianceId' => $allianceId]);

        $sql = 'DELETE FROM %%ALLIANCE_RANK%% WHERE `allianceID` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
        ]);

        $sql = 'DELETE FROM %%ALLIANCE_REQUEST%% WHERE `allianceID` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
        ]);

        $sql = 'DELETE FROM %%DIPLO%% WHERE `owner_1` = :allianceId OR `owner_2` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
        ]);

        $sql = 'DELETE FROM %%STATPOINTS%% WHERE `stat_type` = :type AND `id_owner` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
            ':type'         => 2,
        ]);

        $sql = 'DELETE FROM %%STATPOINTS_HISTORY%% WHERE `stat_type` = :type AND `id_owner` = :allianceId;';
        $db->delete($sql, [
            ':allianceId'   => $allianceId,
            ':type'         => 2,
        ]);

        $sql = 'UPDATE %%STATPOINTS%% SET `id_ally` = :resetId WHERE `id_ally` = :allianceId;';
        $db->update($sql, [
            ':allianceId'   => $allianceId,
            ':resetId'      => 0,
        ]);
    }

    /**
     * Deletes all buddy relations and requests of a player (as owner or sender).
     *
     * @param int $userId
     */
    public function removeFromBuddy(int $userId): void
    {
        $db = $this->db;
        $sql = 'DELETE FROM %%BUDDY%% WHERE `owner` = :userId OR `sender` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /**
     * Deletes a player and everything owned by them (planets, fleets, messages, stats, settings, account link).
     *
     * Fleets of other players heading to the player's planets are sent back. The superuser (ROOT_USER) cannot
     * be deleted. Callers hold the transaction.
     *
     * @param int $userId
     * @return bool false if the user does not exist, true after deletion
     * @throws Exception for ROOT_USER
     */
    public function deletePlayer(int $userId): bool
    {
        if (ROOT_USER == $userId) {
            // superuser can not be deleted.
            throw new Exception("Superuser #" . ROOT_USER . " can't be deleted!");
        }

        $db = $this->db;
        $sql = 'SELECT `id`, `universe`, `ally_id` FROM %%USERS%% WHERE `id` = :userId;';
        $userData = $db->selectSingle($sql, [
            ':userId'   => $userId,
        ]);

        if (empty($userData)) {
            return false;
        }

        $this->removeFromAlliance($userData);

        $this->removeFromBuddy($userId);

        $this->settleBounties($userId);

        $this->deleteMarketOffersAndFleets($userId);

        $this->deleteMessageCopies($userId);

        $this->deleteSecondaryPlayerData($userId);

        $this->deletePlanets($userId);

        // relics, dust and log of the player go with him
        (new \App\Game\Engagement\Relics\RelicInventory($this->db, $this->configs, $this->state))->deleteUser($userId);

        $this->deleteUserRows($userId);

        $this->deleteStatistics($userId);

        $this->recallIncomingFleets($userId);

        $this->unlinkAccount($userId);

        /*
        $sql = 'UPDATE %%UNIVERSE%% SET `userAmount` = `userAmount` - 1 WHERE `universe` = :universe;';
        $db->update($sql, array(
            ':universe' => $userData['universe']
        ));

        Cache::get()->flush('universe');
        */

        return true;
    }

    /**
     * Engagement bounties of the player: open ones on him go back to their placers, the ones he placed vanish (no refund). A failure
     * here (e.g. the tables do not exist yet) must not stop the deletion; only deadlocks are passed on for the retry.
     */
    private function settleBounties(int $userId): void
    {
        try {
            (new \App\Game\Engagement\Bounties\Bounties($this->db, $this->configs, $this->state))->onPlayerDeleted($userId, $this->state->runtime->timestamp);
        } catch (\Throwable $e) {
            if (Database::isRetryable($e)) {
                throw $e;
            }
            error_log('bounties of deleted player ' . $userId . ' not settled: ' . $e->getMessage());
        }
    }

    /**
     * Deletes the marketplace offers made with the player's fleets (they would stay behind without a fleet), then
     * the player's fleets together with their events.
     */
    private function deleteMarketOffersAndFleets(int $userId): void
    {
        $sql = 'DELETE t FROM %%TRADES%% t
            INNER JOIN %%FLEETS%% f ON f.`id` = t.`seller_fleet_id`
            WHERE f.`owner` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);

        $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
            FROM %%FLEETS%% LEFT JOIN %%FLEETS_EVENT%% on `id` = fleetId
            WHERE `owner` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /** Deletes all planets and moons of the player together with their wreck fields. */
    private function deletePlanets(int $userId): void
    {
        $sql = 'DELETE %%PLANETS%%, %%PLANET_WRECKFIELD%%
            FROM %%PLANETS%% LEFT JOIN %%PLANET_WRECKFIELD%% on `planetID` = id
            WHERE `id_owner` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /** Deletes the settings row and the user row itself (settings first, order matters for the foreign keys). */
    private function deleteUserRows(int $userId): void
    {
        $sql = 'DELETE FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);

        $sql = 'DELETE FROM %%USERS%% WHERE `id` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /** Sends fleets of other players that were heading to the deleted player's planets back home. */
    private function recallIncomingFleets(int $userId): void
    {
        $fleetIds = $this->db->select('SELECT `id`, `owner` FROM %%FLEETS%% WHERE `target_owner` = :userId AND `owner` != :userId;', [
            ':userId'   => $userId,
        ]);

        foreach ($fleetIds as $fleetId) {
            $this->fleetFunctions()->sendFleetBack(['id' => $fleetId['owner']], $fleetId['id']);
        }
    }

    /** Removes the link between the player and its account. */
    private function unlinkAccount(int $userId): void
    {
        $sql = 'DELETE FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userId;';
        $this->db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /**
     * Removes the player's copies of all messages and drops messages nobody holds a copy of anymore.
     *
     * Messages are shared rows: every recipient (and the sender) owns a row in users_to_messages. Messages the
     * player sent stay in the recipients' inboxes (sender_id is nulled by the foreign key).
     *
     * @param int $userId
     */
    private function deleteMessageCopies(int $userId): void
    {
        $db = $this->db;
        $messageIds = $db->select('SELECT `message_id` FROM %%USERS_TO_MESSAGES%% WHERE `user_id` = :userId;', [
            ':userId'   => $userId,
        ]);

        $sql = 'DELETE FROM %%USERS_TO_MESSAGES%% WHERE `user_id` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
        ]);

        foreach (array_column($messageIds, 'message_id') as $messageId) {
            $sql = 'DELETE FROM %%MESSAGES%% WHERE `message_id` = :messageId
                AND NOT EXISTS (SELECT 1 FROM %%USERS_TO_MESSAGES%% um WHERE um.`message_id` = :messageId2);';
            $db->delete($sql, [
                ':messageId'  => $messageId,
                ':messageId2' => $messageId,
            ]);
        }
    }

    /**
     * Deletes per-player secondary data: blocklist, comments, analytics, achievements, tutorials, sessions,
     * records, phalanx log, support tickets with answers and notes.
     *
     * Kept on purpose: users_banned (public ban list), users_to_topkb and topkb/raports (battle reports and hall
     * of fame, other players are part of them), log/log_fleets (audit).
     *
     * @param int $userId
     */
    private function deleteSecondaryPlayerData(int $userId): void
    {
        $db = $this->db;

        $perUserTables = [
            'USERS_BLOCKLIST'       => ['blocking_user', 'blocked_user'],
            'ALLIANCE_REQUEST'      => ['userID'],
            'USERS_COMMENTS'        => ['user_id'],
            'USERS_ANALYTICS'       => ['userID'],
            'USERS_TO_ACHIEVEMENTS' => ['userID'],
            'USERS_TO_ACS'          => ['userID'],
            'USERS_TUTORIAL'        => ['userID'],
            'USERS_TUTORIAL_GOAL'   => ['userID'],
            'EXPEDITION_USER_DECK'  => ['user_id'],
            'MULTI_TO_USERS'        => ['userID'],
            'SHORTCUTS'             => ['ownerID'],
            'SESSION'               => ['userID'],
            'RECORDS'               => ['userID'],
            'ADVANCED_STATS_UNIVERSE' => ['userId'],
            'PHALANX_LOG'           => ['owner', 'target'],
            'ENG_COSMETICS'         => ['user_id'],
            'ENG_SEASON_SCORES'     => ['user_id'],
            'ENG_SEASON_PAIRS'      => ['user_id', 'opponent_id'],
            'ENG_MOTHERSHIP_DAMAGE' => ['user_id'],
            'ENG_STORIES'           => ['user_id'],
            'ENG_CONTRACTS'         => ['user_id'],
            'ENG_RETURNS'           => ['user_id'],
            'ENG_CATCHUP'           => ['user_id'],
        ];
        foreach ($perUserTables as $table => $columns) {
            $delete = $db->qb()->delete($db->table($table))->setParameter('userId', $userId);
            foreach ($columns as $column) {
                $delete->orWhere('`' . $column . '` = :userId');
            }
            $db->execute($delete);
        }

        $sql = 'DELETE a FROM %%TICKETS_ANSWER%% a INNER JOIN %%TICKETS%% t ON t.`ticketID` = a.`ticketID`
            WHERE t.`ownerID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
        ]);

        $sql = 'DELETE FROM %%TICKETS%% WHERE `ownerID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
        ]);

        $sql = 'DELETE FROM %%NOTES%% WHERE `owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
        ]);
    }

    /**
     * Deletes the player's statistics: points, points history and advanced stats with history.
     *
     * @param int $userId
     */
    private function deleteStatistics(int $userId): void
    {
        $db = $this->db;

        $sql = 'DELETE FROM %%STATPOINTS%% WHERE `stat_type` = :type AND `id_owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
            ':type'     => 1,
        ]);

        $sql = 'DELETE FROM %%STATPOINTS_HISTORY%% WHERE `stat_type` = :type AND `id_owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
            ':type'     => 1,
        ]);

        $sql = 'DELETE FROM %%ADVANCED_STATS%% WHERE `userId` = :userId;';
        $db->delete($sql, [
            ':userId'    => $userId,
        ]);

        $sql = 'DELETE FROM %%ADVANCED_STATS_HISTORY%% WHERE `userId` = :userId;';
        $db->delete($sql, [
            ':userId'    => $userId,
        ]);
    }
}
