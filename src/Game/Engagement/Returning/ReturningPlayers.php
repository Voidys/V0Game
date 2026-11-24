<?php

declare(strict_types=1);

namespace App\Game\Engagement\Returning;

use App\Game\Enum\Mission;

/**
 * Welcome of returning players: a player who logs in after `returning_days` away (vacation mode does not count) gets a row in
 * eng_returns, a one-time summary on the overview and, once per `returning_cooldown_days`, a protection of
 * `returning_protection_hours` hours against attacks, spying and missiles. The protection ends when the player attacks, spies
 * or fires missiles himself. Bots get nothing. Not an EngagementFeature: it is driven by the login (Session) and the fleet rules.
 */
final class ReturningPlayers
{
    /** Missions that are refused against a protected player and that end the sender's own protection. */
    public const AGGRESSIVE = [
        Mission::Attack->value, Mission::Acs->value, Mission::Spy->value, Mission::Destruction->value, Mission::Missile->value,
    ];

    public function __construct(
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {}

    public function enabled(int $universe): bool
    {
        return $universe > 0 && (bool) ($this->configs->get($universe)->returning_enabled ?? false);
    }

    /**
     * Login hook (called by the session before it refreshes users.onlinetime, with the universe of the session; nothing happens while the feature is off): records the return of a player who was away for
     * returning_days or more. Idempotent per absence (unique user + away_since).
     */
    public function onLogin(int $universe, int $userId, int $now): void
    {
        if ($userId <= 0 || !$this->enabled($universe)) {
            return;
        }
        $user = $this->db->selectSingle(
            'SELECT u.`onlinetime`, u.`urlaubs_modus`, u.`urlaubs_until`, u.`bana`, b.`user_id` AS bot FROM %%USERS%% u
            LEFT JOIN %%BOT_PLAYERS%% b ON b.`user_id` = u.`id` WHERE u.`id` = :user;',
            [':user' => $userId],
        );
        if (empty($user) || !empty($user['bot']) || (int) $user['urlaubs_modus'] === 1 || (int) $user['bana'] === 1) {
            return;
        }
        $config = $this->configs->get($universe);
        // vacation that ended while the player was away does not count as time away
        $awaySince = (int) $user['onlinetime'];
        if ((int) $user['urlaubs_until'] > $awaySince && (int) $user['urlaubs_until'] < $now) {
            $awaySince = (int) $user['urlaubs_until'];
        }
        $days = max(1, (int) ($config->returning_days ?? 14));
        if ($awaySince <= 0 || $now - $awaySince < $days * 86400) {
            return;
        }
        $this->db->insert(
            'INSERT IGNORE INTO %%ENG_RETURNS%% (`user_id`, `universe`, `away_since`, `returned_at`, `protected_until`, `seen`)
            VALUES (:user, :universe, :away, :now, :until, 0);',
            [':user' => $userId, ':universe' => $universe, ':away' => $awaySince, ':now' => $now, ':until' => $this->protectionEnd($universe, $userId, $now)],
        );
    }

    /** End of the protection granted now: 0 when protection is off or the cooldown since the last protection has not passed. */
    private function protectionEnd(int $universe, int $userId, int $now): int
    {
        $config = $this->configs->get($universe);
        $hours = max(0, min(48, (int) ($config->returning_protection_hours ?? 12)));
        if ($hours === 0) {
            return 0;
        }
        $cooldown = max(1, (int) ($config->returning_cooldown_days ?? 60)) * 86400;
        $last = (int) $this->db->selectSingle(
            'SELECT MAX(`returned_at`) AS t FROM %%ENG_RETURNS%% WHERE `user_id` = :user AND `protected_until` > 0;',
            [':user' => $userId],
            't',
        );

        return $last > 0 && $now - $last < $cooldown ? 0 : $now + $hours * 3600;
    }

    /**
     * End of the returning protection of a player, 0 when he is not protected (or the feature is off).
     */
    public function protectedUntil(int $universe, int $userId): int
    {
        if (!$this->enabled($universe) || $userId <= 0) {
            return 0;
        }

        return (int) $this->db->selectSingle(
            'SELECT MAX(`protected_until`) AS t FROM %%ENG_RETURNS%% WHERE `user_id` = :user AND `protected_until` > :now;',
            [':user' => $userId, ':now' => $this->state->runtime->timestamp],
            't',
        );
    }

    /** Ends the protection of a player at once (he sent an aggressive mission). */
    public function endProtection(int $universe, int $userId): void
    {
        if (!$this->enabled($universe) || $userId <= 0) {
            return;
        }
        $now = $this->state->runtime->timestamp;
        $this->db->update(
            'UPDATE %%ENG_RETURNS%% SET `protected_until` = :now WHERE `user_id` = :user AND `protected_until` > :now2;',
            [':now' => $now, ':user' => $userId, ':now2' => $now],
        );
    }

    /**
     * The unseen return of the player with the numbers of the welcome summary, null when there is nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $universe, int $userId, \App\Game\Core\Language $LNG): ?array
    {
        if (!$this->enabled($universe)) {
            return null;
        }
        $row = $this->db->selectSingle(
            'SELECT * FROM %%ENG_RETURNS%% WHERE `user_id` = :user AND `seen` = 0 ORDER BY `returned_at` DESC LIMIT 1;',
            [':user' => $userId],
        );
        if (empty($row)) {
            return null;
        }
        $fights = $this->db->selectSingle(
            'SELECT COUNT(*) AS anz, COALESCE(SUM(`message_text` LIKE :lost), 0) AS lost FROM %%MESSAGES%%
            WHERE `message_owner` = :user AND `message_type` = :type AND `message_time` >= :since AND `message_time` <= :until;',
            [':lost' => '%raportLose%', ':user' => $userId, ':type' => \App\Game\Enum\MessageType::BattleReport->value, ':since' => $row['away_since'], ':until' => $row['returned_at']],
        );
        $rankBefore = $this->db->selectSingle(
            'SELECT `total_rank` FROM %%STATPOINTS_HISTORY%% WHERE `id_owner` = :user AND `stat_type` = 1 AND `timestamp` <= :since ORDER BY `timestamp` DESC LIMIT 1;',
            [':user' => $userId, ':since' => $row['away_since']],
            'total_rank',
        );
        $rankNow = $this->db->selectSingle('SELECT `total_rank` FROM %%STATPOINTS%% WHERE `id_owner` = :user AND `stat_type` = 1;', [':user' => $userId], 'total_rank');

        return [
            'id' => (int) $row['id'],
            'days' => (int) floor(((int) $row['returned_at'] - (int) $row['away_since']) / 86400),
            'fights' => (int) ($fights['anz'] ?? 0),
            'lost' => (int) ($fights['lost'] ?? 0),
            'rankBefore' => empty($rankBefore) ? null : (int) $rankBefore,
            'rankNow' => empty($rankNow) ? null : (int) $rankNow,
            'protectedUntil' => max(0, (int) $row['protected_until']),
            'protectedHours' => max(0, (int) ceil(((int) $row['protected_until'] - $this->state->runtime->timestamp) / 3600)),
            'events' => $this->runningEvents($universe, $LNG),
        ];
    }

    /**
     * Titles of the universe events running now (empty while the event feature does not exist or is off).
     *
     * @return list<string>
     */
    private function runningEvents(int $universe, \App\Game\Core\Language $LNG): array
    {
        if (!class_exists(\App\Game\Engagement\Events\UniverseEvents::class)) {
            return [];
        }
        $events = new \App\Game\Engagement\Events\UniverseEvents($this->db, $this->configs, $this->state);
        $titles = [];
        foreach ($events->banner($universe, $this->state->runtime->timestamp, $LNG) as $event) {
            if (!empty($event['running'])) {
                $titles[] = (string) $event['title'];
            }
        }

        return $titles;
    }

    /** Marks the welcome summary as seen (dismissed); only the player's own row. */
    public function dismiss(int $userId): void
    {
        $this->db->update('UPDATE %%ENG_RETURNS%% SET `seen` = 1 WHERE `user_id` = :user AND `seen` = 0;', [':user' => $userId]);
    }
}
