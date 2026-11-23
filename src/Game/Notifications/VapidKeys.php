<?php

namespace App\Game\Notifications;

use App\Game\Core\Database;
use Minishlink\WebPush\VAPID;

/**
 * The VAPID key pair that identifies this game server to the push services. It is universe independent: generated
 * on first use and stored as the two rows `push_vapid_public_key` / `push_vapid_private_key` of the global `config`
 * table (not part of the admin config schema, so the private key is never shown or overwritten by a form).
 */
class VapidKeys
{
    public const PUBLIC_KEY = 'push_vapid_public_key';
    public const PRIVATE_KEY = 'push_vapid_private_key';

    public function __construct(private readonly Database $db) {}

    /**
     * The key pair, created and stored when none exists yet (INSERT IGNORE: parallel first requests agree on one pair).
     *
     * @return array{publicKey: string, privateKey: string} base64url encoded
     */
    public function get(): array
    {
        $pair = $this->read();
        if ($pair !== null) {
            return $pair;
        }

        $created = VAPID::createVapidKeys();
        $sql = 'INSERT IGNORE INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value);';
        $this->db->insert($sql, [':key' => self::PUBLIC_KEY, ':value' => $created['publicKey']]);
        $this->db->insert($sql, [':key' => self::PRIVATE_KEY, ':value' => $created['privateKey']]);

        return $this->read() ?? $created;
    }

    /** The public key the browser needs for pushManager.subscribe(). */
    public function publicKey(): string
    {
        return $this->get()['publicKey'];
    }

    /** @return array{publicKey: string, privateKey: string}|null null while one of the rows is missing or empty */
    private function read(): ?array
    {
        $rows = $this->db->select('SELECT `key`, `value` FROM %%CONFIG%% WHERE `key` IN (:pub, :priv);', [
            ':pub' => self::PUBLIC_KEY,
            ':priv' => self::PRIVATE_KEY,
        ]);
        $values = array_column($rows, 'value', 'key');
        if (empty($values[self::PUBLIC_KEY]) || empty($values[self::PRIVATE_KEY])) {
            return null;
        }

        return ['publicKey' => $values[self::PUBLIC_KEY], 'privateKey' => $values[self::PRIVATE_KEY]];
    }
}
