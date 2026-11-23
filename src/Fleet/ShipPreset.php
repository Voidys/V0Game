<?php

declare(strict_types=1);

namespace App\Fleet;

/**
 * The compact ship list `202:50,210:2` (ship id : count, comma separated) used by the step-1 preset link parameter
 * `ships` and by the stored fleet templates. Pure parsing and formatting, no game state: the caller passes the ids
 * that may fly.
 */
final class ShipPreset
{
    /** Largest accepted count per ship type. */
    public const MAX_COUNT = 1000000000;

    /**
     * The ids that may be sent from a list of fleet ship ids: solar satellites (212) cannot fly.
     *
     * @param iterable<int|string> $fleetIds
     * @return list<int>
     */
    public static function flyable(iterable $fleetIds): array
    {
        $ids = [];
        foreach ($fleetIds as $id) {
            if ((int) $id !== 212) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Parses and validates a ship list. Returns null for anything malformed: empty text, a token that is not
     * `<digits>:<digits>`, an id that is not in `$validIds`, a duplicate id, a count below 1 or above MAX_COUNT.
     *
     * @param list<int> $validIds ship ids that may be sent
     * @return array<int, int>|null ship id => count
     */
    public static function parse(string $raw, array $validIds): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 500) {
            return null;
        }
        $ships = [];
        foreach (explode(',', $raw) as $token) {
            if (!preg_match('/^(\d{1,6}):(\d{1,12})$/', $token, $match)) {
                return null;
            }
            $id = (int) $match[1];
            $count = (int) $match[2];
            if (!in_array($id, $validIds, true) || isset($ships[$id]) || $count < 1 || $count > self::MAX_COUNT) {
                return null;
            }
            $ships[$id] = $count;
        }

        return $ships;
    }

    /**
     * The text form of a ship list (counts below 1 are left out).
     *
     * @param array<int|string, int|float|string> $ships ship id => count
     */
    public static function format(array $ships): string
    {
        $parts = [];
        foreach ($ships as $id => $count) {
            if ((int) $count >= 1) {
                $parts[] = (int) $id . ':' . (int) $count;
            }
        }

        return implode(',', $parts);
    }
}
