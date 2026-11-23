<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Pure value maths for a combat report: what each side and player lost in resources, and what the attacker made
 * (loot + debris - losses). No game state; unit prices are passed in.
 */
final class CombatReportValue
{
    private const RES_METAL = 901;
    private const RES_CRYSTAL = 902;
    private const RES_DEUT = 903;

    /**
     * @param array<int, array{0: int|float, 1: int|float, 2: int|float}> $prices unit id => [metal, crystal, deuterium]
     */
    public function __construct(private readonly array $prices) {}

    /**
     * Fleets of both sides from a rust report: start (round 0) and final (last round) unit counts per fleet.
     *
     * @param array<string, mixed> $report decoded report
     * @return array{attacker: list<array{name: string, start: array<int, int>, end: array<int, int>}>, defender: list<array{name: string, start: array<int, int>, end: array<int, int>}>}
     */
    public static function fleetsFromRust(array $report): array
    {
        $rounds = $report['rounds'] ?? [];
        $first = $rounds[0] ?? [];
        $last = $rounds ? $rounds[array_key_last($rounds)] : [];
        $result = ['attacker' => [], 'defender' => []];
        foreach (array_keys($result) as $side) {
            foreach ($first[$side . 's'] ?? [] as $fleetId => $fleet) {
                $result[$side][] = [
                    'name' => (string) ($fleet['player']['username'] ?? ''),
                    'start' => self::amounts($first[$side]['Roundstats'][$fleetId] ?? [], 'amount'),
                    'end' => self::amounts($last[$side]['Roundstats'][$fleetId] ?? [], 'amount'),
                ];
            }
        }

        return $result;
    }

    /**
     * Fleets of both sides from a classic (PHP engine) report.
     *
     * @param array<string, mixed> $report decoded report
     * @return array{attacker: list<array{name: string, start: array<int, int>, end: array<int, int>}>, defender: list<array{name: string, start: array<int, int>, end: array<int, int>}>}
     */
    public static function fleetsFromClassic(array $report): array
    {
        $rounds = $report['rounds'] ?? [];
        $first = $rounds[0] ?? [];
        $last = $rounds ? $rounds[array_key_last($rounds)] : [];
        $result = ['attacker' => [], 'defender' => []];
        foreach (array_keys($result) as $side) {
            foreach ($first[$side] ?? [] as $nr => $fleet) {
                $result[$side][] = [
                    'name' => (string) ($report['players'][$fleet['userID'] ?? 0]['name'] ?? ''),
                    'start' => self::amounts($fleet['ships'] ?? [], 0),
                    'end' => self::amounts($last[$side][$nr]['ships'] ?? [], 0),
                ];
            }
        }

        return $result;
    }

    /**
     * Value of the units lost between two unit count maps.
     *
     * @param array<int, int|float> $start unit id => count before
     * @param array<int, int|float> $end unit id => count after
     * @return array{units: int, metal: float, crystal: float, deuterium: float, total: float}
     */
    public function loss(array $start, array $end): array
    {
        $loss = ['units' => 0, 'metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0, 'total' => 0.0];
        foreach ($start as $id => $count) {
            $lost = max(0, (int) $count - (int) ($end[$id] ?? 0));
            if ($lost === 0) {
                continue;
            }
            $price = $this->prices[$id] ?? [0, 0, 0];
            $loss['units'] += $lost;
            $loss['metal'] += $lost * $price[0];
            $loss['crystal'] += $lost * $price[1];
            $loss['deuterium'] += $lost * $price[2];
        }
        $loss['total'] = $loss['metal'] + $loss['crystal'] + $loss['deuterium'];

        return $loss;
    }

    /**
     * Losses per player (fleets of one name are merged) and in total for each side.
     *
     * @param array<string, list<array{name: string, start: array<int, int|float>, end: array<int, int|float>}>> $fleets side => fleets
     * @return array<string, array{players: array<string, array<string, int|float>>, total: array<string, int|float>}>
     */
    public function sideLosses(array $fleets): array
    {
        $out = [];
        foreach ($fleets as $side => $list) {
            $players = [];
            $zero = ['units' => 0, 'metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0, 'total' => 0.0];
            $total = $zero;
            foreach ($list as $fleet) {
                $loss = $this->loss($fleet['start'], $fleet['end']);
                $players[$fleet['name']] ??= $zero;
                foreach ($loss as $key => $value) {
                    $players[$fleet['name']][$key] += $value;
                    $total[$key] += $value;
                }
            }
            $out[$side] = ['players' => $players, 'total' => $total];
        }

        return $out;
    }

    /**
     * Resource value of a metal/crystal/deuterium map keyed by resource id (loot, debris).
     *
     * @param array<int|string, int|float> $resources
     */
    public static function resourceValue(array $resources): float
    {
        $sum = 0.0;
        foreach ([self::RES_METAL, self::RES_CRYSTAL, self::RES_DEUT] as $id) {
            $sum += (float) ($resources[$id] ?? 0);
        }

        return $sum;
    }

    /**
     * Attacker profit: loot plus the whole debris field (assuming it is recycled) minus own losses minus fuel (when known).
     *
     * @param array<int|string, int|float> $loot
     * @param array<int|string, int|float> $debris
     */
    public static function attackerProfit(array $loot, array $debris, float $ownLoss, float $fuel = 0.0): float
    {
        return self::resourceValue($loot) + self::resourceValue($debris) - $ownLoss - $fuel;
    }

    /**
     * Raises the ship counts of the attacker side (side 0) of a simulator prefill by a percentage.
     *
     * @param array<int|string, mixed> $battleinput slot => [side => [unit id => count]]
     * @param list<int> $shipIds ids that count as ships (techs and defences stay untouched)
     * @return array<int|string, mixed>
     */
    public static function boostAttackers(array $battleinput, array $shipIds, int $percent): array
    {
        if ($percent <= 0) {
            return $battleinput;
        }
        foreach ($battleinput as $slot => $sides) {
            foreach ($shipIds as $id) {
                if (isset($sides[0][$id]) && is_numeric($sides[0][$id])) {
                    $battleinput[$slot][0][$id] = (int) ceil((float) $sides[0][$id] * (100 + $percent) / 100);
                }
            }
        }

        return $battleinput;
    }

    /**
     * @param array<int|string, mixed> $rows unit id => stats array/list
     * @return array<int, int>
     */
    private static function amounts(array $rows, int|string $key): array
    {
        $out = [];
        foreach ($rows as $id => $data) {
            $out[(int) $id] = (int) ($data[$key] ?? 0);
        }

        return $out;
    }
}
