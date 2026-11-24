<?php

declare(strict_types=1);

namespace App\Game\Engagement;

/**
 * ISO weeks of the engagement features: the key is the ISO year and week (e.g. 202648), a week starts on Monday 00:00 in
 * the server time zone.
 */
final class Week
{
    /** Key of the week a timestamp lies in. */
    public static function key(int $time): int
    {
        return (int) (new \DateTimeImmutable('@' . $time))->setTimezone(self::zone())->format('oW');
    }

    /** Timestamp of Monday 00:00 of the week a timestamp lies in. */
    public static function start(int $time): int
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(self::zone())->modify('monday this week')->setTime(0, 0)->getTimestamp();
    }

    /** Timestamp of the start of the following week (the end of the current one). */
    public static function end(int $time): int
    {
        return (new \DateTimeImmutable('@' . self::start($time)))->setTimezone(self::zone())->modify('+1 week')->setTime(0, 0)->getTimestamp();
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }
}
