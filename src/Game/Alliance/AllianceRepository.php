<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Read access (and the member counter) of the alliance tables shared by the alliance services: alliances, ranks,
 * applications, members with their points, diplomacy counters and the message receivers of an alliance.
 * No game rules beyond "rights of a member"; the rules live in the other services of this namespace.
 */
class AllianceRepository
{
    use GameUtilities;

    /** the member list columns of every list that shows a player status (see ProtectionRules::userStatus) */
    private const MEMBER_ROW_SQL = "SELECT DISTINCT u.id, u.username, u.galaxy, u.system, u.planet, u.banaday, u.urlaubs_modus,
            u.ally_register_time, u.onlinetime, u.ally_rank_id, s.total_points, u.rebuild_protection, u.rebuild_protection_attackers
            FROM %%USERS%% u
            LEFT JOIN %%STATPOINTS%% as s ON s.stat_type = '1' AND s.id_owner = u.id
            WHERE ally_id = :AllianceID;";

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** @return array<string, mixed>|null the alliance row, null when it does not exist */
    public function find(int|string $allianceId): ?array
    {
        $sql = 'SELECT * FROM %%ALLIANCE%% WHERE id = :allianceId AND ally_universe = :universe;';

        return $this->db->selectSingle($sql, [
            ':allianceId' => $allianceId,
            ':universe' => $this->universe()->current(),
        ]) ?: null;
    }

    /**
     * Whether the user has an open application at an alliance that still exists (an application whose alliance
     * was closed in the meantime does not count).
     */
    public function hasOpenApplication(int $userId): bool
    {
        $db = $this->db;
        $sql = "SELECT `allianceID` FROM %%ALLIANCE_REQUEST%% WHERE userId = :userId;";
        $requestAllianceId = $db->selectSingle($sql, [
            ':userId' => $userId,
        ], 'allianceID');

        $sql = "SELECT `id` FROM %%ALLIANCE%% WHERE `id` = :requestAllianceId;";
        $allianceId = $db->selectSingle($sql, [
            ':requestAllianceId' => $requestAllianceId,
        ], 'id');

        return !empty($allianceId);
    }

    /**
     * Rights of a user inside his own alliance: the leader holds everything, a member with a rank the rights of that
     * rank, everybody else nothing.
     *
     * @param array<string, mixed> $user     the acting user (id, ally_id, ally_rank_id)
     * @param array<string, mixed> $alliance the alliance row
     * @return array<string, bool|int|string> right name => granted
     */
    public function rightsOf(array $user, array $alliance): array
    {
        $rights = null;
        if ($alliance['ally_owner'] == $user['id']) {
            $rights = AllianceRights::all(true);
        } elseif ($user['ally_rank_id'] != 0) {
            // the right columns are the fixed list AllianceRights::AVAILABLE
            $rights = $this->db->fetchRow(
                $this->db->qb()
                    ->select(...array_values(AllianceRights::AVAILABLE))
                    ->from($this->db->table('ALLIANCE_RANK'))
                    ->where('allianceId = :allianceId')
                    ->andWhere('rankID = :ally_rank_id')
                    ->setParameter('allianceId', $alliance['id'])
                    ->setParameter('ally_rank_id', $user['ally_rank_id']),
            );
        }

        return $rights ?: AllianceRights::all(false);
    }

    /** total points (stat type 1) of a player, null without statistics row */
    public function userPoints(int $userId): mixed
    {
        $sql = 'SELECT total_points
			FROM %%STATPOINTS%%
			WHERE id_owner = :userId AND stat_type = :statType';

        return $this->db->selectSingle($sql, [
            ':userId' => $userId,
            ':statType' => 1,
        ], 'total_points');
    }

    /** @return list<int|string> ids of all members */
    public function memberIds(int|string $allianceId): array
    {
        $sql = "SELECT id FROM %%USERS%% WHERE ally_id = :allyID;";

        return array_column($this->db->select($sql, [
            ':allyID' => $allianceId,
        ]), 'id');
    }

    /** @return list<array<string, mixed>> members whose rank carries the DIPLOMATIC right (id, username, rankName) */
    public function diplomats(int|string $allianceId): array
    {
        $sql = "SELECT u.id, u.username, r.rankName FROM %%USERS%% u
                JOIN %%ALLIANCE_RANK%% r ON r.allianceID = u.ally_id AND r.rankID = u.ally_rank_id
                WHERE r.DIPLOMATIC = 1 AND u.ally_id = :ally_id;";

        return $this->db->select($sql, [':ally_id' => $allianceId]);
    }

    /** number of open applications at the alliance */
    public function countApplications(int|string $allianceId): mixed
    {
        $sql = "SELECT COUNT(*) as count FROM %%ALLIANCE_REQUEST%% WHERE allianceId = :AllianceID;";

        return $this->db->selectSingle($sql, [
            ':AllianceID' => $allianceId,
        ], 'count');
    }

