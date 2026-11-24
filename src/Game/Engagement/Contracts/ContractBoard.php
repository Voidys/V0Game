<?php

declare(strict_types=1);

namespace App\Game\Engagement\Contracts;

use App\Game\Engagement\Cosmetics\Cosmetics;
use App\Game\Engagement\Week;

/**
 * The player side of the weekly contracts: the list for the page, claiming a finished contract (reward, streak badges) and the
 * weekly reroll. Used by the contracts page and the overview line, never by the fight / stat hooks (see Contracts).
 */
final class ContractBoard
{
    /** Consecutive fully claimed weeks that earn a badge: weeks => badge code. */
    private const STREAK_BADGES = [4 => 'contract_streak_4', 12 => 'contract_streak_12'];

    private const RESOURCE_COLUMNS = ['metal', 'crystal', 'deuterium'];

    public function __construct(
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {}

    /**
     * The contracts of the player this week (slot order), with the reroll state of the week.
     *
     * @return array{contracts: list<array<string, mixed>>, done: int, rerollsLeft: int, endsAt: int}
     */
    public function summary(int $userId, int $universe, int $now): array
    {
        $rows = $this->db->select(
            'SELECT * FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week ORDER BY `slot`;',
            [':user' => $userId, ':week' => Week::key($now)],
        );
        $done = 0;
        $rerolled = 0;
        $contracts = [];
        foreach ($rows as $row) {
            $done += $row['completed_at'] !== null ? 1 : 0;
            $rerolled += (int) $row['rerolled'];
            $contracts[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'target' => (int) $row['target'],
                'progress' => (int) $row['progress'],
                'percent' => $row['target'] > 0 ? (int) floor(100 * $row['progress'] / $row['target']) : 0,
                'completed' => $row['completed_at'] !== null,
                'claimed' => $row['claimed_at'] !== null,
            ];
        }
        $rerolls = (int) ($this->configs->get($universe)->contracts_rerolls ?? 1);

        return [
            'contracts' => $contracts,
            'done' => $done,
            'rerollsLeft' => max(0, min(3, $rerolls) - $rerolled),
            'endsAt' => Week::end($now),
        ];
    }

    /**
     * Claims a finished contract exactly once: the claimed flag is set by a conditional update (row count 1 wins), and the
     * resource reward (mode "resources") is credited to the planet in the same transaction.
     *
     * @return array{ok: bool, resources: array<string, int>, badges: list<string>} ok is false when nothing was claimable
     */
    public function claim(int $userId, int $universe, int $contractId, int $planetId, int $now): array
    {
        $failed = ['ok' => false, 'resources' => [], 'badges' => []];
        try {
            return $this->db->transactional(function () use ($userId, $universe, $contractId, $planetId, $now, $failed): array {
                $player = $this->db->selectSingle('SELECT `urlaubs_modus`, `bana` FROM %%USERS%% WHERE `id` = :user FOR UPDATE;', [':user' => $userId]);
                if (empty($player) || (int) $player['urlaubs_modus'] === 1 || (int) $player['bana'] === 1) {
                    return $failed;
                }
                $this->db->update(
                    'UPDATE %%ENG_CONTRACTS%% SET `claimed_at` = :now
                    WHERE `id` = :id AND `user_id` = :user AND `week` = :week AND `claimed_at` IS NULL AND `completed_at` IS NOT NULL;',
                    [':now' => $now, ':id' => $contractId, ':user' => $userId, ':week' => Week::key($now)],
                );
                if ($this->db->rowCount() !== 1) {
                    return $failed;
                }
                $resources = $this->creditReward($userId, $universe, $planetId);

                return ['ok' => true, 'resources' => $resources, 'badges' => $this->grantStreakBadges($userId, $now)];
            });
        } catch (NothingToPay) {
            // the transaction was rolled back: the contract stays unclaimed
            return $failed;
        }
    }

    /**
     * Resources mode: hours of the production of the player's most productive planet, capped in total, credited to the given
     * planet (locked, must belong to the player); mode "cosmetic" and a cap of 0 pay nothing. Added relative to the stored values.
     *
     * @return array<string, int> column => amount credited
     * @throws NothingToPay when resources are due but the amount is empty (no producing planet / planet not usable): the claim is rolled back
     */
    private function creditReward(int $userId, int $universe, int $planetId): array
    {
        $config = $this->configs->get($universe);
        $cap = (int) ($config->contracts_resource_cap ?? 0);
        if ((int) ($config->contracts_reward_mode ?? 0) !== 1 || $cap <= 0) {
            return [];
        }
        $target = $this->db->selectSingle('SELECT `id` FROM %%PLANETS%% WHERE `id` = :id AND `id_owner` = :user AND `destruyed` = 0 FOR UPDATE;', [':id' => $planetId, ':user' => $userId]);
        // the best producer, not the planet in view: a moon or a fresh colony must not burn the reward
        $best = $this->db->selectSingle(
            'SELECT `metal_perhour`, `crystal_perhour`, `deuterium_perhour` FROM %%PLANETS%%
            WHERE `id_owner` = :user AND `destruyed` = 0 AND `planet_type` = 1
            ORDER BY (`metal_perhour` + `crystal_perhour` + `deuterium_perhour`) DESC LIMIT 1;',
            [':user' => $userId],
        );
        if (empty($target) || empty($best)) {
            throw new NothingToPay();
        }
        $hours = max(1, min(24, (int) ($config->contracts_reward_hours ?? 2)));
        $amounts = [];
        foreach (self::RESOURCE_COLUMNS as $column) {
            $amounts[$column] = max(0, (int) floor((float) $best[$column . '_perhour'] * $hours));
        }
        $sum = array_sum($amounts);
        if ($sum > $cap) {
            $amounts = array_map(static fn(int $value): int => (int) floor($value * $cap / $sum), $amounts);
        }
        $amounts = array_filter($amounts, static fn(int $value): bool => $value > 0);
        if ($amounts === []) {
            throw new NothingToPay();
        }
        $this->db->updateRows('PLANETS', [], ['id' => $planetId], [], $amounts);

        return $amounts;
    }

    /**
     * After a claim: when every contract of this week is claimed, counts the consecutive fully claimed weeks up to this one
     * and grants the streak badges whose length was reached.
     *
     * @return list<string> codes of the newly granted badges
     */
    private function grantStreakBadges(int $userId, int $now): array
    {
        $open = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week AND `claimed_at` IS NULL;',
            [':user' => $userId, ':week' => Week::key($now)],
            'anz',
        );
        if ($open > 0) {
            return [];
        }
        $streak = 0;
        $weekStart = Week::start($now);
        $complete = $this->completeWeeks($userId);
        while (isset($complete[Week::key($weekStart)]) && $streak < 12) {
            ++$streak;
            $weekStart = Week::start($weekStart - 86400);
        }
        $granted = [];
        $cosmetics = new Cosmetics($this->db);
        foreach (self::STREAK_BADGES as $weeks => $code) {
            if ($streak >= $weeks && $cosmetics->grant($userId, Cosmetics::KIND_BADGE, $code, 'contracts')) {
                $granted[] = $code;
            }
        }

        return $granted;
    }

