<?php

declare(strict_types=1);

namespace App\Security\Social;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * What the social login keeps in the Symfony session between its steps:
 *
 * - the authorization request (provider, state, PKCE code verifier, intent login|link, creation time): written when
 *   the browser is sent to the provider, taken out (single use) when it comes back, valid for 10 minutes;
 * - the pending identity (a person the provider vouched for who has no link yet), valid for 15 minutes, waiting for the
 *   "link with password" or "complete registration" step.
 *
 * The time is a parameter so the expiry is testable.
 */
final class SocialFlowState
{
    public const REQUEST_KEY = 'social_oauth';
    public const PENDING_KEY = 'social_pending';
    public const REQUEST_MAX_AGE = 600;
    public const PENDING_MAX_AGE = 900;

    /**
     * Starts an authorization: stores state and PKCE verifier (replacing an earlier unfinished one) and returns what
     * goes into the authorize URL.
     *
     * @param string $intent `login` or `link` (connect to the logged-in account)
     * @return array{state: string, challenge: string}
     */
    public function begin(SessionInterface $session, string $provider, string $intent, int $now): array
    {
        $state = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(48));
        $session->set(self::REQUEST_KEY, [
            'provider' => $provider,
            'state' => $state,
            'verifier' => $verifier,
            'intent' => $intent === 'link' ? 'link' : 'login',
            'created' => $now,
        ]);

        return ['state' => $state, 'challenge' => self::base64Url(hash('sha256', $verifier, true))];
    }

    /**
     * Takes the stored authorization out of the session (it is gone after this call, whatever the result) and checks
     * it against the returned state: constant-time compare, same provider, not older than 10 minutes.
     *
     * @return array{verifier: string, intent: string}|null null for a missing, foreign, used or expired state
     */
    public function consume(SessionInterface $session, string $provider, string $givenState, int $now): ?array
    {
        $stored = $session->remove(self::REQUEST_KEY);
        if (!is_array($stored) || !is_string($stored['state'] ?? null) || !is_string($stored['verifier'] ?? null)) {
            return null;
        }
        $age = $now - (int) ($stored['created'] ?? 0);
        if ($givenState === '' || !hash_equals($stored['state'], $givenState)
            || ($stored['provider'] ?? '') !== $provider || $age < 0 || $age > self::REQUEST_MAX_AGE) {
            return null;
        }

        return ['verifier' => $stored['verifier'], 'intent' => ($stored['intent'] ?? '') === 'link' ? 'link' : 'login'];
    }

    /** Keeps the identity for the next step (replaces an earlier pending one). */
    public function storePending(SessionInterface $session, SocialIdentity $identity, int $now): void
    {
        $session->set(self::PENDING_KEY, ['identity' => $identity->toArray(), 'created' => $now]);
    }

    /** The pending identity, null when there is none or it is older than 15 minutes (an expired one is dropped). */
    public function pending(SessionInterface $session, int $now): ?SocialIdentity
    {
        $stored = $session->get(self::PENDING_KEY);
        if (!is_array($stored) || !is_array($stored['identity'] ?? null)) {
            return null;
        }
        $age = $now - (int) ($stored['created'] ?? 0);
        if ($age < 0 || $age > self::PENDING_MAX_AGE) {
            $this->clearPending($session);

            return null;
        }

        return SocialIdentity::fromArray($stored['identity']);
    }

    /** Forgets the pending identity (after linking, registering or cancelling). */
    public function clearPending(SessionInterface $session): void
    {
        $session->remove(self::PENDING_KEY);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
