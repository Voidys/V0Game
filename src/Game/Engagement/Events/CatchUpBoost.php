<?php

declare(strict_types=1);

namespace App\Game\Engagement\Events;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Catch-up boost for late joiners: a young account (younger than catchup_days) with fewer than catchup_threshold_percent
 * of the average points of the players active in the last 7 days gets a metal/crystal/deuterium mine production bonus of
 * up to catchup_bonus_percent that shrinks linearly to 0 at the end of the period. Bots never get it. Once an account is
 * over the threshold the boost ends for good (row in eng_catchup), so losing points later cannot restore it.
 * Accounts that share an IP with another human account or sit in a multi group get nothing (multi farming).
 * The factor is not integrated over the elapsed window: the boost only shrinks, so applying today's (lower) value is conservative.
 * The average is stored once per day (eng_catchup_avg), never computed per production call.
 */
final class CatchUpBoost
{
    private const AVG_MAX_AGE = 86400;
    private const ACTIVE_WINDOW = 7 * 86400;

    /** @var array<int, array{percent: float, endsAt: int}> per user id, memoised for the request */
    private array $memo = [];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** Whether the admin switched the boost on for the universe. */
    public function enabled(int $universe): bool
    {
        return (bool) ($this->configs->get($universe)->catchup_enabled ?? false);
    }

    /** Current bonus in percent (0 when not eligible); cheap when the feature is off (no query). */
    public function percentFor(int $userId, int $universe, int $registerTime): float
    {
        return $this->statusFor($userId, $universe, $registerTime)['percent'];
    }

    /**
     * Bonus percent and end of the period for the resources page; percent is 0 when the player is not eligible.
     *
     * @return array{percent: float, endsAt: int}
     */
    public function statusFor(int $userId, int $universe, int $registerTime): array
    {
        $none = ['percent' => 0.0, 'endsAt' => 0];
        if (!$this->enabled($universe)) {
            return $none;
        }

        return $this->memo[$userId] ??= $this->compute($userId, $universe, $registerTime) ?? $none;
    }

    /**
     * @return array{percent: float, endsAt: int}|null null when not eligible
     */
    private function compute(int $userId, int $universe, int $registerTime): ?array
    {
        $config = $this->configs->get($universe);
        $now = $this->state->runtime->timestamp;
        $days = max(1, min(60, (int) ($config->catchup_days ?? 14)));
        $endsAt = $registerTime + $days * 86400;
        if ($now >= $endsAt || $registerTime <= 0) {
            return null;
        }

        if ($this->blockedOrEnded($userId, $universe, $now)) {
            return null;
        }

        $points = (float) $this->db->selectSingle(
            'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;',
            [':id' => $userId],
            'total_points',
        );
        $limit = $this->averagePoints($universe, $now) * max(1, min(100, (int) ($config->catchup_threshold_percent ?? 25))) / 100;
        if ($limit <= 0) {
            return null;
        }
        if ($points > $limit) {
            $this->db->insert(
                'INSERT INTO %%ENG_CATCHUP%% (user_id, universe, ended_at) VALUES (:id, :uni, :now)
                ON DUPLICATE KEY UPDATE ended_at = IF(ended_at = 0, VALUES(ended_at), ended_at);',
                [':id' => $userId, ':uni' => $universe, ':now' => $now],
            );

            return null;
        }

        $max = max(0, min(100, (int) ($config->catchup_bonus_percent ?? 50)));
        $percent = round($max * (1 - ($now - $registerTime) / ($days * 86400)), 1);

        return $percent > 0 ? ['percent' => $percent, 'endsAt' => $endsAt] : null;
    }

