<?php

declare(strict_types=1);

namespace App\Fleet;

use App\Game\Core\Database;

/** Saved ship selections of a player (table FLEET_TEMPLATES), at most MAX_PER_USER each. */
final class FleetTemplateStore
{
    public const MAX_PER_USER = 20;
    public const MAX_NAME_LENGTH = 40;

    public function __construct(private readonly Database $db) {}

    /**
     * Templates of the player, newest last.
     *
     * @return list<array{id: int, name: string, ships: string}>
     */
    public function all(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `name`, `ships` FROM %%FLEET_TEMPLATES%% WHERE `user_id` = :user ORDER BY `id` ASC;',
            [':user' => $userId],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'ships' => (string) $row['ships'],
        ], $rows);
    }

    /** A usable template name: trimmed, 1 to MAX_NAME_LENGTH characters, null when empty. */
    public static function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        return $name === '' ? null : $name;
    }

    /**
     * Stores a template unless the player already has MAX_PER_USER (the check and the insert are one statement).
     * Returns the new id, 0 when the limit is reached.
     *
     * @param string $ships validated ship list text (ShipPreset::format())
     */
    public function create(int $userId, string $name, string $ships, int $time): int
    {
        $this->db->insert(
            'INSERT INTO %%FLEET_TEMPLATES%% (`user_id`, `name`, `ships`, `created_at`)
             SELECT :user, :name, :ships, :time FROM DUAL
             WHERE (SELECT COUNT(*) FROM %%FLEET_TEMPLATES%% WHERE `user_id` = :user2) < ' . self::MAX_PER_USER . ';',
            [':user' => $userId, ':user2' => $userId, ':name' => $name, ':ships' => $ships, ':time' => $time],
        );

        return $this->db->rowCount() > 0 ? $this->db->lastInsertId() : 0;
    }

    /** Deletes a template of the player; false when it does not exist or belongs to someone else. */
    public function delete(int $userId, int $id): bool
    {
        $this->db->delete('DELETE FROM %%FLEET_TEMPLATES%% WHERE `id` = :id AND `user_id` = :user;', [':id' => $id, ':user' => $userId]);

        return $this->db->rowCount() > 0;
    }
}
