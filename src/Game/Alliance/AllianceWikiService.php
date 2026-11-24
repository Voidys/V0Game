<?php

declare(strict_types=1);

namespace App\Game\Alliance;

use App\Game\Achievements\AchievementGuard;
use App\Game\Achievements\MiscAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * The notes pages of an alliance (table alliance_pages): readable by every member, edited by ranks with the WIKI right.
 * Titles and bodies are stored as submitted (already HTML-encoded by the request input) and rendered through
 * AllianceText::multiline().
 */
class AllianceWikiService
{
    public const MAX_PAGES = 30;
    public const TITLE_LENGTH = 100;
    public const BODY_LENGTH = 20000;

    /** Minimum page text length for a created page to count towards the Chronicler achievement (91). */
    private const MIN_ACHIEVEMENT_BODY = 200;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** @return list<array<string, mixed>> id, title, updated_at, editor */
    public function pages(int $allianceId): array
    {
        return $this->db->select(
            'SELECT p.id, p.title, p.updated_at, u.username AS editor FROM %%ALLIANCE_PAGES%% p
            LEFT JOIN %%USERS%% u ON u.id = p.updated_by
            WHERE p.alliance_id = :id ORDER BY p.title ASC;',
            [':id' => $allianceId],
        );
    }

    /** @return array<string, mixed>|null the page (id, title, body, updated_at, editor) of this alliance */
    public function page(int $allianceId, int $pageId): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT p.id, p.title, p.body, p.updated_at, u.username AS editor FROM %%ALLIANCE_PAGES%% p
            LEFT JOIN %%USERS%% u ON u.id = p.updated_by
            WHERE p.alliance_id = :id AND p.id = :page;',
            [':id' => $allianceId, ':page' => $pageId],
        );

        return $row ?: null;
    }

    /**
     * Creates (`$pageId` = 0) or updates a page.
     *
     * @return int the page id, 0 when title/body are empty or too long, -1 when the alliance has too many pages
     */
    public function save(int $allianceId, int $userId, int $pageId, string $title, string $body): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > self::TITLE_LENGTH || mb_strlen($body) > self::BODY_LENGTH) {
            return 0;
        }

        $now = $this->state->runtime->timestamp;
        if ($pageId > 0) {
            if ($this->page($allianceId, $pageId) === null) {
                return 0;
            }
            $this->db->update(
                'UPDATE %%ALLIANCE_PAGES%% SET title = :title, body = :body, updated_by = :user, updated_at = :now WHERE id = :id AND alliance_id = :alliance;',
                [':title' => $title, ':body' => $body, ':user' => $userId, ':now' => $now, ':id' => $pageId, ':alliance' => $allianceId],
            );

            return $pageId;
        }

        $count = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_PAGES%% WHERE alliance_id = :id;', [':id' => $allianceId], 'c');
        if ($count >= self::MAX_PAGES) {
            return -1;
        }

        $this->db->insert(
            'INSERT INTO %%ALLIANCE_PAGES%% SET alliance_id = :alliance, title = :title, body = :body, updated_by = :user, updated_at = :now;',
            [':alliance' => $allianceId, ':title' => $title, ':body' => $body, ':user' => $userId, ':now' => $now],
        );

        $pageId = (int) $this->db->lastInsertId();
        // Chronicler counts only real pages, not one-letter stubs
        if (mb_strlen(trim($body)) >= self::MIN_ACHIEVEMENT_BODY) {
            (new AdvancedStatsHandler($this->db, $this->configs, $this->state))->increment($userId, AdvancedStatsEnum::ALLIANCE_PAGES_CREATED);
            (new AchievementGuard($this->db, $this->configs, $this->state))->run(
                fn() => (new MiscAchievement($this->db, $this->configs, $this->state))->checkWikiAchievements($userId),
            );
        }

        return $pageId;
    }

    /** Deletes a page of the alliance. */
    public function delete(int $allianceId, int $pageId): void
    {
        $this->db->delete(
            'DELETE FROM %%ALLIANCE_PAGES%% WHERE id = :id AND alliance_id = :alliance;',
            [':id' => $pageId, ':alliance' => $allianceId],
        );
    }
}