    /**
     * Weeks (newest first, at most 13) in which every contract of the player is claimed.
     *
     * @return array<int, true> week key => true
     */
    private function completeWeeks(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT `week` FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user GROUP BY `week`
            HAVING SUM(`claimed_at` IS NULL) = 0 ORDER BY `week` DESC LIMIT 13;',
            [':user' => $userId],
        );

        return array_fill_keys(array_map('intval', array_column($rows, 'week')), true);
    }

    /**
     * Replaces one unfinished contract of this week by another kind (progress restarts); one reroll per `contracts_rerolls`
     * allowance per week, counted by the `rerolled` counter, checked under the row lock of the contracts.
     *
     * @return bool false when the contract is not rerollable (finished, not the player's, no reroll left, no free kind)
     */
    public function reroll(int $userId, int $universe, int $contractId, int $now): bool
    {
        return $this->db->transactional(function () use ($userId, $universe, $contractId, $now): bool {
            $week = Week::key($now);
            $rows = $this->db->select('SELECT * FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week FOR UPDATE;', [':user' => $userId, ':week' => $week]);
            $allowed = min(3, (int) ($this->configs->get($universe)->contracts_rerolls ?? 1));
            $used = array_sum(array_column($rows, 'rerolled'));
            $chosen = null;
            foreach ($rows as $row) {
                if ((int) $row['id'] === $contractId && $row['completed_at'] === null) {
                    $chosen = $row;
                }
            }
            $free = array_values(array_diff(ContractKinds::all(), array_column($rows, 'kind')));
            if ($chosen === null || $used >= $allowed || $free === []) {
                return false;
            }
            $kind = $free[random_int(0, count($free) - 1)];
            $points = (float) $this->db->selectSingle('SELECT `total_points` FROM %%STATPOINTS%% WHERE `id_owner` = :user AND `stat_type` = 1;', [':user' => $userId], 'total_points');
            $this->db->update(
                'UPDATE %%ENG_CONTRACTS%% SET `kind` = :kind, `target` = :target, `progress` = 0, `rerolled` = `rerolled` + 1
                WHERE `id` = :id AND `user_id` = :user AND `completed_at` IS NULL;',
                [':kind' => $kind, ':target' => ContractKinds::target($kind, $points), ':id' => $contractId, ':user' => $userId],
            );

            return $this->db->rowCount() === 1;
        });
    }
}
