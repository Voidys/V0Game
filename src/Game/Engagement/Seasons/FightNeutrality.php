<?php

declare(strict_types=1);

namespace App\Game\Engagement\Seasons;

use App\Game\Core\Database;
use App\Game\Enum\DiploType;

/**
 * Are two players neutral to each other for the season ladder (a fight between them is no honest competition)? Same alliance,
 * buddies, allied alliances (pact, wing) and shared connections (same IP or registered as multi accounts) are neutral.
 * Like the honor points rules, but without the "inactive defender" rule: raiding inactive players is part of the game.
 */
final class FightNeutrality
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** @var array<int, array<string, mixed>|null> */
    private static array $rows = [];

    /** time the static memos were started; they are dropped after a minute so a long-running process sees alliance changes */
    private static int $since = 0;

    public function __construct(
        private Database $db,
        private int $now,
    ) {}

    public function pairIsNeutral(int $a, int $b): bool
    {
        $this->expire();
        $key = min($a, $b) . ':' . max($a, $b);

        return self::$cache[$key] ??= $this->compute($a, $b);
    }

    /** Drops the process-wide memos (shared by all features; user ids are unique over universes) when they are older than a minute. */
    private function expire(): void
    {
        $mono = intdiv(hrtime(true), 1_000_000_000); // monotonic, independent of the caller's notion of now
        if (self::$since === 0 || $mono - self::$since > 60 || $mono < self::$since) {
            self::$rows = [];
            self::$cache = [];
            self::$since = $mono;
        }
    }

    /**
     * Loads the rows of several users with one query (and the bot flag), so the pair checks of a fight do not query per user.
     *
     * @param list<int|string> $ids
     */
    public function users(array $ids): void
    {
        $this->expire();
        if (count(self::$rows) > 1000) {
            self::$rows = [];
            self::$cache = [];
        }
        $missing = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn(int $id): bool => $id > 0 && !array_key_exists($id, self::$rows),
        )));
        if ($missing === []) {
            return;
        }
        $found = $this->db->select(
            'SELECT u.`id`, u.`ally_id`, u.`user_lastip`, u.`ip_at_reg`, u.`authlevel`, u.`bana`,
            EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.`user_id` = u.`id`) AS bot
            FROM %%USERS%% u WHERE u.`id` IN (' . implode(',', $missing) . ');',
        );
        foreach ($missing as $id) {
            self::$rows[$id] = null;
        }
        foreach ($found as $row) {
            self::$rows[(int) $row['id']] = $row;
        }
    }

    /**
     * Cached user row (ally_id, IPs, authlevel, bana, bot), null for an unknown user.
     *
     * @return array<string, mixed>|null
     */
    public function user(int $id): ?array
    {
        $this->users([$id]);

        return self::$rows[$id] ?? null;
    }

    /** Neutral when one of the rules matches; a deleted player counts as neutral (nothing to score against). */
    private function compute(int $a, int $b): bool
    {
        $x = $this->user($a);
        $y = $this->user($b);
        if ($x === null || $y === null) {
            return true;
        }

        return $this->sameAlliance($x, $y)
            || $this->sameConnection($x, $y)
            || $this->buddies($a, $b)
            || $this->sameMulti($a, $b)
            || $this->alliedAlliances((int) $x['ally_id'], (int) $y['ally_id']);
    }

    /**
     * @param array<string, mixed> $x
     * @param array<string, mixed> $y
     */
    private function sameAlliance(array $x, array $y): bool
    {
        return (int) $x['ally_id'] > 0 && (int) $x['ally_id'] === (int) $y['ally_id'];
    }

    /**
     * @param array<string, mixed> $x
     * @param array<string, mixed> $y
     */
    private function sameConnection(array $x, array $y): bool
    {
        foreach (['user_lastip', 'ip_at_reg'] as $column) {
            foreach (['user_lastip', 'ip_at_reg'] as $other) {
                if ((string) $x[$column] !== '' && (string) $x[$column] === (string) $y[$other]) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buddies(int $a, int $b): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%BUDDY%% WHERE (`sender` = :a AND `owner` = :b) OR (`sender` = :b AND `owner` = :a);',
            [':a' => $a, ':b' => $b],
            'anz',
        ) > 0;
    }

    private function sameMulti(int $a, int $b): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%MULTI_TO_USERS%% x INNER JOIN %%MULTI_TO_USERS%% y ON y.`multiID` = x.`multiID` WHERE x.`userID` = :a AND y.`userID` = :b;',
            [':a' => $a, ':b' => $b],
            'anz',
        ) > 0;
    }

    /** Accepted main/wing/alliance pacts between the two alliances. */
    private function alliedAlliances(int $allyA, int $allyB): bool
    {
        if ($allyA <= 0 || $allyB <= 0) {
            return false;
        }

        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%DIPLO%% WHERE `accept` = 1 AND `level` IN (:main, :wing, :pact, :secret)
            AND ((`owner_1` = :a AND `owner_2` = :b) OR (`owner_1` = :b AND `owner_2` = :a))
            AND (`expires_at` IS NULL OR `expires_at` > :now);',
            [
                ':main' => DiploType::Main->value,
                ':wing' => DiploType::Wing->value,
                ':pact' => DiploType::AlliancePact->value,
                ':secret' => DiploType::SecretAlliancePact->value,
                ':a' => $allyA,
                ':b' => $allyB,
                ':now' => $this->now,
            ],
            'anz',
        ) > 0;
    }
}
