<?php

declare(strict_types=1);

namespace App\Game\Engagement\Contracts;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\EngagementFeature;
use App\Game\Engagement\FightValidity;
use App\Game\Engagement\Week;

/**
 * Weekly contracts: every player gets a few rotating goals per ISO week (lazily generated when the player opens the page or
 * an event of the player arrives, never for all players at once). Progress comes from advanced-stat counters (onStat) and
 * fights (onBattle, with the anti-abuse rules of FightValidity). Claiming and rerolling are in ContractBoard.
 *
 * The hooks run inside the fleet event transaction, so they only issue plain statements (no nested transaction).
 */
final class Contracts extends EngagementFeature
{
    /** @var array<int, array{0: int, 1: bool}> user id => [week key, whether the player has contracts] (also remembers refusals) */
    private array $ready = [];

    public function enabled(int $universe): bool
    {
        return (bool) $this->setting($universe, 'contracts_enabled', false);
    }

    public function onStat(int $universe, int $userId, AdvancedStatsEnum $key, int $value): void
    {
        $kind = ContractKinds::forStat($key);
        if ($kind !== null) {
            $this->advance($universe, $userId, $kind, $value);
        }
    }

    /** A qualifying expedition (fleet value of at least a quarter of the reference, see EngagementHooks) counts for the expedition contract. */
    public function onExpedition(int $universe, int $userId, bool $qualifies, int $time): void
    {
        if ($qualifies) {
            $this->advance($universe, $userId, ContractKinds::EXPEDITIONS, 1);
        }
    }

    /**
     * Credits every valid participant of a fight (see FightValidity) with destroyed enemy value and loot (shared equally by the
     * users of the side) and, for the winning side, one fight won. Only players with an open fight contract are judged at all.
     */
    public function onBattle(BattleOutcome $outcome): void
    {
        if ($outcome->kind === 'pirate') {
            return;
        }
        $validity = new FightValidity($this->db, $this->configs, $this->state);
        $sides = [
            'a' => [$outcome->attackers, $outcome->losses->defenderValue, $outcome->lootTotal()],
            'r' => [$outcome->defenders, $outcome->losses->attackerValue, 0.0],
        ];
        foreach ($sides as $winCode => [$users, $destroyed, $loot]) {
            $share = max(1, count($users));
            foreach (array_keys($users) as $userId) {
                $open = $this->openBattleKinds($outcome->universe, (int) $userId);
                if ($open === [] || !$validity->counts($outcome, (int) $userId)) {
                    continue;
                }
                $gains = [
                    ContractKinds::DESTROYED => (int) floor($destroyed / $share),
                    ContractKinds::RAIDED => (int) floor($loot / $share),
                    ContractKinds::WINS => $outcome->won === $winCode ? 1 : 0,
                ];
                foreach ($open as $kind) {
                    $this->advance($outcome->universe, (int) $userId, $kind, $gains[$kind] ?? 0);
                }
            }
        }
    }

    /**
     * Fight kinds of the player's open contracts this week (generates the contracts first); empty for ineligible players.
     *
     * @return list<string>
     */
    private function openBattleKinds(int $universe, int $userId): array
    {
        $now = $this->state->runtime->timestamp;
        if (!$this->ensureWeek($universe, $userId, $now)) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT `kind` FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week AND `completed_at` IS NULL;',
            [':user' => $userId, ':week' => Week::key($now)],
        );

