<?php

declare(strict_types=1);

namespace App\Community;

use App\Game\Admin\Chat\ChatModeration;
use App\Game\Chat\ChatSettings;
use App\Game\Core\Database;
use App\Game\Enum\AuthLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The in-game chat (module MODULE_CHAT, table CHAT_MESSAGES, separate from the player messages): a universe-wide
 * "global" channel and the "alliance" channel of the player's own alliance (stored as `ally:<id>`). Polled over plain
 * HTTP, no push. Messages are stored as plain text and must be shown as text only (the client uses textContent).
 */
final class Chat
{
    public const GLOBAL = 'global';
    public const ALLIANCE = 'alliance';

    public const MAX_LENGTH = 300;
    /** messages per poll response */
    public const PAGE = 50;
    /** the badge counts up to this many unread messages */
    public const UNREAD_CAP = 99;

    public const ERR_EMPTY = 'empty';
    public const ERR_CHANNEL = 'channel';
    public const ERR_BANNED = 'banned';
    public const ERR_MUTED = 'muted';
    public const ERR_RATE = 'rate';
    public const ERR_SLOW = 'slow';
    public const ERR_GLOBAL_OFF = 'global_off';
    public const ERR_AGE = 'age';
    public const ERR_POINTS = 'points';
    public const ERR_UNKNOWN = 'unknown';
    public const ERR_DUPLICATE_REPORT = 'reported';
    public const ERR_SELF = 'self';
    public const ERR_LIMIT = 'limit';
    public const ERR_REPORT_LIMIT = 'report_limit';
    /** open reports one player may have at a time */
    public const MAX_OPEN_REPORTS = 20;

    public const REASONS = ['spam', 'insult', 'cheating', 'other'];
    public const REPORT_COMMENT_MAX = 200;
    public const MAX_BLOCKS = 200;
    /** a message equal to one of the sender's last 3 global messages within this many seconds is refused */
    public const DUPLICATE_WINDOW = 60;
    /** seconds between two messages an alliance leader may choose as the slow mode of the alliance channel (0 = off) */
    public const ALLIANCE_SLOWMODES = [0, 10, 30, 60, 120, 300];

    public function __construct(
        private readonly Database $db,
        #[Autowire(service: 'limiter.chat_gap')]
        private readonly RateLimiterFactoryInterface $gap,
        #[Autowire(service: 'limiter.chat_burst')]
        private readonly RateLimiterFactoryInterface $burst,
        #[Autowire(service: 'limiter.chat_report')]
        private readonly RateLimiterFactoryInterface $reports,
    ) {}

    /**
     * The stored channel name a player may use for a client channel name, null when he may not (unknown channel, global
     * switched off, alliance without membership).
     *
     * @param array<string, mixed> $user the player row (ally_id)
     */
    public function channelFor(array $user, string $name, bool $globalEnabled = true): ?string
    {
        if ($name === self::GLOBAL) {
            return $globalEnabled ? self::GLOBAL : null;
        }
        $allyId = (int) ($user['ally_id'] ?? 0);

        return $name === self::ALLIANCE && $allyId > 0 ? 'ally:' . $allyId : null;
    }

    /** True for moderators, operators and admins (may delete messages and mute players). */
    public function isModerator(array $user): bool
    {
        return (int) ($user['authlevel'] ?? 0) >= AuthLevel::Moderator->value;
    }

