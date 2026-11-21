<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\AccountUtil;
use App\Security\Social\SocialAccounts;
use App\Security\Social\SocialIdentity;
use App\Security\Social\SocialOutcome;
use App\Security\Social\SocialResolver;
use App\Tests\Support\GameTestCase;

/**
 * The decisions of the social login against the test database: a) linked identity logs in, b) intent link connects to the
 * logged-in account, c) an e-mail match never links without the password, d) no account leads to the registration, plus the
 * unique link, the unusable password marker and the cleanup when an account is deleted.
 */
final class SocialLoginResolutionTest extends GameTestCase
{
    private const NOW = 1_800_000_000;

    private SocialAccounts $accounts;
    private SocialResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = new SocialAccounts($this->db);
        $this->resolver = new SocialResolver($this->accounts);
    }

    /**
     * An account with a player (password `secret`) and its e-mail address.
     *
     * @return array{0: int, 1: string} account id, e-mail
     */
    private function account(): array
    {
        [$user] = $this->createPlayer();
        $row = $this->db->selectSingle('SELECT a.`id`, a.`email` FROM %%ACCOUNTS%% a INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.`account_id` = a.`id` WHERE au.`user_id` = :id;', [':id' => $user['id']]);

        return [(int) $row['id'], (string) $row['email']];
    }

    private function identity(string $uid, ?string $email = null, bool $verified = true, string $provider = 'discord'): SocialIdentity
    {
        return new SocialIdentity($provider, $uid, $email, $verified, 'Some Name');
    }

    private function linkCount(): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ACCOUNTS_SOCIAL%%;', [], 'c');
    }

    public function testLinkedIdentityLogsItsAccountIn(): void
    {
        [$accountId] = $this->account();
        $identity = $this->identity('u-1', 'whatever@example.com');
        $this->assertTrue($this->accounts->link($accountId, $identity, self::NOW));

        $this->assertSame([SocialOutcome::Login, $accountId], $this->resolver->resolve($identity, 'login', 0, self::NOW));

        $this->accounts->touchLogin($identity, self::NOW + 60);
        $row = $this->db->selectSingle('SELECT * FROM %%ACCOUNTS_SOCIAL%% WHERE `provider_uid` = :u;', [':u' => 'u-1']);
        $this->assertSame(self::NOW + 60, (int) $row['last_login_at']);
        $this->assertSame(self::NOW, (int) $row['created_at']);
    }

    public function testLinkedIdentityLogsInEvenWithoutAUsableEmail(): void
    {
        [$accountId] = $this->account();
        $identity = $this->identity('fb-1', 'x@example.com', false, 'facebook');
        $this->accounts->link($accountId, $identity, self::NOW);

        $this->assertSame([SocialOutcome::Login, $accountId], $this->resolver->resolve($identity, 'login', 0, self::NOW));
    }

    public function testLinkIntentConnectsTheLoggedInAccount(): void
    {
        [$accountId] = $this->account();
        $identity = $this->identity('u-2', 'other@example.com');

        $this->assertSame([SocialOutcome::Linked, $accountId], $this->resolver->resolve($identity, 'link', $accountId, self::NOW));
        $this->assertSame($accountId, $this->accounts->linkedAccountId('discord', 'u-2'));
        $this->assertSame(['discord'], array_keys($this->accounts->linksOf($accountId)));
    }

    public function testLinkIntentRefusesAnIdentityOfAnotherAccount(): void
    {
        [$owner] = $this->account();
        [$other] = $this->account();
        $identity = $this->identity('u-3');
        $this->accounts->link($owner, $identity, self::NOW);

        $this->assertSame([SocialOutcome::Taken, $owner], $this->resolver->resolve($identity, 'link', $other, self::NOW));
        $this->assertSame($owner, $this->accounts->linkedAccountId('discord', 'u-3'));
        $this->assertSame([], $this->accounts->linksOf($other));
    }

    public function testLinkIntentWithoutALoginIsAnOrdinaryLogin(): void
    {
        [$accountId] = $this->account();
        $identity = $this->identity('u-4');
        $this->accounts->link($accountId, $identity, self::NOW);

        $this->assertSame([SocialOutcome::Login, $accountId], $this->resolver->resolve($identity, 'link', 0, self::NOW));
    }

    public function testVerifiedEmailOfAnExistingAccountNeverLinksWithoutThePassword(): void
    {
        [$accountId, $email] = $this->account();
        $identity = $this->identity('u-5', strtoupper($email));
        // the provider normalisation lower-cases the address; the lookup is case-insensitive anyway
        $before = $this->linkCount();

        $this->assertSame([SocialOutcome::LinkWithPassword, $accountId], $this->resolver->resolve($identity, 'login', 0, self::NOW));
        $this->assertSame($before, $this->linkCount(), 'resolving must not create a link');
        $this->assertNull($this->accounts->linkedAccountId('discord', 'u-5'));
    }

    public function testPasswordStepLinksOnlyWithTheRightPassword(): void
    {
        [$accountId, $email] = $this->account();
        $identity = $this->identity('u-6', $email);
        $account = $this->resolver->accountOf($identity);
        $this->assertSame($accountId, $account['id']);

        $this->assertSame(SocialOutcome::WrongPassword, $this->resolver->verifyAndLink($account, $identity, 'not-the-password', self::NOW));
        $this->assertNull($this->accounts->linkedAccountId('discord', 'u-6'));

        $this->assertSame(SocialOutcome::Linked, $this->resolver->verifyAndLink($account, $identity, 'secret', self::NOW));
        $this->assertSame($accountId, $this->accounts->linkedAccountId('discord', 'u-6'));
    }

    public function testPasswordStepRefusesAnIdentityLinkedElsewhere(): void
    {
        [, $email] = $this->account();
        [$other] = $this->account();
        $identity = $this->identity('u-7', $email);
        $this->accounts->link($other, $identity, self::NOW);

        $this->assertSame(SocialOutcome::Taken, $this->resolver->verifyAndLink($this->resolver->accountOf($identity), $identity, 'secret', self::NOW));
    }

    public function testUnverifiedEmailIsNeverMatchedToAnAccount(): void
    {
        [, $email] = $this->account();

        $this->assertSame([SocialOutcome::NoEmail, 0], $this->resolver->resolve($this->identity('fb-2', $email, false, 'facebook'), 'login', 0, self::NOW));
        $this->assertNull($this->resolver->accountOf($this->identity('fb-2', $email, false, 'facebook')));
        $this->assertSame([SocialOutcome::NoEmail, 0], $this->resolver->resolve($this->identity('u-8', null), 'login', 0, self::NOW));
    }

    public function testUnknownVerifiedEmailLeadsToTheRegistration(): void
    {
        $this->assertSame([SocialOutcome::Register, 0], $this->resolver->resolve($this->identity('u-9', 'nobody-has-this@example.com'), 'login', 0, self::NOW));
    }

    public function testAnIdentityBelongsToOneAccountAndTheLinkIsUniquePerProvider(): void
    {
        [$first] = $this->account();
        [$second] = $this->account();
        $this->assertTrue($this->accounts->link($first, $this->identity('same'), self::NOW));

        $this->assertTrue($this->accounts->link($first, $this->identity('same'), self::NOW), 'the same account may link again');
        $this->assertFalse($this->accounts->link($second, $this->identity('same'), self::NOW));
        // the same user id at another provider is a different identity
        $this->assertTrue($this->accounts->link($second, $this->identity('same', null, true, 'github'), self::NOW));
    }

    public function testUnlinkRemovesOnlyThatProvider(): void
    {
        [$accountId] = $this->account();
        $this->accounts->link($accountId, $this->identity('a'), self::NOW);
        $this->accounts->link($accountId, $this->identity('b', null, true, 'github'), self::NOW);

        $this->assertTrue($this->accounts->unlink($accountId, 'discord'));
        $this->assertFalse($this->accounts->unlink($accountId, 'discord'));
        $this->assertSame(['github'], array_keys($this->accounts->linksOf($accountId)));
    }

    public function testUnusablePasswordMatchesNothingAndIsRecognised(): void
    {
        $hash = SocialAccounts::unusablePassword();
        $this->assertLessThanOrEqual(60, strlen($hash), 'fits accounts.password varchar(60)');
        $this->assertNotSame($hash, SocialAccounts::unusablePassword());
        $this->assertFalse(\App\Security\PasswordSupport::verifyAny('', $hash));
        $this->assertFalse(\App\Security\PasswordSupport::verifyAny($hash, $hash));

        $util = new AccountUtil($this->db, $this->configs, $this->state);
        $accountId = $util->createAccount('socialonly', $hash, 'socialonly@example.com', 'en');
        $this->assertFalse($this->accounts->hasUsablePassword($accountId));

        $this->db->update('UPDATE %%ACCOUNTS%% SET `password` = :p WHERE `id` = :id;', [':p' => password_hash('new', PASSWORD_BCRYPT, ['cost' => 4]), ':id' => $accountId]);
        $this->assertTrue($this->accounts->hasUsablePassword($accountId));
    }

    public function testDeletingAnAccountRemovesItsLinks(): void
    {
        $util = new AccountUtil($this->db, $this->configs, $this->state);
        $accountId = $util->createAccount('todelete', SocialAccounts::unusablePassword(), 'todelete@example.com', 'en');
        $this->accounts->link($accountId, $this->identity('gone'), self::NOW);
        $this->accounts->link($accountId, $this->identity('gone2', null, true, 'google'), self::NOW);

        $this->assertTrue($util->deleteAccount($accountId));

        $this->assertSame([], $this->accounts->linksOf($accountId));
        $this->assertNull($this->accounts->linkedAccountId('discord', 'gone'));
    }

    public function testEmailAndNameTakenAlsoSeePendingRegistrations(): void
    {
        $this->db->insert("INSERT INTO %%ACCOUNTS_VALID%% SET `accountname` = 'pendingname', `validationKey` = 'k', `password` = 'x', `email` = 'pending@example.com', `date` = 1, `ip` = '1.1.1.1', `language` = 'en';");

        $this->assertTrue($this->accounts->emailTaken('pending@example.com'));
        $this->assertTrue($this->accounts->nameTaken('pendingname'));
        $this->assertFalse($this->accounts->emailTaken('free@example.com'));
        $this->assertFalse($this->accounts->nameTaken('freename'));
    }
}
