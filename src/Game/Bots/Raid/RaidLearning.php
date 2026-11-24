<?php

namespace App\Game\Bots\Raid;

/**
 * What a raiding bot learns from raids that cost more than the simulation promised: a mild loss multiplier (1 to MAX) kept in the
 * personality JSON (`_learn`: `m` multiplier, `at` time it was set). The planner multiplies the expected losses of later raids
 * with it; it decays back to 1 (half of the excess per day), so one bad day does not make a bot timid for good.
 */
final class RaidLearning
{
    /** Largest multiplier ever applied to the expected losses. */
    public const MAX = 1.75;

    /** Seconds in which the excess over 1 halves. */
    private const HALF_LIFE = 86400;

    /** A raid counts as a miss when its losses were at least this share above the estimate. */
    private const TOLERANCE = 1.25;

    /**
     * The multiplier valid at `$now`.
     *
     * @param array<string, mixed> $personality
     */
    public static function multiplier(array $personality, int $now): float
    {
        $learn = $personality['_learn'] ?? null;
        if (!is_array($learn) || empty($learn['m'])) {
            return 1.0;
        }
        $age = max(0, $now - (int) ($learn['at'] ?? 0));

        return 1.0 + max(0.0, (float) $learn['m'] - 1.0) * 0.5 ** ($age / self::HALF_LIFE);
    }

    /**
     * Notes the result of a raid: when the real losses were clearly higher than estimated (and not trivial), the multiplier rises towards
     * the ratio of real to estimated losses (a third of the excess, at least what is already in force, never above MAX).
     *
     * @param array<string, mixed> $personality changed in place
     * @param float $estimated losses the plan expected (value units)
     * @param float $actual losses the fight really cost
     * @return float the multiplier now in force
     */
    public static function learn(array &$personality, float $estimated, float $actual, int $now): float
    {
        $current = self::multiplier($personality, $now);
        if ($actual < 500 || $actual < max(1.0, $estimated) * self::TOLERANCE) {
            return $current;
        }
        $target = 1.0 + min(2.25, $actual / max(1.0, $estimated) - 1.0) / 3;
        $new = round(min(self::MAX, max($current, $target)), 3);
        $personality['_learn'] = ['m' => $new, 'at' => $now];

        return $new;
    }
}