        return array_values(array_filter(array_column($rows, 'kind'), ContractKinds::isBattle(...)));
    }

    /**
     * Adds progress to the player's open contract of a kind (capped at the target; the completion time is set when the target
     * is reached) in the current week (the one the claim page lists). Players without contracts this week get them first.
     */
    private function advance(int $universe, int $userId, string $kind, int $amount): void
    {
        $now = $this->state->runtime->timestamp;
        if ($amount <= 0 || !$this->ensureWeek($universe, $userId, $now)) {
            return;
        }
        $this->db->update(
            'UPDATE %%ENG_CONTRACTS%% SET `progress` = LEAST(`target`, `progress` + :amount),
                `completed_at` = IF(`progress` >= `target`, :now, `completed_at`)
            WHERE `user_id` = :user AND `week` = :week AND `kind` = :kind AND `completed_at` IS NULL;',
            [':amount' => $amount, ':now' => $now, ':user' => $userId, ':week' => Week::key($now), ':kind' => $kind],
        );
    }

    /**
     * Makes sure the player has this week's contracts: true when they exist (or were just generated), false for bots,
     * players in vacation mode, banned players and unknown users.
     */
    public function ensureWeek(int $universe, int $userId, int $now): bool
    {
        $week = Week::key($now);
        if (isset($this->ready[$userId]) && $this->ready[$userId][0] === $week) {
            return $this->ready[$userId][1];
        }
        $count = max(1, min(5, (int) $this->setting($universe, 'contracts_count', 3)));
        $have = (int) $this->db->selectSingle('SELECT COUNT(*) AS anz FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week;', [':user' => $userId, ':week' => $week], 'anz');
        $ok = true;
        if ($have < $count) {
            $player = $this->db->selectSingle(
                'SELECT u.`urlaubs_modus`, u.`bana`, b.`user_id` AS bot, COALESCE(s.`total_points`, 0) AS points
                FROM %%USERS%% u
                LEFT JOIN %%BOT_PLAYERS%% b ON b.`user_id` = u.`id`
                LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1
                WHERE u.`id` = :user;',
                [':user' => $userId],
            );
            if (empty($player) || !empty($player['bot']) || (int) $player['urlaubs_modus'] === 1 || (int) $player['bana'] === 1) {
                // a player who got contracts before keeps working with them; nobody new is created for ineligible players
                $ok = $have > 0;
            } else {
                $this->generate($universe, $userId, $week, (float) $player['points'], $now, $count);
            }
        }
        $this->ready[$userId] = [$week, $ok];

        return $ok;
    }

    /**
     * Inserts the missing contracts of the week with one INSERT IGNORE statement (atomic): the kinds come from a shuffle seeded
     * with player and week, so concurrent calls compute the same rows and a week never holds more than `contracts_count`
     * of them. Missing slots of a partly filled week (e.g. after the count was raised) are repaired; a kind that a reroll
     * already put into another slot is replaced by the next unused one.
     */
    private function generate(int $universe, int $userId, int $week, float $points, int $now, int $count): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32($userId . ':' . $week)));
        $battle = array_values(array_filter(ContractKinds::all(), ContractKinds::isBattle(...)));
        $stat = array_values(array_diff(ContractKinds::all(), $battle));
        $battleCount = $count >= 4 ? 2 : ($count >= 2 ? 1 : $random->getInt(0, 1));
        $kinds = $random->shuffleArray(array_merge(
            array_slice($random->shuffleArray($battle), 0, $battleCount),
            array_slice($random->shuffleArray($stat), 0, $count - $battleCount),
        ));
        $spare = $random->shuffleArray(ContractKinds::all());
        $existing = $this->db->select('SELECT `slot`, `kind` FROM %%ENG_CONTRACTS%% WHERE `user_id` = :user AND `week` = :week;', [':user' => $userId, ':week' => $week]);
        $slots = array_column($existing, 'kind', 'slot');
        $taken = array_values($slots);
        $values = [];
        $params = [':user' => $userId, ':universe' => $universe, ':week' => $week, ':now' => $now];
        foreach ($kinds as $slot => $kind) {
            if (isset($slots[$slot])) {
                continue;
            }
            if (in_array($kind, $taken, true)) {
                $kind = current(array_diff($spare, $taken, $kinds)) ?: current(array_diff($spare, $taken));
            }
            if ($kind === false) {
                continue;
            }
            $taken[] = $kind;
            $values[] = '(:user, :universe, :week, ' . (int) $slot . ', :k' . $slot . ', :t' . $slot . ', :now)';
            $params[':k' . $slot] = $kind;
            $params[':t' . $slot] = ContractKinds::target($kind, $points);
        }
        if ($values !== []) {
            $this->db->insert('INSERT IGNORE INTO %%ENG_CONTRACTS%% (`user_id`, `universe`, `week`, `slot`, `kind`, `target`, `created_at`) VALUES ' . implode(', ', $values) . ';', $params);
        }
    }
}
