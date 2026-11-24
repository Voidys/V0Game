<?php

declare(strict_types=1);

namespace App\Game\Bots;

/**
 * When a bot is likely online: the hourly activity table of its profile read in the bot's own time (personal hour offset, the
 * profile's pattern shift, the hidden rhythm), and from that the time it can expect to stay offline after a session. A human knows
 * his own habits, so the fleetsave of a bot is timed on the expectation, not on the length of its next check interval.
 */
final class BotSchedule
{
    /** Longest expected offline time in seconds; a bot that has not come back by then is assumed to return within the day. */
    private const MAX_OFFLINE = 86400;

    /**
     * Probability (0-1) that a check at `$at` brings the bot online: the `activity` entry of its local hour.
     *
     * @param array<string, mixed> $s profile settings (`activity`, `pattern_shift_days`, `pattern_shift_hours`, `hidden_rhythm`)
     * @param int $tzOffset the bot's personal hour offset against the server clock
     */
    public static function probability(array $s, int $at, int $createdAt, int $tzOffset = 0): float
    {
        $activity = array_values((array) $s['activity']);
        $shiftDays = (int) ($s['pattern_shift_days'] ?? 0);
        $shift = 0;
        if ($shiftDays > 0) {
            $shift = (int) ($s['pattern_shift_hours'] ?? 0) * (int) floor(max(0, $at - $createdAt) / 86400 / $shiftDays);
        }
        if (!empty($s['hidden_rhythm'])) {
            // hidden rhythm: the daily pattern moves by a different (but fixed for the day) number of hours every day
            $shift += crc32('rhythm:' . (int) $s['hidden_rhythm'] . ':' . date('Ymd', $at)) % 24;
        }
        $index = (((int) date('G', $at) + $tzOffset - $shift) % 24 + 24) % 24;

        return max(0.0, min(1.0, (float) ($activity[$index] ?? 0.0)));
    }

    /**
     * Expected seconds until the bot is online again when it logs off at `$now` and checks every `$checkSeconds`: the expectation
     * of the time of the first successful check (a check succeeds with the probability of its hour), at most a day. Never less than
     * one check interval.
     *
     * @param array<string, mixed> $s profile settings
     */
    public static function expectedOffline(array $s, int $now, int $createdAt, int $checkSeconds, int $tzOffset = 0): int
    {
        $step = max(60, $checkSeconds);
        $none = 1.0;
        $expected = 0.0;
        for ($t = $step; $t < self::MAX_OFFLINE; $t += $step) {
            $p = self::probability($s, $now + $t, $createdAt, $tzOffset);
            $expected += $t * $none * $p;
            $none *= 1 - $p;
            if ($none < 0.001) {
                break;
            }
        }
        $expected += $none * self::MAX_OFFLINE;

        return (int) max($step, min(self::MAX_OFFLINE, round($expected)));
    }
}
