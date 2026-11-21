<?php

declare(strict_types=1);

namespace App\Security\Social;

use App\Security\PasswordSupport;

/**
 * The decisions of the social login after the provider vouched for a person (no HTTP, no session; the controller turns the
 * outcome into pages):
 *
 * a) the identity is linked -> Login of that account;
 * b) intent link and an account is logged in -> connect it there (Taken when another account owns the identity);
 * c) not linked, an account has the same VERIFIED e-mail address -> LinkWithPassword: the link is never made on the address
 *    alone, only after verifyAndLink() saw the password of that account;
 * d) not linked, no such account -> Register; without a verified address -> NoEmail.
 */
class SocialResolver
{
    public function __construct(private readonly SocialAccounts $accounts) {}

    /**
     * @param string $intent `login` or `link`
     * @param int $currentAccountId the logged-in account, 0 for a visitor
     * @return array{0: SocialOutcome, 1: int} outcome and the account it concerns (0 when none)
     */
    public function resolve(SocialIdentity $identity, string $intent, int $currentAccountId, int $now): array
    {
        $owner = $this->accounts->linkedAccountId($identity->provider, $identity->uid);

        if ($intent === 'link' && $currentAccountId > 0) {
            if ($owner !== null && $owner !== $currentAccountId) {
                return [SocialOutcome::Taken, $owner];
            }
            $this->accounts->link($currentAccountId, $identity, $now);

            return [SocialOutcome::Linked, $currentAccountId];
        }
        if ($owner !== null) {
            return [SocialOutcome::Login, $owner];
        }

        $email = $identity->usableEmail();
        if ($email === null) {
            return [SocialOutcome::NoEmail, 0];
        }
        $account = $this->accounts->accountByEmail($email);

        return $account !== null ? [SocialOutcome::LinkWithPassword, $account['id']] : [SocialOutcome::Register, 0];
    }

    /**
     * The account whose verified e-mail address the identity carries (null for an unverified or unknown address); the link step
     * looks it up again at every request.
     *
     * @return array{id: int, password: string}|null
     */
    public function accountOf(SocialIdentity $identity): ?array
    {
        $email = $identity->usableEmail();

        return $email === null ? null : $this->accounts->accountByEmail($email);
    }

    /**
     * Link step: with the right password of the account (raw or old-style hash, like every password check) the identity is
     * linked to it (Linked). WrongPassword leaves everything as it was; Taken when the identity belongs to another account by now.
     *
     * @param array{id: int, password: string} $account
     */
    public function verifyAndLink(array $account, SocialIdentity $identity, string $password, int $now): SocialOutcome
    {
        if (!PasswordSupport::verifyAny($password, $account['password'])) {
            return SocialOutcome::WrongPassword;
        }

        return $this->accounts->link($account['id'], $identity, $now) ? SocialOutcome::Linked : SocialOutcome::Taken;
    }
}