    /**
     * True when the boost ended for good, or the account is a bot / shares an identity (verdict stored in eng_catchup
     * and recomputed at most once an hour, so ineligible accounts cost one indexed lookup per request).
     */
    private function blockedOrEnded(int $userId, int $universe, int $now): bool
    {
        $row = $this->db->selectSingle(
            'SELECT ended_at, blocked, check_until FROM %%ENG_CATCHUP%% WHERE user_id = :id;',
            [':id' => $userId],
        );
        if (is_array($row) && $row !== []) {
            if ((int) $row['ended_at'] > 0) {
                return true;
            }
            if ((int) $row['check_until'] > $now) {
                return (bool) $row['blocked'];
            }
        }

        $bot = (bool) $this->db->selectSingle('SELECT user_id FROM %%BOT_PLAYERS%% WHERE user_id = :id;', [':id' => $userId], 'user_id');
        $blocked = $bot || $this->sharesIdentity($userId, $universe);
        $this->db->insert(
            'INSERT INTO %%ENG_CATCHUP%% (user_id, universe, blocked, check_until) VALUES (:id, :uni, :b, :until)
            ON DUPLICATE KEY UPDATE blocked = VALUES(blocked), check_until = VALUES(check_until);',
            [':id' => $userId, ':uni' => $universe, ':b' => (int) $blocked, ':until' => $now + 3600],
        );

        return $blocked;
    }

    /**
     * Multi-account guard: true when the account shares its registration or last IP with another non-bot account of
     * the universe, or sits in a multi group (tables multi / multi_to_users, as flagged by the multi IP detection).
     */
    private function sharesIdentity(int $userId, int $universe): bool
    {
        $shared = $this->db->selectSingle(
            "SELECT o.id FROM %%USERS%% me
            INNER JOIN %%USERS%% o ON o.universe = me.universe AND o.id <> me.id
                AND ((me.ip_at_reg <> '' AND (o.ip_at_reg = me.ip_at_reg OR o.user_lastip = me.ip_at_reg))
                  OR (me.user_lastip <> '' AND (o.ip_at_reg = me.user_lastip OR o.user_lastip = me.user_lastip)))
            LEFT JOIN %%BOT_PLAYERS%% b ON b.user_id = o.id
            WHERE me.id = :id AND b.user_id IS NULL LIMIT 1;",
            [':id' => $userId],
            'id',
        );
        if ($shared) {
            return true;
        }

        return (bool) $this->db->selectSingle(
            'SELECT m.userID FROM %%MULTI_TO_USERS%% m INNER JOIN %%MULTI%% g ON g.multiID = m.multiID AND g.universe = :uni
            WHERE m.userID = :id LIMIT 1;',
            [':id' => $userId, ':uni' => $universe],
            'userID',
        );
    }

    /** Average total points of the players active in the last 7 days (no bots, no admins, not banned), cached for a day. */
    public function averagePoints(int $universe, int $now): float
    {
        $row = $this->db->selectSingle(
            'SELECT avg_points, computed_at FROM %%ENG_CATCHUP_AVG%% WHERE universe = :uni;',
            [':uni' => $universe],
        );
        if (is_array($row) && $row !== [] && $now - (int) $row['computed_at'] < self::AVG_MAX_AGE) {
            return (float) $row['avg_points'];
        }

        $avg = (float) $this->db->selectSingle(
            'SELECT AVG(s.total_points) AS a FROM %%STATPOINTS%% s
            INNER JOIN %%USERS%% u ON u.id = s.id_owner
            LEFT JOIN %%BOT_PLAYERS%% b ON b.user_id = u.id
            WHERE s.stat_type = 1 AND s.universe = :uni AND u.universe = :uni2 AND u.authlevel = 0 AND u.bana = 0
                AND u.onlinetime > :since AND b.user_id IS NULL;',
            [':uni' => $universe, ':uni2' => $universe, ':since' => $now - self::ACTIVE_WINDOW],
            'a',
        );
        $this->db->replace(
            'REPLACE INTO %%ENG_CATCHUP_AVG%% (universe, avg_points, computed_at) VALUES (:uni, :avg, :now);',
            [':uni' => $universe, ':avg' => $avg, ':now' => $now],
        );

        return $avg;
    }
}
