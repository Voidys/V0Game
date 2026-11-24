<?php

declare(strict_types=1);

namespace App\Game\Engagement\Cosmetics;

/**
 * Cosmetic rewards (table eng_cosmetics): badges, titles and name colours earned from seasons, contracts and alliance
 * goals. They never change game values. One row per user and kind/code; the chosen title and colour are flagged
 * `active` (at most one per kind).
 */
final class Cosmetics
{
    public const KIND_BADGE = 'badge';
    public const KIND_TITLE = 'title';
    public const KIND_COLOR = 'color';

    public function __construct(
        private \App\Game\Core\Database $db,
    ) {}

    /**
     * Gives a user a cosmetic once (a second grant of the same kind/code is ignored).
     *
     * @param string $kind one of the KIND_* constants
     * @param string $code short identifier, [a-z0-9_]{1,48} (e.g. "season3_raider_1", "contract_streak_4")
     * @param string $source where it came from, shown in the tooltip (e.g. "season:3", "contracts", "alliance_goal")
     * @return bool true when it was new
     */
    public function grant(int $userId, string $kind, string $code, string $source): bool
    {
        if ($userId <= 0 || !in_array($kind, [self::KIND_BADGE, self::KIND_TITLE, self::KIND_COLOR], true) || preg_match('/^[a-z0-9_]{1,48}$/', $code) !== 1) {
            return false;
        }

        return $this->db->insert(
            'INSERT IGNORE INTO %%ENG_COSMETICS%% (`user_id`, `kind`, `code`, `source`, `granted_at`, `active`) VALUES (:userId, :kind, :code, :source, UNIX_TIMESTAMP(), 0);',
            [':userId' => $userId, ':kind' => $kind, ':code' => $code, ':source' => mb_substr($source, 0, 64)],
        ) && $this->db->rowCount() > 0;
    }

    /**
     * Everything a user owns, newest first.
     *
     * @return list<array{kind: string, code: string, source: string, granted_at: int, active: bool}>
     */
    public function owned(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT `kind`, `code`, `source`, `granted_at`, `active` FROM %%ENG_COSMETICS%% WHERE `user_id` = :userId ORDER BY `granted_at` DESC, `id` DESC;',
            [':userId' => $userId],
        );

        return array_map(static fn(array $row): array => [
            'kind' => (string) $row['kind'],
            'code' => (string) $row['code'],
            'source' => (string) $row['source'],
            'granted_at' => (int) $row['granted_at'],
            'active' => (int) $row['active'] === 1,
        ], $rows);
    }

    /**
     * Makes one owned title or colour the active one (the others of that kind are cleared), or clears the kind with a null code.
     * Runs in one transaction; the ownership is checked under the row lock.
     *
     * @return bool false when the kind is not choosable or the user does not own the code
     */
    public function choose(int $userId, string $kind, ?string $code): bool
    {
        if ($userId <= 0 || !in_array($kind, [self::KIND_TITLE, self::KIND_COLOR], true)) {
            return false;
        }

        return (bool) $this->db->transactional(function () use ($userId, $kind, $code): bool {
            $this->db->select(
                'SELECT `id` FROM %%ENG_COSMETICS%% WHERE `user_id` = :userId AND `kind` = :kind FOR UPDATE;',
                [':userId' => $userId, ':kind' => $kind],
            );
            if ($code !== null) {
                $owned = $this->db->selectSingle(
                    'SELECT `id` FROM %%ENG_COSMETICS%% WHERE `user_id` = :userId AND `kind` = :kind AND `code` = :code;',
                    [':userId' => $userId, ':kind' => $kind, ':code' => $code],
                    'id',
                );
                if (!$owned) {
                    return false;
                }
            }
            $this->db->update(
                'UPDATE %%ENG_COSMETICS%% SET `active` = 0 WHERE `user_id` = :userId AND `kind` = :kind;',
                [':userId' => $userId, ':kind' => $kind],
            );
            if ($code !== null) {
                $this->db->update(
                    'UPDATE %%ENG_COSMETICS%% SET `active` = 1 WHERE `user_id` = :userId AND `kind` = :kind AND `code` = :code;',
                    [':userId' => $userId, ':kind' => $kind, ':code' => $code],
                );
            }

            return true;
        });
    }

    /**
     * The active title and colour code of several users (for names in lists).
     *
     * @param list<int> $userIds
     * @return array<int, array{title: ?string, color: ?string}>
     */
    public function activeFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT `user_id`, `kind`, `code` FROM %%ENG_COSMETICS%% WHERE `active` = 1 AND `kind` IN (\'title\', \'color\') AND `user_id` IN (' . implode(',', $userIds) . ');',
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] ??= ['title' => null, 'color' => null];
            $result[(int) $row['user_id']][$row['kind']] = (string) $row['code'];
        }

        return $result;
    }
}
