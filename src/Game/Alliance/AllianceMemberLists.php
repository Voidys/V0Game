<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Exception;

/**
 * Builds the member lists shown by the alliance pages (member list incl. the lists of main/wing alliances, and the
 * member administration): one row per member with status class, position, points, rank name and idle minutes.
 *
 * Side effect: the acting user's own total points are merged into the shared `state->USER` array (key
 * `total_points`), as the noob/strong check of the status class needs them.
 */
class AllianceMemberLists
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
    ) {}

    /**
     * Member list page: the members of the alliance with their rank names.
     *
     * @param array<string, mixed> $alliance
     * @return array<int|string, array<string, mixed>> user id => row (class, username, galaxy, system, planet,
     *         register_time, points, rankName, onlinetime)
     */
    public function members(array $alliance): array
    {
        $this->loadOwnPoints();

        return $this->rowsOf($alliance);
    }

    /**
     * Activity summary for the alliance home page, one query: the number of members online (idle under 4 minutes, the
     * "online" tag of the member list) and the $limit most recently active members with their idle minutes.
     *
     * @param array<string, mixed> $alliance
     * @return array{online: int, top: list<array{id: int|string, username: string, onlinetime: int}>}
     */
    public function activitySummary(array $alliance, int $limit = 8): array
    {
        $online = 0;
        $top = [];
        foreach ($this->repository->memberActivity($alliance['id']) as $row) {
            $minutes = (int) $this->idleMinutes($row);
            if ($minutes < 4) {
                $online++;
            }
            if (count($top) < $limit) {
                $top[] = ['id' => $row['id'], 'username' => $row['username'], 'onlinetime' => $minutes];
            }
        }

        return ['online' => $online, 'top' => $top];
    }

    /**
     * The lists of the alliances this alliance is "wing" of (we are the wing: their members form our "main" lists).
     *
     * @param array<string, mixed> $alliance
     * @return array<int|string, array{header: string, mainData: array<string, mixed>, memberList: array}>
     */
    public function mainAlliances(array $alliance): array
    {
        $sql = "SELECT a.id, a.ally_name, a.ally_tag, ally_owner, ally_owner_range FROM %%DIPLO%% d
            JOIN %%ALLIANCE%% a ON a.id = d.owner_1
            WHERE d.owner_2 = :AllianceID AND d.level = 1 AND accept = 1;";

        return $this->linkedAlliances($sql, $alliance, 'al_users_list_main', 'mainData');
    }

    /**
     * The lists of the alliances that are "wing" of this alliance.
     *
     * @param array<string, mixed> $alliance
     * @return array<int|string, array{header: string, wingData: array<string, mixed>, memberList: array}>
     */
    public function wingAlliances(array $alliance): array
    {
        $sql = "SELECT a.id, a.ally_name, a.ally_tag, ally_owner, ally_owner_range FROM %%DIPLO%% d
            JOIN %%ALLIANCE%% a ON a.id = d.owner_2
            WHERE d.owner_1 = :AllianceID AND d.level = 1 AND accept = 1;";

        return $this->linkedAlliances($sql, $alliance, 'al_users_list_wing', 'wingData');
    }

    /**
     * Member administration list: like members(), but with the rank id (-1 for the leader) instead of the rank name
     * and the translated kick confirmation question.
     *
     * @param array<string, mixed> $alliance
     * @return array<int|string, array<string, mixed>>
     */
    public function adminMembers(array $alliance): array
    {
        $LNG = $this->state->LNG;
        $this->loadOwnPoints();
        $user = $this->state->USER;

        $memberList = [];
        foreach ($this->repository->memberRows($alliance['id']) as $memberRow) {
            $class = $this->statusClass($user, $memberRow);

            if ($alliance['ally_owner'] == $memberRow['id']) {
                $memberRow['ally_rank_id'] = -1;
            }

            $memberList[$memberRow['id']] = [
                'class' => $class,
                'username' => $memberRow['username'],
                'galaxy' => $memberRow['galaxy'],
                'system' => $memberRow['system'],
                'planet' => $memberRow['planet'],
                'register_time' => $memberRow['ally_register_time'],
                'points' => $memberRow['total_points'],
                'rankID' => $memberRow['ally_rank_id'],
                'onlinetime' => $this->idleMinutes($memberRow),
                'kickQuestion' => $LNG->format('al_kick_player', $memberRow['username']),
            ];
        }

        return $memberList;
    }

    /**
     * Display name of the acting user's rank: the leader's custom title (or the founder text), the name of his rank,
     * or the "new member" text.
     *
     * @param array<string, mixed> $alliance his alliance
     */
    public function ownRankName(array $alliance): string
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        if ($alliance['ally_owner'] == $USER['id']) {
            $rankName = ($alliance['ally_owner_range'] != '')
                ? $alliance['ally_owner_range'] : $LNG['al_founder_rank_text'];
        } elseif ($USER['ally_rank_id'] != 0) {
            $rankName = $this->repository->rankName($USER['ally_rank_id']);
        }

        return empty($rankName) ? $LNG['al_new_member_rank_text'] : $rankName;
    }

    /** @return array<string, string> status short names for the legend of the member lists */
    public function shortStatus(): array
    {
        $LNG = $this->state->LNG;

        return [
            'vacation' => $LNG['gl_short_vacation'],
            'banned' => $LNG['gl_short_ban'],
            'inactive' => $LNG['gl_short_inactive'],
            'longinactive' => $LNG['gl_short_long_inactive'],
            'noob' => $LNG['gl_short_newbie'],
            'strong' => $LNG['gl_short_strong'],
            'enemy' => $LNG['gl_short_enemy'],
            'friend' => $LNG['gl_short_friend'],
            'member' => $LNG['gl_short_member'],
        ];
    }

    /**
     * Shared body of mainAlliances()/wingAlliances(): runs the query for the linked alliances (rows id, ally_name,
     * ally_tag, ally_owner, ally_owner_range) and builds the member list of each.
     *
     * @param array<string, mixed> $alliance
     * @return array<mixed>
     */
    private function linkedAlliances(string $sql, array $alliance, string $headerKey, string $dataKey): array
    {
        $LNG = $this->state->LNG;
        $linkedRows = $this->db->select($sql, [
            ':AllianceID' => $alliance['id'],
        ]);

        $lists = [];
        if ($linkedRows) {
            $this->loadOwnPoints();
            foreach ($linkedRows as $linked) {
                $memberList = $this->rowsOf($linked);
                $lists[$linked['id']]['header'] = $LNG->format($headerKey, count($memberList), '[' . $linked['ally_tag'] . '] ' . $linked['ally_name']);
                $lists[$linked['id']][$dataKey] = $linked;
                $lists[$linked['id']]['memberList'] = $memberList;
            }
        }

        return $lists;
    }

    /**
     * Member rows of one alliance for the display lists.
     *
     * @param array<string, mixed> $alliance needs id, ally_owner, ally_owner_range
     * @return array<mixed>
     */
    private function rowsOf(array $alliance): array
    {
        $LNG = $this->state->LNG;
        $user = $this->state->USER;
        $rankList = $this->repository->rankNames($alliance['id']);

        $memberList = [];
        foreach ($this->repository->memberRows($alliance['id']) as $memberRow) {
            $class = $this->statusClass($user, $memberRow);

            if ($alliance['ally_owner'] == $memberRow['id']) {
                $rankName = empty($alliance['ally_owner_range'])
                    ? $LNG['al_founder_rank_text'] : $alliance['ally_owner_range'];
            } elseif ($memberRow['ally_rank_id'] != 0 && isset($rankList[$memberRow['ally_rank_id']])) {
                $rankName = $rankList[$memberRow['ally_rank_id']];
            } else {
                $rankName = $LNG['al_new_member_rank_text'];
            }

            $memberList[$memberRow['id']] = [
                'class' => $class,
                'username' => $memberRow['username'],
                'galaxy' => $memberRow['galaxy'],
                'system' => $memberRow['system'],
                'planet' => $memberRow['planet'],
                'register_time' => $memberRow['ally_register_time'],
                'points' => $memberRow['total_points'],
                'rankName' => $rankName,
                'onlinetime' => $this->idleMinutes($memberRow),
            ];
        }

        return $memberList;
    }

    /**
     * @param array<mixed> $memberRow
     * @param array<string, mixed> $user
     */
    private function statusClass(array $user, array $memberRow): mixed
    {
        $isNoobProtected = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($user), $this->models()->user($memberRow));

        return $this->protectionRules()->userStatus($memberRow, $isNoobProtected);
    }

    /**
     * @param array<mixed> $memberRow
     */
    private function idleMinutes(array $memberRow): float|int
    {
        return floor(($this->state->runtime->timestamp - $memberRow['onlinetime']) / 60);
    }

    /** Merges the acting user's total points into state->USER (0 without statistics row). */
    private function loadOwnPoints(): void
    {
        try {
            $this->state->USER += $this->db->selectSingle(
                'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType',
                [
                    ':userId' => $this->state->USER['id'],
                    ':statType' => 1,
                ],
            ) ?: ['total_points' => 0];
        } catch (Exception $e) {
            $this->state->USER['total_points'] = 0;
        }
    }
}
