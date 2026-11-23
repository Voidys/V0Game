<?php

declare(strict_types=1);

namespace App\Game\Admin\Chat;

use App\Game\Core\Database;

/**
 * Chat moderation queries (tables CHAT_MESSAGES, CHAT_REPORTS): deleting and restoring messages, the filtered history of a
 * channel for the admin page, and the report queue with context. Used by the admin page /admin/chat and by the in-game
 * moderator delete.
 */
final class ChatModeration
{
    public const PAGE = 100;
    /** messages shown before and after a reported message */
    public const CONTEXT = 5;
    /** reported messages per page of the report queue */
    public const MAX_REPORTED = 20;

    public function __construct(private readonly Database $db) {}

    /**
     * Marks a message deleted by the moderator and resolves its open reports.
     *
     * @return bool false when the message does not exist in the universe
     */
    public function delete(int $universe, int $id, int $moderatorId, int $now): bool
    {
        if (!$this->exists($universe, $id)) {
            return false;
        }
        $this->db->update('UPDATE %%CHAT_MESSAGES%% SET `deleted` = 1, `deleted_by` = :by, `deleted_at` = :now WHERE `id` = :id;', [':by' => $moderatorId, ':now' => $now, ':id' => $id]);
        $this->closeReports($id, 'resolved', $moderatorId, $now);

        return true;
    }

    /** Brings a deleted message back; false when it does not exist in the universe. */
    public function restore(int $universe, int $id): bool
    {
        if (!$this->exists($universe, $id)) {
            return false;
        }
        $this->db->update('UPDATE %%CHAT_MESSAGES%% SET `deleted` = 0, `deleted_by` = NULL, `deleted_at` = NULL WHERE `id` = :id;', [':id' => $id]);

        return true;
    }

    /** Dismisses the open reports of a message; false when it does not exist in the universe. */
    public function dismiss(int $universe, int $id, int $moderatorId, int $now): bool
    {
        if (!$this->exists($universe, $id)) {
            return false;
        }
        $this->closeReports($id, 'dismissed', $moderatorId, $now);

        return true;
    }

