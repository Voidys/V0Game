<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bots;

use App\Game\Bots\BotSchedule;
use App\Game\Bots\BotSettings;
use PHPUnit\Framework\TestCase;

/**
 * When a bot is online: the activity table in the bot's own time, the expected offline time from it, and the personal time zone.
 */
final class BotScheduleTest extends TestCase
{
    /** @return array<string, mixed> a day player: busy 8-22 o'clock, a trickle at night */
    private function dayPlayer(): array
    {
        $activity = [];
        for ($h = 0; $h < 24; $h++) {
            $activity[] = $h >= 8 && $h < 22 ? 0.5 : 0.02;
        }

        return ['activity' => $activity, 'pattern_shift_days' => 0, 'pattern_shift_hours' => 0];
    }

    public function testExpectedOfflineIsLongOverNightAndShortByDay(): void
    {
        $s = $this->dayPlayer();
        $midnight = (int) strtotime('2030-01-01 23:30:00');
        $noon = (int) strtotime('2030-01-01 12:00:00');
        $created = $noon - 30 * 86400;

        $night = BotSchedule::expectedOffline($s, $midnight, $created, 20 * 60);
        $day = BotSchedule::expectedOffline($s, $noon, $created, 20 * 60);

        self::assertGreaterThan(5 * 3600, $night, 'the next likely session is in the morning, not after one check interval');
        self::assertLessThanOrEqual(86400, $night);
        self::assertLessThan(1.5 * 3600, $day);
        self::assertGreaterThanOrEqual(20 * 60, $day, 'never less than one check interval');
    }

    public function testPersonalOffsetMovesTheTable(): void
    {
        $s = $this->dayPlayer();
        $at = (int) strtotime('2030-01-01 23:00:00');

        self::assertSame(0.02, BotSchedule::probability($s, $at, $at - 86400, 0));
        self::assertSame(0.5, BotSchedule::probability($s, $at, $at - 86400, -3), 'three hours behind it is 20 o\'clock: evening');
        self::assertSame(0.02, BotSchedule::probability($s, $at, $at - 86400, 3), 'three hours ahead it is 2 o\'clock: night');
    }

    public function testTimezoneOffsetIsStableBoundedAndSpread(): void
    {
        $knobs = BotSettings::defaults();
        $seen = [];
        for ($id = 1; $id <= 200; $id++) {
            $tz = $knobs->timezoneOffset($id);
            self::assertSame($tz, $knobs->timezoneOffset($id), 'stable');
            self::assertGreaterThanOrEqual(-3, $tz);
            self::assertLessThanOrEqual(3, $tz);
            $seen[$tz] = true;
        }
        self::assertCount(7, $seen, 'every offset in -3..+3 occurs');
        self::assertSame(2, $knobs->timezoneOffset(5, 2), 'a stored offset wins');
        self::assertSame(3, $knobs->timezoneOffset(5, 9), 'but stays inside the spread');
        self::assertSame(0, BotSettings::fromValues(['timezoneSpreadHours' => 0])->timezoneOffset(5, 2), 'spread 0 = off');
    }
}