    /** number of open (not yet accepted) diplomacy requests; incoming = addressed to the alliance */
    public function countOpenDiplomacy(int|string $allianceId, bool $incoming): mixed
    {
        $column = $incoming ? 'owner_2' : 'owner_1';
        $sql = "SELECT COUNT(*) as count FROM %%DIPLO%% WHERE $column = :AllianceID AND accept = 0;";

        return $this->db->selectSingle($sql, [
            ':AllianceID' => $allianceId,
        ], 'count');
    }

    /** name of a rank (any alliance: rank ids are globally unique), null when it does not exist */
    public function rankName(int|string $rankId): mixed
    {
        $sql = "SELECT rankName FROM %%ALLIANCE_RANK%% WHERE rankID = :UserRankID;";

        return $this->db->selectSingle($sql, [
            ':UserRankID' => $rankId,
        ], 'rankName');
    }

    /** @return array<int|string, string> rankID => rankName of an alliance */
    public function rankNames(int|string $allianceId): array
    {
        $sql = "SELECT rankID, rankName FROM %%ALLIANCE_RANK%% WHERE allianceId = :AllianceID";
        $rankList = [];
        foreach ($this->db->select($sql, [':AllianceID' => $allianceId]) as $rankRow) {
            $rankList[$rankRow['rankID']] = $rankRow['rankName'];
        }

        return $rankList;
    }

    /**
     * The ranks of an alliance with all right columns (plus rankName when asked).
     *
     * @return list<array<string, mixed>>
     */
    public function ranksWithRights(int|string $allianceId, bool $withName = true): array
    {
        $columns = ['rankID'];
        if ($withName) {
            $columns[] = 'rankName';
        }

        return $this->db->fetchAll(
            $this->db->qb()
                ->select(...$columns)
                ->addSelect(...array_values(AllianceRights::AVAILABLE))
                ->from($this->db->table('ALLIANCE_RANK'))
                ->where('allianceId = :allianceId')
                ->setParameter('allianceId', $allianceId),
        );
    }

    /** @return list<array<string, mixed>> members with position, status columns, points and rank id */
    public function memberRows(int|string $allianceId): array
    {
        return $this->db->select(self::MEMBER_ROW_SQL, [
            ':AllianceID' => $allianceId,
        ]);
    }

    /** @return list<array{id: int|string, username: string, onlinetime: int|string}> id, name and last activity of all members, most recently active first */
    public function memberActivity(int|string $allianceId): array
    {
        return $this->db->select(
            'SELECT `id`, `username`, `onlinetime` FROM %%USERS%% WHERE `ally_id` = :AllianceID ORDER BY `onlinetime` DESC, `username` ASC;',
            [':AllianceID' => $allianceId],
        );
    }

    /** Recomputes alliance.ally_members from the users table (after somebody left or was kicked). */
    public function recountMembers(int|string $allianceId): void
    {
        $sql = "UPDATE %%ALLIANCE%% SET ally_members = (SELECT COUNT(*) FROM %%USERS%% WHERE ally_id = :AllianceID)"
            . " WHERE id = :AllianceID;";
        $this->db->update($sql, [
            ':AllianceID' => $allianceId,
        ]);
    }

    /**
     * Receivers (id, language) of an alliance message.
     *  - $managers: the target alliance's leader and the members whose rank may MANAGEAPPLY (applications);
     *  - otherwise the members of the target alliance and of the own alliance, restricted to leaders and members
     *    with the DIPLOMATIC right when $diplomats is set.
     *
     * @return list<array{id: int|string, lang: string}>
     */
    public function messageReceivers(int $targetAllyId, int|string $ownAllyId, bool $diplomats = false, bool $managers = false): array
    {
        $db = $this->db;

        $sql = "SELECT u.id, us.lang FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id";
        if ($diplomats || $managers) {
            $sql = $sql . " JOIN %%ALLIANCE%% a ON u.ally_id = a.id";
            $sql = $sql . " LEFT JOIN %%ALLIANCE_RANK%% r ON u.ally_id = r.allianceID AND u.ally_rank_id = r.rankID";
        }
        if (!$managers) {
            $sql = $sql . " WHERE (u.ally_id = :allianceId OR u.ally_id = :id)";
            if ($diplomats) {
                $sql = $sql . " AND (r.DIPLOMATIC = 1 OR u.id = a.ally_owner)";
            }
            $sql = $sql . ";";

            return $db->select($sql, [
                ':allianceId' => $ownAllyId,
                ':id' => $targetAllyId,
            ]);
        }

        $sql = $sql . " WHERE u.ally_id = :allianceId AND (r.MANAGEAPPLY = 1 OR u.id = a.ally_owner);";

        return $db->select($sql, [
            ':allianceId' => $targetAllyId,
        ]);
    }
}