    /**
     * History of a channel, newest first, with deleted messages. Filters: `name` (player name contains), `text` (message or
     * original text contains), `from` / `to` (timestamps, inclusive), `deleted` (only deleted ones). Keyset pagination: pass the last
     * id of the previous page as `$before`.
     *
     * @param array{name?: string, text?: string, from?: int, to?: int, deleted?: bool} $filters
     * @return array{rows: list<array<string, mixed>>, next: int|null} `next` = the `$before` of the following page, null on the last page
     */
    public function history(int $universe, string $channel, array $filters, int $before = 0): array
    {
        $where = ['m.`universe` = :universe', 'm.`channel` = :channel'];
        $params = [':universe' => $universe, ':channel' => $channel];
        if ($before > 0) {
            $where[] = 'm.`id` < :before';
            $params[':before'] = $before;
        }
        if (($filters['name'] ?? '') !== '') {
            $where[] = 'm.`username` LIKE :name';
            $params[':name'] = '%' . self::like($filters['name']) . '%';
        }
        if (($filters['text'] ?? '') !== '') {
            $where[] = '(m.`message` LIKE :text OR m.`original_message` LIKE :text2)';
            $params[':text'] = $params[':text2'] = '%' . self::like($filters['text']) . '%';
        }
        if (($filters['from'] ?? 0) > 0) {
            $where[] = 'm.`created_at` >= :from';
            $params[':from'] = (int) $filters['from'];
        }
        if (($filters['to'] ?? 0) > 0) {
            $where[] = 'm.`created_at` <= :to';
            $params[':to'] = (int) $filters['to'];
        }
        if (!empty($filters['deleted'])) {
            $where[] = 'm.`deleted` = 1';
        }
        $rows = $this->db->select('SELECT m.*, d.`username` AS deleted_by_name FROM %%CHAT_MESSAGES%% m LEFT JOIN %%USERS%% d ON d.`id` = m.`deleted_by`
            WHERE ' . implode(' AND ', $where) . ' ORDER BY m.`id` DESC LIMIT ' . (self::PAGE + 1) . ';', $params);
        $next = null;
        if (count($rows) > self::PAGE) {
            $rows = array_slice($rows, 0, self::PAGE);
            $next = (int) $rows[self::PAGE - 1]['id'];
        }

        return ['rows' => $rows, 'next' => $next];
    }

    /** Number of messages with an open report in the universe (badge of the "open reports" filter). */
    public function openReportCount(int $universe): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(DISTINCT r.`message_id`) AS c FROM %%CHAT_REPORTS%% r INNER JOIN %%CHAT_MESSAGES%% m ON m.`id` = r.`message_id`
            WHERE r.`status` = \'open\' AND m.`universe` = :universe;', [':universe' => $universe], 'c');
    }

    /**
     * Reported messages with open reports, the one waiting longest first (by the id of its first open report, so a flood of
     * reports on one message cannot bury another), PAGE_REPORTED per page (keyset: `$after` = the `first` of the last item
     * of the previous page). Each item has its reports (reason, comment, reporter), the report count and the CONTEXT messages
     * before and after it in the same channel.
     *
     * @return array{items: list<array{first: int, count: int, message: array<string, mixed>, reports: list<array<string, mixed>>, before: list<array<string, mixed>>, after: list<array<string, mixed>>}>, next: int|null}
     */
    public function reported(int $universe, int $after = 0): array
    {
        $heads = $this->db->select('SELECT r.`message_id`, MIN(r.`id`) AS first, COUNT(*) AS cnt FROM %%CHAT_REPORTS%% r
            INNER JOIN %%CHAT_MESSAGES%% m ON m.`id` = r.`message_id`
            WHERE r.`status` = \'open\' AND m.`universe` = :universe
            GROUP BY r.`message_id` HAVING MIN(r.`id`) > :after ORDER BY first ASC LIMIT ' . (self::MAX_REPORTED + 1) . ';', [':universe' => $universe, ':after' => $after]);
        $next = null;
        if (count($heads) > self::MAX_REPORTED) {
            $heads = array_slice($heads, 0, self::MAX_REPORTED);
            $next = (int) $heads[self::MAX_REPORTED - 1]['first'];
        }
        if ($heads === []) {
            return ['items' => [], 'next' => null];
        }

        $ids = array_map(static fn(array $head): int => (int) $head['message_id'], $heads);
        $reports = $this->db->select('SELECT r.*, u.`username` AS reporter FROM %%CHAT_REPORTS%% r LEFT JOIN %%USERS%% u ON u.`id` = r.`reporter_id`
            WHERE r.`status` = \'open\' AND r.`message_id` IN (' . implode(', ', $ids) . ') ORDER BY r.`id` ASC;');
        $byMessage = [];
        foreach ($reports as $report) {
            $byMessage[(int) $report['message_id']][] = $report;
        }

        $messages = [];
        foreach ($this->db->select('SELECT m.*, d.`username` AS deleted_by_name FROM %%CHAT_MESSAGES%% m LEFT JOIN %%USERS%% d ON d.`id` = m.`deleted_by`
            WHERE m.`id` IN (' . implode(', ', $ids) . ');') as $message) {
            $messages[(int) $message['id']] = $message;
        }
        $context = $this->neighbourContext($messages);

        $items = [];
        foreach ($heads as $head) {
            $id = (int) $head['message_id'];
            if (!isset($messages[$id])) {
                continue;
            }
            $items[] = [
                'first' => (int) $head['first'],
                'count' => (int) $head['cnt'],
                'message' => $messages[$id],
                'reports' => $byMessage[$id] ?? [],
                'before' => array_reverse($context[$id]['before'] ?? []),
                'after' => $context[$id]['after'] ?? [],
            ];
        }

        return ['items' => $items, 'next' => $next];
    }

    /** @return bool true when the message exists in the universe */
    private function exists(int $universe, int $id): bool
    {
        return (bool) $this->db->selectSingle('SELECT `id` FROM %%CHAT_MESSAGES%% WHERE `id` = :id AND `universe` = :universe;', [':id' => $id, ':universe' => $universe], 'id');
    }

    private function closeReports(int $messageId, string $status, int $moderatorId, int $now): void
    {
        $this->db->update('UPDATE %%CHAT_REPORTS%% SET `status` = :status, `handled_by` = :by, `handled_at` = :now WHERE `message_id` = :id AND `status` = \'open\';', [
            ':status' => $status, ':by' => $moderatorId, ':now' => $now, ':id' => $messageId,
        ]);
    }

    /**
     * The messages before (newest first) and after (oldest first) each of the given messages in its channel, all
     * loaded with one UNION query.
     *
     * @param array<int, array<string, mixed>> $messages message rows by id
     * @return array<int, array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}>
     */
    private function neighbourContext(array $messages): array
    {
        $parts = [];
        $params = [];
        $n = 0;
        foreach ($messages as $id => $message) {
            foreach (['before' => ['<', 'DESC'], 'after' => ['>', 'ASC']] as $side => [$operator, $order]) {
                $parts[] = '(SELECT ' . $id . ' AS `ctx_id`, \'' . $side . '\' AS `ctx_side`, `id`, `username`, `ally_tag`, `message`, `created_at`, `deleted` FROM %%CHAT_MESSAGES%%
                    WHERE `universe` = :u' . $n . ' AND `channel` = :c' . $n . ' AND `id` ' . $operator . ' ' . $id . ' ORDER BY `id` ' . $order . ' LIMIT ' . self::CONTEXT . ')';
                $params[':u' . $n] = (int) $message['universe'];
                $params[':c' . $n] = (string) $message['channel'];
                $n++;
            }
        }
        $context = [];
        if ($parts === []) {
            return $context;
        }
        foreach ($this->db->select('SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') t ORDER BY `ctx_id`, `id`;', $params) as $row) {
            $key = (int) $row['ctx_id'];
            $side = (string) $row['ctx_side'];
            unset($row['ctx_id'], $row['ctx_side']);
            $context[$key][$side][] = $row;
        }
        // rows come by ascending id: the messages before are wanted newest first
        foreach ($context as &$sides) {
            if (isset($sides['before'])) {
                $sides['before'] = array_reverse($sides['before']);
            }
        }
        unset($sides);

        return $context;
    }

    /** Escapes LIKE wildcards of a search text. */
    private static function like(string $text): string
    {
        return addcslashes($text, '\\%_');
    }
}
