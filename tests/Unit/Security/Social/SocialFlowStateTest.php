<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Social;

use App\Security\Social\SocialFlowState;
use App\Security\Social\SocialIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * State, PKCE and pending-identity handling of the social login: a state is single use, bound to the provider, valid for 10
 * minutes; the challenge is the S256 hash of the stored verifier; a pending identity expires after 15 minutes.
 */
final class SocialFlowStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    public function testBeginStoresStateAndAnS256ChallengeOfTheVerifier(): void
    {
        $session = $this->session();
        $request = (new SocialFlowState())->begin($session, 'discord', 'login', self::NOW);

        $stored = $session->get(SocialFlowState::REQUEST_KEY);
        $this->assertSame($request['state'], $stored['state']);
        $this->assertSame('discord', $stored['provider']);
        $this->assertSame('login', $stored['intent']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $stored['verifier'], true)), '+/', '-_'), '='), $request['challenge']);
        // 32 random bytes as base64url = 43 characters; the verifier is within RFC 7636's 43..128
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $request['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $stored['verifier']);
    }

    public function testStatesAreRandom(): void
    {
        $flow = new SocialFlowState();

        $this->assertNotSame(
            $flow->begin($this->session(), 'github', 'login', self::NOW)['state'],
            $flow->begin($this->session(), 'github', 'login', self::NOW)['state'],
        );
    }

    public function testConsumeReturnsVerifierAndIntentOnce(): void
    {
        $session = $this->session();
        $flow = new SocialFlowState();
        $request = $flow->begin($session, 'google', 'link', self::NOW);
        $verifier = $session->get(SocialFlowState::REQUEST_KEY)['verifier'];

        $this->assertSame(['verifier' => $verifier, 'intent' => 'link'], $flow->consume($session, 'google', $request['state'], self::NOW + 5));
        // single use: gone after the first call
        $this->assertNull($flow->consume($session, 'google', $request['state'], self::NOW + 6));
        $this->assertFalse($session->has(SocialFlowState::REQUEST_KEY));
    }

    public function testWrongStateIsRefusedAndStillBurnsTheStoredOne(): void
    {
        $session = $this->session();
        $flow = new SocialFlowState();
        $request = $flow->begin($session, 'discord', 'login', self::NOW);

        $this->assertNull($flow->consume($session, 'discord', 'forged', self::NOW));
        $this->assertNull($flow->consume($session, 'discord', $request['state'], self::NOW), 'a failed attempt uses the state up');
    }

    public function testEmptyStateAndMissingStoredStateAreRefused(): void
    {
        $flow = new SocialFlowState();

        $this->assertNull($flow->consume($this->session(), 'discord', 'anything', self::NOW));
        $session = $this->session();
        $flow->begin($session, 'discord', 'login', self::NOW);
        $this->assertNull($flow->consume($session, 'discord', '', self::NOW));
    }

    public function testStateOfAnotherProviderIsRefused(): void
    {
        $session = $this->session();
        $flow = new SocialFlowState();
        $request = $flow->begin($session, 'discord', 'login', self::NOW);

        $this->assertNull($flow->consume($session, 'github', $request['state'], self::NOW));
    }

    public function testStateExpiresAfterTenMinutes(): void
    {
        $flow = new SocialFlowState();
        $session = $this->session();
        $request = $flow->begin($session, 'discord', 'login', self::NOW);
        $this->assertNull($flow->consume($session, 'discord', $request['state'], self::NOW + SocialFlowState::REQUEST_MAX_AGE + 1));

        $session = $this->session();
        $request = $flow->begin($session, 'discord', 'login', self::NOW);
        $this->assertNotNull($flow->consume($session, 'discord', $request['state'], self::NOW + SocialFlowState::REQUEST_MAX_AGE));
    }

    public function testUnknownIntentFallsBackToLogin(): void
    {
        $session = $this->session();
        $flow = new SocialFlowState();
        $request = $flow->begin($session, 'discord', 'something-else', self::NOW);

        $this->assertSame('login', $flow->consume($session, 'discord', $request['state'], self::NOW)['intent']);
    }

    public function testPendingIdentityRoundTripsAndExpiresAfterFifteenMinutes(): void
    {
        $flow = new SocialFlowState();
        $session = $this->session();
        $identity = new SocialIdentity('github', '42', 'a@example.com', true, 'Ann');
        $flow->storePending($session, $identity, self::NOW);

        $this->assertEquals($identity, $flow->pending($session, self::NOW + SocialFlowState::PENDING_MAX_AGE));
        $this->assertNull($flow->pending($session, self::NOW + SocialFlowState::PENDING_MAX_AGE + 1));
        $this->assertNull($flow->pending($session, self::NOW), 'an expired identity is dropped');
    }

    public function testClearPending(): void
    {
        $flow = new SocialFlowState();
        $session = $this->session();
        $flow->storePending($session, new SocialIdentity('github', '42', null, false, ''), self::NOW);
        $flow->clearPending($session);

        $this->assertNull($flow->pending($session, self::NOW));
    }
}
