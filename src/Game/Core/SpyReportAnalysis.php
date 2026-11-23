<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Pure calculations on the data of a spy report (no database, no globals): loot at the raid rules, cargo ships needed,
 * the value of the visible fleet and defense, activity state and the "woke up" marker. Resources are arrays with the
 * keys metal, crystal and deuterium.
 *
 * The loot follows BattleHandler::fillCapacity() (1/3 of the capacity in metal, half of the rest in crystal, the rest in
 * deuterium, then the leftover refills metal and crystal) with the base share of half the stock per resource. The honor
 * tier of the target (calculate_honorpoints_decrease) is not part of the report, so it is not applied here.
 */
final class SpyReportAnalysis
{
    /** share of each resource stock a raid may take */
    public const BASE_LOOT_SHARE = 0.5;

    /**
     * The planet's resources out of the report data (resource class 900, ids 901 to 903); 0 where the report shows nothing.
     *
     * @param array<int|string, mixed> $spyData class id => element id => amount, as stored in the report
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    public static function resources(array $spyData): array
    {
        $res = $spyData[900] ?? [];

        return [
            'metal' => (float) ($res[901] ?? 0),
            'crystal' => (float) ($res[902] ?? 0),
            'deuterium' => (float) ($res[903] ?? 0),
        ];
    }

    /**
     * Loot a fleet with the given free capacity takes (floored per resource).
     *
     * @param array{metal: float, crystal: float, deuterium: float} $res stock on the planet
     * @param float $capacity free cargo capacity (INF = no limit)
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    public static function loot(array $res, float $capacity = INF, float $share = self::BASE_LOOT_SHARE, float $factor = 1.0): array
    {
        $cap = static fn(string $k): float => max(0.0, $res[$k] * $share * $factor);

        $metal = min($capacity / 3, $cap('metal'));
        $capacity -= $metal;
        $crystal = min($capacity / 2, $cap('crystal'));
        $capacity -= $crystal;
        $deuterium = min($capacity, $cap('deuterium'));
        $capacity -= $deuterium;

        $extraMetal = max(0.0, min($capacity / 2, $cap('metal') - $metal));
        $metal += $extraMetal;
        $capacity -= $extraMetal;
        $crystal += max(0.0, min($capacity, $cap('crystal') - $crystal));

        return [
            'metal' => floor($metal),
            'crystal' => floor($crystal),
            'deuterium' => floor($deuterium),
        ];
    }

    /** Sum of a loot or resource array. */
    public static function total(array $res): float
    {
        return (float) ($res['metal'] + $res['crystal'] + $res['deuterium']);
    }

    /**
     * Smallest capacity that carries the full loot (binary search over the loot rules above).
     *
     * @param array{metal: float, crystal: float, deuterium: float} $res
     */
    public static function minCapacity(array $res, float $share = self::BASE_LOOT_SHARE, float $factor = 1.0): int
    {
        $full = self::total(self::loot($res, INF, $share, $factor));
        if ($full <= 0) {
            return 0;
        }

        $low = 0;
        $high = (int) ceil($full) * 3;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if (self::total(self::loot($res, (float) $mid, $share, $factor)) >= $full) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $low;
    }

    /** Ships of one capacity needed for a capacity (rounded up, 0 for no capacity needed or a ship without room). */
    public static function shipsNeeded(int $capacity, int|float $shipCapacity): int
    {
        return $shipCapacity > 0 && $capacity > 0 ? (int) ceil($capacity / $shipCapacity) : 0;
    }

    /**
     * Value of the visible ships (class 200) and defense (class 400) in resource units (metal + crystal + deuterium).
     *
     * @param array<int|string, mixed> $spyData
     * @param array<int, array{0: int|float, 1: int|float, 2: int|float}> $costs element id => [metal, crystal, deuterium] per unit
     * @return array{fleet: float, defense: float}
     */
    public static function unitValues(array $spyData, array $costs): array
    {
        $value = ['fleet' => 0.0, 'defense' => 0.0];
        foreach (['fleet' => 200, 'defense' => 400] as $key => $class) {
            foreach ($spyData[$class] ?? [] as $id => $amount) {
                $cost = $costs[(int) $id] ?? [0, 0, 0];
                $value[$key] += (float) $amount * ($cost[0] + $cost[1] + $cost[2]);
            }
        }

        return $value;
    }

    /**
     * Activity of a player: 'I' after the long inactivity limit, 'i' after the short one, '' when active.
     */
    public static function inactiveState(int $onlineTime, int $now, int $inactive, int $inactiveLong): string
    {
        if ($onlineTime < $now - $inactiveLong) {
            return 'I';
        }

        return $onlineTime < $now - $inactive ? 'i' : '';
    }

    /**
     * True when the owner was inactive when the report was made (flag stored in the report) and has been online since and is
     * active now. Reports without the flag (null) never count.
     */
    public static function wokeUp(?bool $inactiveAtReport, int $reportTime, int $onlineTime, int $now, int $inactive): bool
    {
        return $inactiveAtReport === true && $onlineTime > $reportTime && $onlineTime >= $now - $inactive;
    }
}
