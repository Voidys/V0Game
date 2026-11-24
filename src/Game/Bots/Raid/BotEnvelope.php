<?php

declare(strict_types=1);

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Enum\Mission;

/**
 * The anti-frustration envelope shared by all bots of a universe. Whatever a single bot is allowed to do, a human player is hit by the
 * bots together at most `bots_raids_per_human_day` times per 24 hours, not again within `bots_human_cooldown_hours` of the last bot
 * attack, not while a bot attack on him is still on its way, and is spied on at most `bots_probes_per_human_day` times a day by all
 * bots together. The rules count the flights in the fleet log (all bots, recalled flights excluded) and only protect humans: bots may
 * hit bots without limits beyond their own.
 */
class BotEnvelope
{
    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
    ) {}

    /**
     * Why no bot may send this mission to the player now, null when it may. For a spy flight only the probe quota (and a full raid
     * quota, which makes looking pointless) counts; for an attack all rules apply.
     *
     * @param int $mission Mission::Attack->value, Mission::Acs->value (joining a bot group) or Mission::Spy->value
     * @param int $joinGroup ACS group the bot joins: the flights of that group are one attack with the leader's, not a second one
     */
    public function refusal(BotActions $ctx, int $targetUserId, int $mission, int $joinGroup = 0): ?string
    {
        if ($this->isBot($targetUserId)) {
            return null;
        }
        $now = $this->state->runtime->timestamp;
        $bot = $ctx->bot;
        if ($mission === Mission::Spy->value) {
            if ($this->flights($targetUserId, [Mission::Spy->value], $now - 86400) >= $bot->probesPerHumanDay) {
                return 'bots spied on this player often enough today';
            }

            return $this->attackQuota($ctx, $targetUserId, $now, false);
        }

        return $this->attackQuota($ctx, $targetUserId, $now, true, $joinGroup);
    }

    /**
     * The attack rules: quota per day, cooldown since the last attack and (when `$flying`) no bot attack still on its way.
     */
    private function attackQuota(BotActions $ctx, int $targetUserId, int $now, bool $flying, int $joinGroup = 0): ?string
    {
        $attacks = [Mission::Attack->value, Mission::Acs->value, Mission::Destruction->value];
        if ($this->flights($targetUserId, $attacks, $now - 86400, $joinGroup) >= $ctx->bot->raidsPerHumanDay) {
            return 'bots attacked this player often enough today';
        }
        if (!$flying) {
            return null;
        }
        $inFlight = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%FLEETS%% f INNER JOIN %%BOT_PLAYERS%% bp ON bp.user_id = f.owner
            WHERE f.target_owner = :t AND f.mission IN (:a, :b, :c) AND f.status = :s AND (:g = 0 OR f.`group` <> :g2);',
            [':t' => $targetUserId, ':a' => $attacks[0], ':b' => $attacks[1], ':c' => $attacks[2], ':s' => FLEET_OUTWARD, ':g' => $joinGroup, ':g2' => $joinGroup],
            'c',
        );
        if ($inFlight > 0) {
            return 'a bot attack on this player is on its way';
        }

        return $this->flights($targetUserId, $attacks, $now - $ctx->bot->humanCooldownSeconds(), $joinGroup) > 0 ? 'another bot attacked this player a short while ago' : null;
    }

    /**
     * Flights of any bot to the player with one of the missions that arrive (or arrived) after `$since` (recalled ones not counted).
     *
     * An ACS group counts once (all its fleets are one attack); with `$exceptGroup` that group is not counted at all.
     *
     * @param list<int> $missions
     */
    private function flights(int $targetUserId, array $missions, int $since, int $exceptGroup = 0): int
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(DISTINCT IF(l.`group` IN (\'0\', \'\'), CONCAT(\'f\', l.id), l.`group`)) AS c FROM %%LOG_FLEETS%% l INNER JOIN %%BOT_PLAYERS%% bp ON bp.user_id = l.owner
            WHERE l.target_owner = :t AND l.mission IN (' . implode(',', array_map('intval', $missions)) . ') AND l.arrival_time > :since AND l.hasCanceled = 0
              AND (:g = 0 OR l.`group` <> :g2);',
            [':t' => $targetUserId, ':since' => $since, ':g' => $exceptGroup, ':g2' => (string) $exceptGroup],
            'c',
        );
    }

    /** Whether the user is a bot. */
    private function isBot(int $userId): bool
    {
        return !empty($this->db->selectSingle('SELECT user_id FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $userId]));
    }
}
