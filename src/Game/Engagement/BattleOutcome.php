<?php

declare(strict_types=1);

namespace App\Game\Engagement;

use App\Game\BattleEngine\BattleLosses;

/**
 * What the engagement features (contracts, seasons, bounties, wars, battle feed, ...) learn about a finished real fight:
 * who fought on which side, what each side lost, the loot and the new debris. Built by the battle missions after the
 * report was stored. Immutable.
 */
final class BattleOutcome
{
    /**
     * @param array<int, string> $attackers attacking user id => name
     * @param array<int, string> $defenders defending user id => name (empty for a pirate base)
     * @param array<int, int|float> $loot resource id => stolen amount (attackers' gain)
     * @param array<int, int|float> $debris resource id => new debris
     * @param string $won 'a' (attacker), 'r' (defender) or 'w' (draw), as BattleResult::$won
     * @param string $kind 'attack', 'destruction' or 'pirate'
     * @param array<int, int|float> $startValuePerUser user id => metal+crystal+deuterium value of the units the user brought into the fight (empty = unknown)
     */
    public function __construct(
        public readonly int $universe,
        public readonly string $reportId,
        public readonly int $time,
        public readonly array $attackers,
        public readonly array $defenders,
        public readonly BattleLosses $losses,
        public readonly string $won,
        public readonly array $loot,
        public readonly array $debris,
        public readonly int $galaxy,
        public readonly int $system,
        public readonly int $planet,
        public readonly int $planetType,
        public readonly int $targetPlanetId,
        public readonly string $kind = 'attack',
        public readonly array $startValuePerUser = [],
    ) {}

    /** Metal+crystal+deuterium the attackers carried off. */
    public function lootTotal(): float
    {
        return (float) array_sum($this->loot);
    }

    /** Both sides' lost value (the "units" of the top-KB list). */
    public function totalLost(): float
    {
        return (float) $this->losses->totalValue();
    }

    /** True when $userId fought on the attacking side. */
    public function isAttacker(int $userId): bool
    {
        return isset($this->attackers[$userId]);
    }

    /** True when $userId fought on the defending side. */
    public function isDefender(int $userId): bool
    {
        return isset($this->defenders[$userId]);
    }

    /**
     * Whether the user took a real part in the fight: his units at the start were worth at least $minShare of his side's
     * total, or he is the only member of the side. True when the start values are unknown (older callers).
     */
    public function holdsShare(int $userId, float $minShare = 0.1): bool
    {
        if ($this->startValuePerUser === []) {
            return true;
        }
        $side = $this->isAttacker($userId) ? $this->attackers : ($this->isDefender($userId) ? $this->defenders : null);
        if ($side === null || count($side) <= 1) {
            return $side !== null;
        }
        $total = 0.0;
        foreach (array_keys($side) as $id) {
            $total += (float) ($this->startValuePerUser[$id] ?? 0);
        }

        return $total <= 0 || (float) ($this->startValuePerUser[$userId] ?? 0) >= $minShare * $total;
    }
}
