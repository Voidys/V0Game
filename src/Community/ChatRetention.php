<?php

declare(strict_types=1);

namespace App\Community;

use App\Game\Core\Database;

/**
 * Retention of the in-game chat (table CHAT_MESSAGES), run by the daily cronjob: old messages and the overflow of busy channels go.
 */
final class ChatRetention
{
    /** messages older than this many days are removed */
    public const RETENTION_DAYS = 7;
    /** newest messages kept per channel */
    public const CHANNEL_CAP = 2000;
    /** open reports are dismissed after this many days, handled ones are deleted */
    public const REPORT_DAYS = 30;

    public function __construct(private readonly Database $db) {}

    /**
     * Retention: removes messages older than self::RETENTION_DAYS and keeps only the newest self::CHANNEL_CAP per channel; messages with an open report are kept.
     *
     * @return int number of channels that were over the cap (rows removed by age are not counted)
     */
    public function purge(int $now): int
    {
        // reports do not pin messages forever: old open ones are dismissed (nobody handled them), handled ones are deleted
        $reportCutoff = $now - self::REPORT_DAYS * 86400;
        $this->db->update('UPDATE %%CHAT_REPORTS%% SET `status` = \'dismissed\', `handled_by` = NULL, `handled_at` = :now WHERE `status` = \'open\' AND `created_at` < :time;', [':now' => $now, ':time' => $reportCutoff]);
        $this->db->delete('DELETE FROM %%CHAT_REPORTS%% WHERE `status` != \'open\' AND COALESCE(`handled_at`, `created_at`) < :time;', [':time' => $reportCutoff]);

        // a message with an open report stays until an admin handled the report
        $this->db->delete('DELETE FROM %%CHAT_MESSAGES%% WHERE `created_at` < :time AND `id` NOT IN (SELECT `message_id` FROM %%CHAT_REPORTS%% WHERE `status` = \'open\');', [':time' => $now - self::RETENTION_DAYS * 86400]);

        $trimmed = 0;
        $channels = $this->db->select('SELECT `universe`, `channel` FROM %%CHAT_MESSAGES%% GROUP BY `universe`, `channel` HAVING COUNT(*) > ' . self::CHANNEL_CAP . ';');
        foreach ($channels as $row) {
            $limitId = $this->db->selectSingle('SELECT `id` FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = :channel
                ORDER BY `id` DESC LIMIT 1 OFFSET ' . self::CHANNEL_CAP . ';', [':universe' => $row['universe'], ':channel' => $row['channel']], 'id');
            if ($limitId) {
                $this->db->delete('DELETE FROM %%CHAT_MESSAGES%% WHERE `universe` = :universe AND `channel` = :channel AND `id` <= :id
                    AND `id` NOT IN (SELECT `message_id` FROM %%CHAT_REPORTS%% WHERE `status` = \'open\');', [
                    ':universe' => $row['universe'], ':channel' => $row['channel'], ':id' => $limitId,
                ]);
                $trimmed++;
            }
        }

        // reports of messages that are gone
        $this->db->delete('DELETE r FROM %%CHAT_REPORTS%% r LEFT JOIN %%CHAT_MESSAGES%% m ON m.`id` = r.`message_id` WHERE m.`id` IS NULL;');

        return $trimmed;
    }
}
