<?php

namespace App\Game\Core;

/**
 * Plans the transport that collects resources from one planet: which cargo ships (large first, small for the rest),
 * how much of each resource fits and the fuel. Pure arithmetic, no database; the caller supplies stock, ships, cargo
 * capacities and a fuel function, and sends the result through the normal fleet dispatch (all game rules apply there).
 */
final class ResourceCollector
{
    public const SMALL_CARGO = 202;
    public const LARGE_CARGO = 203;

    /**
     * Plans one source. The deuterium cargo leaves $keepDeuterium on the planet after the fuel is paid; the cargo has to
     * fit into the ships' capacity minus the fuel (the dispatch checks exactly that).
     *
     * @param array<int, int|float> $stock resources on the source [901 => metal, 902 => crystal, 903 => deuterium]
     * @param array<int, int|float> $ships cargo ships on the source [203 => large, 202 => small]
     * @param array<int, int|float> $capacity cargo capacity per ship [203 => .., 202 => ..]
     * @param array<int, bool> $include which resources to collect [901 => bool, ...]
     * @param callable(array<int, int>): float $fuelFor deuterium needed to fly the given ships
     * @return array{ships: array<int, int>, cargo: array<int, int>, capacity: int, fuel: float}|null null when there is nothing to send
     */
    public static function plan(array $stock, array $ships, array $capacity, array $include, int $keepDeuterium, callable $fuelFor): ?array
    {
        $fuel = 0.0;
        $picked = [];
        for ($round = 0; $round < 5; $round++) {
            $wanted = self::wanted($stock, $include, $keepDeuterium, $fuel);
            $picked = self::pickShips(array_sum($wanted) + (int) ceil($fuel), $ships, $capacity);
            if ($picked === []) {
                return null;
            }
            $newFuel = $fuelFor($picked);
            if (abs($newFuel - $fuel) < 0.5) {
                $fuel = $newFuel;
                break;
            }
            $fuel = $newFuel;
        }
        if ($picked === [] || $fuel > floor((float) ($stock[903] ?? 0))) {
            return null;
        }

        $room = self::room($picked, $capacity);
        $cargo = self::fit(self::wanted($stock, $include, $keepDeuterium, $fuel), (int) floor($room - $fuel));

        return array_sum($cargo) < 1 ? null : ['ships' => $picked, 'cargo' => $cargo, 'capacity' => $room, 'fuel' => $fuel];
    }

    /**
     * What to take of each resource: everything chosen, deuterium minus the fuel and the reserve.
     *
     * @param array<int, int|float> $stock
     * @param array<int, bool> $include
     * @return array<int, int> [901 => .., 902 => .., 903 => ..]
     */
    private static function wanted(array $stock, array $include, int $keepDeuterium, float $fuel): array
    {
        return [
            901 => empty($include[901]) ? 0 : max(0, (int) floor($stock[901] ?? 0)),
            902 => empty($include[902]) ? 0 : max(0, (int) floor($stock[902] ?? 0)),
            903 => empty($include[903]) ? 0 : max(0, (int) floor(($stock[903] ?? 0) - $fuel) - max(0, $keepDeuterium)),
        ];
    }

    /**
     * Cuts the cargo down to $room: deuterium first, then crystal, then metal.
     *
     * @param array<int, int> $cargo
     * @return array<int, int>
     */
    private static function fit(array $cargo, int $room): array
    {
        $room = max(0, $room);
        foreach ([903, 902, 901] as $id) {
            $over = max(0, array_sum($cargo) - $room);
            $cargo[$id] = max(0, $cargo[$id] - $over);
        }

        return $cargo;
    }

    /**
     * Total cargo capacity of the picked ships.
     *
     * @param array<int, int> $picked
     * @param array<int, int|float> $capacity
     */
    private static function room(array $picked, array $capacity): int
    {
        $room = 0;
        foreach ($picked as $id => $count) {
            $room += (int) ($capacity[$id] ?? 0) * $count;
        }

        return $room;
    }

    /**
     * Large cargos as far as they fill up, the rest with small cargos; one more large cargo when the smalls are not
     * enough or would carry more than a large cargo does. Fewer ships than needed when there are not more.
     *
     * @param array<int, int|float> $ships available [203 => .., 202 => ..]
     * @param array<int, int|float> $capacity
     * @return array<int, int> ship id => count (only counts above 0, large first); [] when no ship can carry
     */
    private static function pickShips(int $need, array $ships, array $capacity): array
    {
        $availL = (int) ($ships[self::LARGE_CARGO] ?? 0);
        $availS = (int) ($ships[self::SMALL_CARGO] ?? 0);
        $capL = (int) ($capacity[self::LARGE_CARGO] ?? 0);
        $capS = (int) ($capacity[self::SMALL_CARGO] ?? 0);
        if ($need < 1) {
            return [];
        }
        $large = $capL > 0 ? min($availL, intdiv($need, $capL)) : 0;
        $rest = $need - $large * $capL;
        $small = 0;
        if ($rest > 0) {
            $small = $capS > 0 ? (int) ceil($rest / $capS) : PHP_INT_MAX;
            if ($capL > 0 && $large < $availL && ($small > $availS || $small * $capS >= $capL)) {
                $large++;
                $small = 0;
            }
            $small = min($small, $availS);
        }

        return array_filter([self::LARGE_CARGO => $large, self::SMALL_CARGO => $small], static fn(int $count): bool => $count > 0);
    }
}
