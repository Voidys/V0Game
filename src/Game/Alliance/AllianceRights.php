<?php

declare(strict_types=1);

namespace App\Game\Alliance;

/**
 * The rank rights of an alliance (columns of the alliance_ranks table) and the rules that derive from them.
 * Stateless; the rights of the current member are computed once per request by AllianceRepository::rightsOf().
 */
final class AllianceRights
{
    /** right names in display order; the index is the key of the permission form fields */
    public const AVAILABLE = [
        'MEMBERLIST',
        'ONLINESTATE',
        'TRANSFER',
        'SEEAPPLY',
        'MANAGEAPPLY',
        'ROUNDMAIL',
        'ADMIN',
        'KICK',
        'DIPLOMATIC',
        'RANKS',
        'MANAGEUSERS',
        'TARGETS',
        'ACTIVITY',
        'WIKI',
    ];

    /** @return array<string, bool> every right set to $value (leader: all true, no rank: all false) */
    public static function all(bool $value): array
    {
        return array_combine(self::AVAILABLE, array_fill(0, count(self::AVAILABLE), $value));
    }

    /**
     * Whether a member holding `$ownRights` may grant/assign the rank row: a rank is out of reach as soon as it
     * carries a right the acting member lacks (nobody can hand out rights they do not hold).
     *
     * @param array<string, mixed> $rankRow alliance_ranks row with the right columns
     * @param array<string, bool>  $ownRights rights of the acting member
     */
    public static function canHandOut(array $rankRow, array $ownRights): bool
    {
        foreach (self::AVAILABLE as $right) {
            if (!$ownRights[$right] && $rankRow[$right] == 1) {
                return false;
            }
        }

        return true;
    }
}
