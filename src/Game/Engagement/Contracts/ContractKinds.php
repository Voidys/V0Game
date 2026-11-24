<?php

declare(strict_types=1);

namespace App\Game\Engagement\Contracts;

use App\Game\AdvancedStats\AdvancedStatsEnum;

/**
 * The whitelist of weekly contract kinds: where progress comes from (an advanced-stats counter or a fight) and how the
 * target scales with the player's points, within a minimum and maximum.
 */
final class ContractKinds
{
    public const EXPEDITIONS = 'expeditions';
    public const RECYCLED = 'recycled';
    public const SHIPS = 'ships';
    public const DEFENSE = 'defense';
    public const FUEL = 'fuel';
    public const DESTROYED = 'destroyed';
    public const RAIDED = 'raided';
    public const WINS = 'wins';

    /**
     * kind => [source ('stat' or 'battle'), points per target unit (points are in thousands of resources), min, max]
     *
     * @var array<string, array{0: string, 1: float, 2: int, 3: int}>
     */
    private const DEFINITIONS = [
        self::EXPEDITIONS => ['stat', 100000.0, 2, 10],
        self::RECYCLED => ['stat', 0.05, 50000, 50000000],
        self::SHIPS => ['stat', 50.0, 10, 2000],
        self::DEFENSE => ['stat', 40.0, 10, 2000],
        self::FUEL => ['stat', 0.03, 10000, 5000000],
        self::DESTROYED => ['battle', 0.1, 20000, 100000000],
        self::RAIDED => ['battle', 0.05, 20000, 50000000],
        self::WINS => ['battle', 5000.0, 2, 8],
    ];

    /** Ships that do not count for "ships built" (cheap probes and satellites). */
    private const CHEAP_SHIPS = [210, 212];

    /** @return list<string> every kind */
    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(string $kind): bool
    {
        return isset(self::DEFINITIONS[$kind]);
    }

    /** Target of a kind for a player with the given total points (thousands), within the bounds of the kind. */
    public static function target(string $kind, float $points): int
    {
        [, $perUnit, $min, $max] = self::DEFINITIONS[$kind];
        // the divisor kinds (>= 1) shrink with the points, the factor kinds (< 1) grow like points * 1000 * factor
        $raw = $perUnit >= 1 ? $points / $perUnit : $points * 1000 * $perUnit;

        return max($min, min($max, (int) round($raw)));
    }

    /** Kind a stat counter feeds, null when no contract listens to it (expeditions arrive through the onExpedition hook, not as a stat). */
    public static function forStat(AdvancedStatsEnum $key): ?string
    {
        $name = $key->name;
        if ($name === 'RECYCLED_METAL' || $name === 'RECYCLED_CRYSTAL') {
            return self::RECYCLED;
        }
        if ($name === 'USED_FUEL') {
            return self::FUEL;
        }
        if (preg_match('/^BUILD_(\d+)$/', $name, $match) === 1) {
            $element = (int) $match[1];
            if ($element >= 200 && $element < 300) {
                return in_array($element, self::CHEAP_SHIPS, true) ? null : self::SHIPS;
            }
            if ($element >= 400 && $element < 500) {
                return self::DEFENSE;
            }
        }

        return null;
    }

    /** Whether the kind is fed by fights. */
    public static function isBattle(string $kind): bool
    {
        return (self::DEFINITIONS[$kind][0] ?? '') === 'battle';
    }
}
