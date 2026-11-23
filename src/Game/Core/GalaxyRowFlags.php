<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * Filter flags of one galaxy row (GalaxyRows entry) for the client-side filters of the galaxy view: pure data in, flags out.
 * "In attack range" means the owner can be attacked under the protection rules already applied to the row's status
 * classes (no noob/strong protection, no vacation, no ban) and is not the viewer himself.
 */
final class GalaxyRowFlags
{
    /**
     * @param array<string, mixed> $row one GalaxyRows entry (destroyed, user.class, moon, debris, ownPlanet, wreck)
     * @param int|float $recyclerCapacity cargo capacity of one recycler (0: unknown, then no recyclers are counted)
     * @return array{inactive: bool, inRange: bool, debris: int, moon: bool, vacation: bool, wreck: bool, recyclers: int}
     */
    public static function compute(array $row, int|float $recyclerCapacity): array
    {
        $debris = $row['debris'] ?? false;
        $debrisTotal = $debris ? (int) floor((float) $debris['metal'] + (float) $debris['crystal']) : 0;
        $classes = (array) ($row['user']['class'] ?? []);
        $destroyed = !empty($row['destroyed']);
        $has = static fn(string $class): bool => in_array($class, $classes, true);

        return [
            'inactive' => !$destroyed && ($has('inactive') || $has('longinactive')),
            'inRange' => !$destroyed && !empty($row['user']) && empty($row['ownPlanet'])
                && !$has('noob') && !$has('strong') && !$has('vacation') && !$has('banned'),
            'debris' => $debrisTotal,
            'moon' => !$destroyed && !empty($row['moon']),
            'vacation' => !$destroyed && $has('vacation'),
            'wreck' => !$destroyed && !empty($row['wreck']),
            'recyclers' => self::recyclersNeeded($debrisTotal, $recyclerCapacity),
        ];
    }

    /** Recyclers needed to carry `$debris` away in one go (rounded up). */
    public static function recyclersNeeded(int|float $debris, int|float $recyclerCapacity): int
    {
        if ($debris <= 0 || $recyclerCapacity <= 0) {
            return 0;
        }

        return (int) ceil($debris / $recyclerCapacity);
    }
}
