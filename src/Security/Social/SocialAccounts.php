<?php

declare(strict_types=1);

namespace App\Security\Social;

use App\Game\Core\Database;

/**
 * Persistence of the social login: the links between accounts and provider identities ({prefix}accounts_social:
 * UNIQUE(provider, provider_uid), so one provider identity belongs to at most one account) and the account lookups the
 * login steps need.
 */
class SocialAccounts
{
    /** marker of a password hash nobody can match (password_verify() is false for it): social-only accounts */
    private const UNUSABLE_PREFIX = '!';

    public function __construct(private readonly Database $db) {}

    /** The account an identity is linked to, null when it is not linked. */
    public function linkedAccountId(string $provider, string $uid): ?int
    {
        $id = $this->db->selectSingle(
            'SELECT `account_id` FROM %%ACCOUNTS_SOCIAL%% WHERE `provider` = :provider AND `provider_uid` = :uid;',
            [':provider' => $provider, ':uid' => $uid],
            'account_id',
        );

        return $id === null || $id === false ? null : (int) $id;
    }

    /**
     * Links the identity to the account. False when it already belongs to another account (also when another request
     * won the race for the unique key); linking again to the same account only refreshes the stored e-mail.
     */
    public function link(int $accountId, SocialIdentity $identity, int $now): bool
    {
        $owner = $this->linkedAccountId($identity->provider, $identity->uid);
        if ($owner !== null) {
            return $owner === $accountId;
        }

        try {
            $this->db->insert(
                'INSERT INTO %%ACCOUNTS_SOCIAL%% SET `account_id` = :account, `provider` = :provider, `provider_uid` = :uid, `email` = :email, `created_at` = :now;',
                [':account' => $accountId, ':provider' => $identity->provider, ':uid' => $identity->uid, ':email' => $identity->email, ':now' => $now],
            );
        } catch (\Throwable) {
            return $this->linkedAccountId($identity->provider, $identity->uid) === $accountId;
        }

        return true;
    }

    /** Remembers the login time (and the provider's current e-mail) of a link. */
    public function touchLogin(SocialIdentity $identity, int $now): void
    {
        $this->db->update(
            'UPDATE %%ACCOUNTS_SOCIAL%% SET `last_login_at` = :now, `email` = COALESCE(:email, `email`) WHERE `provider` = :provider AND `provider_uid` = :uid;',
            [':now' => $now, ':email' => $identity->email, ':provider' => $identity->provider, ':uid' => $identity->uid],
        );
    }

    /**
     * The providers an account is linked to.
     *
     * @return array<string, array{email: ?string, created_at: int}> provider id => link data
     */
    public function linksOf(int $accountId): array
    {
        $links = [];
        foreach ($this->db->select('SELECT `provider`, `email`, `created_at` FROM %%ACCOUNTS_SOCIAL%% WHERE `account_id` = :account ORDER BY `id`;', [':account' => $accountId]) as $row) {
            $links[(string) $row['provider']] = ['email' => $row['email'] === null ? null : (string) $row['email'], 'created_at' => (int) $row['created_at']];
        }

        return $links;
    }

    /** Removes the link of one provider; false when there was none. */
    public function unlink(int $accountId, string $provider): bool
    {
        $before = count($this->linksOf($accountId));
        $this->db->delete('DELETE FROM %%ACCOUNTS_SOCIAL%% WHERE `account_id` = :account AND `provider` = :provider;', [':account' => $accountId, ':provider' => $provider]);

        return count($this->linksOf($accountId)) < $before;
    }

    /** Removes all links of an account (account deletion). */
    public function deleteAllOf(int $accountId): void
    {
        $this->db->delete('DELETE FROM %%ACCOUNTS_SOCIAL%% WHERE `account_id` = :account;', [':account' => $accountId]);
    }

    /**
     * The account that uses the e-mail address (as login or second address), null when there is none.
     *
     * @return array{id: int, password: string}|null
     */
    public function accountByEmail(string $email): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT `id`, `password` FROM %%ACCOUNTS%% WHERE `email` = :mail OR `email_2` = :mail ORDER BY `id` LIMIT 1;',
            [':mail' => $email],
        );

        return is_array($row) && $row !== [] ? ['id' => (int) $row['id'], 'password' => (string) $row['password']] : null;
    }

    /** Does an account or a still unverified registration already use the address? */
    public function emailTaken(string $email): bool
    {
        if ($this->accountByEmail($email) !== null) {
            return true;
        }

        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ACCOUNTS_VALID%% WHERE `email` = :mail;', [':mail' => $email], 'c') > 0;
    }

    /** Is the account name used by an account or a pending registration? */
    public function nameTaken(string $name): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT (SELECT COUNT(*) FROM %%ACCOUNTS%% WHERE `accountname` = :name) + (SELECT COUNT(*) FROM %%ACCOUNTS_VALID%% WHERE `accountname` = :name) AS c;',
            [':name' => $name],
            'c',
        ) > 0;
    }

    /** A password hash that matches no password: the stored password of an account that registered through a provider. */
    public static function unusablePassword(): string
    {
        return self::UNUSABLE_PREFIX . bin2hex(random_bytes(29));
    }

    /**
     * Can the account log in with a password? False only for the accounts created by the social login that never set
     * one (lost password or the account settings replace the hash with a real one).
     */
    public function hasUsablePassword(int $accountId): bool
    {
        $hash = (string) $this->db->selectSingle('SELECT `password` FROM %%ACCOUNTS%% WHERE `id` = :id;', [':id' => $accountId], 'password');

        return $hash !== '' && !str_starts_with($hash, self::UNUSABLE_PREFIX);
    }
}