    /**
     * Normalised text: invisible filler characters are removed, control characters, line breaks and all space separators
     * (also no-break and ideographic spaces) become one plain space, runs of spaces collapse, trimmed. An empty result
     * means the text had no visible content.
     */
    public function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x{3164}\x{115F}\x{1160}\x{FFA0}\x{2800}\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\p{Zs}]+/u', ' ', $text);

        return trim((string) preg_replace('/ {2,}/', ' ', $text));
    }

    /**
     * Stores a message of a player after the checks (text, channel, ban, mute, rate limits; for the global channel also the
     * account age / points minimum and the text filter).
     *
     * @param array<string, mixed> $user the player row (id, universe, username, ally_id, bana, chat_muted_until, register_time, total_points)
     * @return array{id: int}|array{error: string, wait?: int} the new message id, or the error code (`slow` also gives the seconds to wait)
     */
    public function send(array $user, string $channelName, string $text, int $now, ?ChatSettings $settings = null): array
    {
        $settings ??= new ChatSettings();
        $text = $this->clean($text);
        if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
            return ['error' => self::ERR_EMPTY];
        }
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        if ($channelName === self::GLOBAL && !$settings->globalEnabled) {
            return ['error' => self::ERR_GLOBAL_OFF];
        }
        $channel = $this->channelFor($user, $channelName, $settings->globalEnabled);
        if ($channel === null) {
            return ['error' => self::ERR_CHANNEL];
        }
        if ((int) ($user['bana'] ?? 0) !== 0) {
            return ['error' => self::ERR_BANNED];
        }
        if ((int) ($user['chat_muted_until'] ?? 0) > $now) {
            return ['error' => self::ERR_MUTED];
        }
        $original = null;
        if ($channel === self::GLOBAL) {
            $failure = $this->globalChecks($user, $settings, $now);
            if ($failure !== null) {
                return ['error' => $failure];
            }
            $filtered = $settings->filter->apply($text, $this->recentTexts((int) $user['universe'], (int) $user['id'], $now));
            if (isset($filtered['error'])) {
                return ['error' => $filtered['error']];
            }
            $original = $filtered['text'] !== $text ? $text : null;
            $text = $filtered['text'];
        }
        $wait = $this->slowModeWait($user, $channel, $settings, $now);
        if ($wait > 0) {
            return ['error' => self::ERR_SLOW, 'wait' => $wait];
        }
        $key = (string) (int) $user['id'];
        if (!$this->gap->create($key)->consume()->isAccepted() || !$this->burst->create($key)->consume()->isAccepted()) {
            return ['error' => self::ERR_RATE];
        }

        $tag = (int) ($user['ally_id'] ?? 0) > 0
            ? $this->db->selectSingle('SELECT `ally_tag` FROM %%ALLIANCE%% WHERE `id` = :id;', [':id' => (int) $user['ally_id']], 'ally_tag')
            : null;
        $this->db->insert('INSERT INTO %%CHAT_MESSAGES%% (`universe`, `channel`, `user_id`, `username`, `ally_tag`, `message`, `original_message`, `created_at`)
            VALUES (:universe, :channel, :user, :username, :tag, :message, :original, :now);', [
            ':universe' => (int) $user['universe'],
            ':channel' => $channel,
            ':user' => (int) $user['id'],
            ':username' => mb_substr((string) $user['username'], 0, 32),
            ':tag' => $tag === null || $tag === false || $tag === '' ? null : (string) $tag,
            ':message' => $text,
            ':original' => $original,
            ':now' => $now,
        ]);

        return ['id' => $this->db->lastInsertId()];
    }

    /**
     * The slow mode that applies to a player in the channels he may read, in seconds between two messages (0 = off or
     * exempt: moderators, the alliance owner in his alliance channel).
     *
     * @param array<string, mixed> $user
     * @return array<string, int> by client channel name
     */
    public function slowModes(array $user, ChatSettings $settings): array
    {
        $modes = [];
        if ($settings->globalEnabled) {
            $modes[self::GLOBAL] = $this->isModerator($user) ? 0 : $settings->globalSlowmode;
        }
        if ((int) ($user['ally_id'] ?? 0) > 0) {
            $alliance = $this->allianceChat((int) $user['ally_id']);
            $exempt = $this->isModerator($user) || (int) ($alliance['ally_owner'] ?? 0) === (int) $user['id'];
            $modes[self::ALLIANCE] = $exempt ? 0 : (int) ($alliance['chat_slowmode'] ?? 0);
        }

        return $modes;
    }

    /**
     * Seconds the sender still has to wait before his next message in the channel (0 = may post). Moderators and, in
     * the alliance channel, the alliance owner are exempt.
     *
     * @param array<string, mixed> $user the player row (id, universe, ally_id, authlevel)
     */
    private function slowModeWait(array $user, string $channel, ChatSettings $settings, int $now): int
    {
        if ($channel === self::GLOBAL) {
            $slow = $settings->globalSlowmode;
        } else {
            $alliance = $this->allianceChat((int) $user['ally_id']);
            $slow = (int) ($alliance['chat_slowmode'] ?? 0);
            if ((int) ($alliance['ally_owner'] ?? 0) === (int) $user['id']) {
                return 0;
            }
        }
        if ($slow <= 0 || $this->isModerator($user)) {
            return 0;
        }
        $last = (int) $this->db->selectSingle('SELECT MAX(`created_at`) AS t FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = :channel AND `user_id` = :user;', [
            ':universe' => (int) $user['universe'], ':channel' => $channel, ':user' => (int) $user['id'],
        ], 't');

        return max(0, $last + $slow - $now);
    }

    /** @return array<string, mixed>|false owner and slow mode of an alliance */
    private function allianceChat(int $allyId): array|false
    {
        return $this->db->selectSingle('SELECT `ally_owner`, `chat_slowmode` FROM %%ALLIANCE%% WHERE `id` = :id;', [':id' => $allyId]);
    }

    /** Account age and points minimum of the global channel: the error code, null when the player may post. */
    private function globalChecks(array $user, ChatSettings $settings, int $now): ?string
    {
        if ($settings->minAccountAgeHours > 0 && (int) ($user['register_time'] ?? 0) > $now - $settings->minAccountAgeHours * 3600) {
            return self::ERR_AGE;
        }
        if ($settings->minPoints > 0 && (float) ($user['total_points'] ?? 0) < $settings->minPoints) {
            return self::ERR_POINTS;
        }

        return null;
    }

    /**
     * The sender's last 3 global messages of the duplicate window, newest first.
     *
     * @return list<string>
     */
    private function recentTexts(int $universe, int $userId, int $now): array
    {
        $rows = $this->db->select('SELECT `message` FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = \'global\' AND `user_id` = :user AND `created_at` >= :since
            ORDER BY `id` DESC LIMIT 3;', [':universe' => $universe, ':user' => $userId, ':since' => $now - self::DUPLICATE_WINDOW]);

        return array_map(static fn(array $row): string => (string) $row['message'], $rows);
    }

    /**
     * Messages of a channel for a reader, without deleted ones and without those of players the reader blocked.
     * `$after` = 0 gives the newest page, otherwise the next page after that id (oldest first).
     *
     * @return list<array{id: int, uid: int, user: string, ally: ?string, text: string, time: int, own: bool}>
     */
    public function messages(int $universe, string $channel, int $readerId, int $after): array
    {
        $order = $after > 0 ? 'ASC' : 'DESC';
        $rows = $this->db->select('SELECT m.`id`, m.`user_id`, m.`username`, m.`ally_tag`, m.`message`, m.`created_at`
            FROM %%CHAT_MESSAGES%% m
            WHERE m.`universe` = :universe AND m.`channel` = :channel AND m.`id` > :after AND m.`deleted` = 0 AND ' . $this->notBlockedSql() . '
            ORDER BY m.`id` ' . $order . ' LIMIT ' . self::PAGE . ';', [
            ':universe' => $universe, ':channel' => $channel, ':after' => $after, ':reader' => $readerId,
        ]);
        if ($after <= 0) {
            $rows = array_reverse($rows);
        }

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'uid' => (int) $row['user_id'],
            'user' => (string) $row['username'],
            'ally' => $row['ally_tag'] === null ? null : (string) $row['ally_tag'],
            'text' => (string) $row['message'],
            'time' => (int) $row['created_at'],
            'own' => (int) $row['user_id'] === $readerId,
        ], $rows);
    }

    /**
     * Ids deleted by a moderator among the messages from `$from` on (the oldest message a client still shows).
     *
     * @return list<int>
     */
    public function deletedSince(int $universe, string $channel, int $from): array
    {
        $rows = $this->db->select('SELECT `id` FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = :channel AND `id` >= :from AND `deleted` = 1
            ORDER BY `id` DESC LIMIT 100;', [':universe' => $universe, ':channel' => $channel, ':from' => $from]);

        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Unread messages (capped, not own, not deleted, not of blocked players) of a channel after the last read id.
     */
    public function unread(int $universe, string $channel, int $readerId, int $after): int
    {
        $count = $this->db->selectSingle('SELECT COUNT(*) AS c FROM (SELECT 1 FROM %%CHAT_MESSAGES%% m
            WHERE m.`universe` = :universe AND m.`channel` = :channel AND m.`id` > :after AND m.`deleted` = 0 AND m.`user_id` != :reader AND ' . $this->notBlockedSql() . '
            LIMIT ' . self::UNREAD_CAP . ') t;', [
            ':universe' => $universe, ':channel' => $channel, ':after' => $after, ':reader' => $readerId,
        ], 'c');

        return (int) $count;
    }

    /**
     * Highest message id of several channels with one query (0 for an empty channel): the cheap check of a poll
     * before it runs the message, deleted and unread queries.
     *
     * @param list<string> $channels
     * @return array<string, int> by channel name
     */
    public function latestIds(int $universe, array $channels): array
    {
        $ids = array_fill_keys($channels, 0);
        if ($channels === []) {
            return $ids;
        }
        $params = [':universe' => $universe];
        $marks = [];
        foreach (array_values($channels) as $i => $channel) {
            $marks[] = ':c' . $i;
            $params[':c' . $i] = $channel;
        }
        $rows = $this->db->select('SELECT `channel`, MAX(`id`) AS i FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` IN (' . implode(',', $marks) . ') GROUP BY `channel`;', $params);
        foreach ($rows as $row) {
            $ids[(string) $row['channel']] = (int) $row['i'];
        }

        return $ids;
    }

    /** Highest message id of a channel (0 when empty): the starting point of a client that never read it. */
    public function latestId(int $universe, string $channel): int
    {
        return (int) $this->db->selectSingle('SELECT MAX(`id`) AS i FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = :channel;', [
            ':universe' => $universe, ':channel' => $channel,
        ], 'i');
    }

    /** Marks a message of the universe deleted by a moderator (it vanishes for everyone on their next poll, open reports are resolved); false when it does not exist. */
    public function delete(int $universe, int $id, int $moderatorId = 0, int $now = 0): bool
    {
        return (new ChatModeration($this->db))->delete($universe, $id, $moderatorId, $now > 0 ? $now : time());
    }

    /**
     * Mutes the author of a message until `$now + $hours` hours (0 = lift the mute). Staff cannot be muted.
     *
     * @return bool false when the message does not exist or its author is staff
     */
    public function muteAuthor(int $universe, int $messageId, int $hours, int $now): bool
    {
        $author = $this->db->selectSingle('SELECT m.`user_id` FROM %%CHAT_MESSAGES%% m INNER JOIN %%USERS%% u ON u.`id` = m.`user_id`
            WHERE m.`id` = :id AND m.`universe` = :universe AND u.`authlevel` = :level;', [
            ':id' => $messageId, ':universe' => $universe, ':level' => AuthLevel::User->value,
        ], 'user_id');
        if (!$author) {
            return false;
        }
        $this->db->update('UPDATE %%USERS%% SET `chat_muted_until` = :until WHERE `id` = :id;', [
            ':until' => $hours > 0 ? $now + $hours * 3600 : 0, ':id' => (int) $author,
        ]);

        return true;
    }

    /**
     * Whether a player may see a message (and so report or block its author): same universe, not deleted, a channel he may read.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null the message row (id, universe, channel, user_id) or null
     */
    private function visibleMessage(array $user, int $messageId, bool $globalEnabled): ?array
    {
        $row = $this->db->selectSingle('SELECT `id`, `universe`, `channel`, `user_id` FROM %%CHAT_MESSAGES%% WHERE `id` = :id AND `deleted` = 0;', [':id' => $messageId]);
        if (!$row || (int) $row['universe'] !== (int) $user['universe']) {
            return null;
        }
        $allowed = $row['channel'] === self::GLOBAL
            ? $globalEnabled
            : $row['channel'] === 'ally:' . (int) ($user['ally_id'] ?? 0) && (int) ($user['ally_id'] ?? 0) > 0;

        return $allowed ? $row : null;
    }

    /**
     * A player reports a message he can see (not his own), once per message, at most 5 reports per 10 minutes.
     *
     * @param array<string, mixed> $user
     * @return array{ok: true}|array{error: string}
     */
    public function report(array $user, int $messageId, string $reason, string $comment, int $now, bool $globalEnabled = true): array
    {
        $message = $this->visibleMessage($user, $messageId, $globalEnabled);
        if ($message === null || !in_array($reason, self::REASONS, true)) {
            return ['error' => self::ERR_UNKNOWN];
        }
        if ((int) $message['user_id'] === (int) $user['id']) {
            return ['error' => self::ERR_SELF];
        }
        $known = $this->db->selectSingle('SELECT `id` FROM %%CHAT_REPORTS%% WHERE `message_id` = :m AND `reporter_id` = :r;', [':m' => $messageId, ':r' => (int) $user['id']], 'id');
        if ($known) {
            return ['error' => self::ERR_DUPLICATE_REPORT];
        }
        $open = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%CHAT_REPORTS%% WHERE `reporter_id` = :r AND `status` = \'open\';', [':r' => (int) $user['id']], 'c');
        if ($open >= self::MAX_OPEN_REPORTS) {
            return ['error' => self::ERR_REPORT_LIMIT];
        }
        if (!$this->reports->create((string) (int) $user['id'])->consume()->isAccepted()) {
            return ['error' => self::ERR_RATE];
        }
        $this->db->insert('INSERT IGNORE INTO %%CHAT_REPORTS%% (`message_id`, `reporter_id`, `reason`, `comment`, `created_at`) VALUES (:m, :r, :reason, :comment, :now);', [
            ':m' => $messageId, ':r' => (int) $user['id'], ':reason' => $reason, ':comment' => mb_substr($this->clean($comment), 0, self::REPORT_COMMENT_MAX), ':now' => $now,
        ]);

        return ['ok' => true];
    }

    /**
     * A player blocks the author of a message he can see (direct messages and chat); false = not possible.
     *
     * @param array<string, mixed> $user
     * @return array{ok: true}|array{error: string}
     */
    public function blockAuthor(array $user, int $messageId, bool $globalEnabled = true): array
    {
        $message = $this->visibleMessage($user, $messageId, $globalEnabled);
        if ($message === null) {
            return ['error' => self::ERR_UNKNOWN];
        }
        $author = (int) $message['user_id'];
        if ($author === (int) $user['id']) {
            return ['error' => self::ERR_SELF];
        }
        $row = $this->db->selectSingle('SELECT `id` FROM %%USERS_BLOCKLIST%% WHERE `blocking_user` = :me AND `blocked_user` = :them;', [':me' => (int) $user['id'], ':them' => $author], 'id');
        if ($row) {
            $this->db->update('UPDATE %%USERS_BLOCKLIST%% SET `block_dm` = 1 WHERE `id` = :id;', [':id' => $row]);

            return ['ok' => true];
        }
        $count = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_BLOCKLIST%% WHERE `blocking_user` = :me;', [':me' => (int) $user['id']], 'c');
        if ($count >= self::MAX_BLOCKS) {
            return ['error' => self::ERR_LIMIT];
        }
        $this->db->insert('INSERT INTO %%USERS_BLOCKLIST%% (`blocking_user`, `blocked_user`, `block_dm`, `block_trade`) VALUES (:me, :them, 1, 0);', [':me' => (int) $user['id'], ':them' => $author]);

        return ['ok' => true];
    }

    /** SQL condition: the author of row `m` is not blocked (direct messages) by the reader (:reader) */
    private function notBlockedSql(): string
    {
        return 'NOT EXISTS (SELECT 1 FROM %%USERS_BLOCKLIST%% b WHERE b.`blocking_user` = :reader AND b.`blocked_user` = m.`user_id` AND b.`block_dm` = 1)';
    }
}
