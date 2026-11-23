<?php

declare(strict_types=1);

namespace App\Api;

use App\Game\Core\Database;

/**
 * Personal API tokens of the read-only player API (table API_TOKENS): creation (the plaintext is only returned once,
 * only its SHA-256 is stored), listing, revoking and the authentication of a bearer token with its scope.
 */
final class ApiTokens
{
    public const SCOPE_REPORTS = 'reports';
    public const SCOPE_EMPIRE = 'empire';
    public const SCOPES = [self::SCOPE_REPORTS, self::SCOPE_EMPIRE];

    public const PREFIX = 'v0_';
    public const MAX_ACTIVE = 5;
    public const NAME_LENGTH = 40;

    /** last_used_at is written at most once per this many seconds */
    public const TOUCH_INTERVAL = 60;

    public function __construct(private readonly Database $db) {}

    /** Hash under which a plaintext token is stored. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a token for a player. Returns the plaintext (shown once) or null when the player already has MAX_ACTIVE active tokens.
     *
     * @param list<string> $scopes any of SCOPES (unknown values are dropped)
     */
    public function create(int $userId, int $universe, string $name, array $scopes, int $now): ?string
    {
        if ($this->countActive($userId) >= self::MAX_ACTIVE) {
            return null;
        }
        $name = mb_substr(trim($name), 0, self::NAME_LENGTH);
        $scopes = array_values(array_intersect(self::SCOPES, $scopes));
        $token = self::PREFIX . bin2hex(random_bytes(32));

        $this->db->insert(
            'INSERT INTO %%API_TOKENS%% SET `user_id` = :user, `universe` = :uni, `name` = :name, `token_hash` = :hash, `scopes` = :scopes, `created_at` = :now;',
            [':user' => $userId, ':uni' => $universe, ':name' => $name, ':hash' => self::hash($token), ':scopes' => implode(',', $scopes), ':now' => $now],
        );

        return $token;
    }

    public function countActive(int $userId): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%API_TOKENS%% WHERE `user_id` = :user AND `revoked` = 0;', [':user' => $userId], 'c');
    }

    /** @return list<array<string, mixed>> the active tokens of a player, newest first (never the hash) */
    public function listFor(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `name`, `scopes`, `created_at`, `last_used_at` FROM %%API_TOKENS%% WHERE `user_id` = :user AND `revoked` = 0 ORDER BY `id` DESC;',
            [':user' => $userId],
        );
        foreach ($rows as &$row) {
            $row['scopes'] = $row['scopes'] === '' ? [] : explode(',', (string) $row['scopes']);
        }

        return $rows;
    }

    /** Revokes one token of the player; false when it is not his or already revoked. */
    public function revoke(int $userId, int $tokenId): bool
    {
        return $this->db->updateRows('API_TOKENS', ['revoked' => 1], ['id' => $tokenId, 'user_id' => $userId, 'revoked' => 0]) > 0;
    }

    /**
     * Looks up a plaintext token. Returns the token row (id, user_id, universe, scopes as list) or null when it is unknown or revoked.
     *
     * @return array{id: int, user_id: int, universe: int, scopes: list<string>}|null
     */
    public function find(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 100) {
            return null;
        }
        $row = $this->db->selectSingle(
            'SELECT `id`, `user_id`, `universe`, `scopes`, `last_used_at` FROM %%API_TOKENS%% WHERE `token_hash` = :hash AND `revoked` = 0;',
            [':hash' => self::hash($token)],
        );
        if (empty($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'universe' => (int) $row['universe'],
            'scopes' => $row['scopes'] === '' ? [] : explode(',', (string) $row['scopes']),
            'last_used_at' => (int) $row['last_used_at'],
        ];
    }

    /** Records the use of a token, at most once per TOUCH_INTERVAL. */
    public function touch(int $tokenId, int $now): void
    {
        $this->db->update(
            'UPDATE %%API_TOKENS%% SET `last_used_at` = :now WHERE `id` = :id AND `last_used_at` <= :before;',
            [':now' => $now, ':id' => $tokenId, ':before' => $now - self::TOUCH_INTERVAL],
        );
    }

    /**
     * Is the player of a token banned (his own ban or the ban of his account)? Bans that ran out do not count.
     */
    public function isBanned(int $userId, int $now): bool
    {
        $row = $this->db->selectSingle(
            'SELECT u.`bana`, u.`banaday`,
                EXISTS(SELECT 1 FROM %%ACCOUNTS_TO_USERS%% atu INNER JOIN %%ACCOUNTS%% a ON a.`id` = atu.`account_id`
                    WHERE atu.`user_id` = u.`id` AND atu.`end_time` = 0 AND a.`bana` = 1 AND a.`banaday` > :now) AS account_banned
            FROM %%USERS%% u WHERE u.`id` = :id;',
            [':id' => $userId, ':now' => $now],
        );
        if (empty($row)) {
            return true;
        }

        return ((int) $row['bana'] === 1 && (int) $row['banaday'] > $now) || (int) $row['account_banned'] === 1;
    }
}
