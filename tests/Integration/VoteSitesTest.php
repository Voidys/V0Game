<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\VoteSites;
use App\Tests\Support\GameTestCase;

/**
 * Vote sites: the cooldown window, the single reward per window (also for a double click), the postback secret and the
 * single-use token, and that the reward lands on the main planet exactly once.
 */
final class VoteSitesTest extends GameTestCase
{
    private const NOW = 1800000000;

    private VoteSites $votes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->votes = self::container()->get(VoteSites::class);
    }

    protected function tearDown(): void
    {
        // the vote transactions commit, so the rollback of the base class does not remove their rows
        $this->db->delete('DELETE l FROM %%VOTE_LOG%% l INNER JOIN %%VOTE_SITES%% s ON s.`id` = l.`site_id` WHERE s.`name` LIKE :name;', [':name' => 'test-site%']);
        $this->db->delete('DELETE FROM %%VOTE_SITES%% WHERE `name` LIKE :name;', [':name' => 'test-site%']);
        parent::tearDown();
    }

    /** @return array<string, mixed> a stored site with 1000 metal and 500 crystal as reward */
    private function site(string $verification, int $cooldownHours = 24): array
    {
        [$values] = $this->votes->validate([
            'name' => 'test-site-' . $verification, 'vote_url' => 'https://vote.example/in?u={userId}&t={token}', 'cooldown_hours' => $cooldownHours,
            'verification' => $verification, 'active' => 1, 'reward' => [901 => 1000, 902 => 500, 903 => 0],
        ]);
        $id = $this->votes->save(0, 1, $values, self::NOW);

        return $this->votes->find($id);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} user and planet row with 100 metal, 0 crystal */
    private function player(): array
    {
        return $this->createPlayer([], ['metal' => 100, 'crystal' => 0, 'deuterium' => 0]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function planetOf(array $user): array
    {
        return $this->loadPlanet((int) $user['id_planet']);
    }

    public function testClickWithoutVerificationRewardsOnceAndEnforcesTheCooldown(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_NONE);

        $first = $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);
        self::assertSame('rewarded', $first['status']);
        self::assertStringStartsWith('https://vote.example/in?u=' . $user['id'] . '&t=', (string) $first['url']);
        self::assertSame(1100.0, (float) $this->planetOf($user)['metal']);
        self::assertSame(500.0, (float) $this->planetOf($user)['crystal']);

        $early = $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW + 23 * 3600);
        self::assertSame('cooldown', $early['status']);
        self::assertNull($early['url']);
        self::assertSame(3600, $early['remaining']);
        self::assertSame(1100.0, (float) $this->planetOf($user)['metal']);

        $later = $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW + 24 * 3600);
        self::assertSame('rewarded', $later['status']);
        self::assertSame(2100.0, (float) $this->planetOf($user)['metal']);
    }

    public function testDoubleClickInTheSameSecondDoesNotPayTwice(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_NONE);

        $statuses = [
            $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW)['status'],
            $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW)['status'],
        ];

        self::assertSame(['rewarded', 'cooldown'], $statuses);
        self::assertSame(1100.0, (float) $this->planetOf($user)['metal']);
        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%VOTE_LOG%% WHERE `site_id` = :id AND `rewarded_at` IS NOT NULL;', [':id' => $site['id']], 'n'));
    }

    public function testPostbackWithWrongSecretIsRefusedAndKeepsTheToken(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_POSTBACK);
        $click = $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);
        self::assertSame('pending', $click['status']);
        self::assertSame(100.0, (float) $this->planetOf($user)['metal']);
        $token = $this->tokenOf($site);

        foreach (['', 'wrong', substr((string) $site['secret'], 0, 31)] as $secret) {
            self::assertSame(VoteSites::CALLBACK_FORBIDDEN, $this->votes->callback($site, $secret, $token, self::NOW + 5));
        }
        self::assertSame(100.0, (float) $this->planetOf($user)['metal']);

        self::assertSame(VoteSites::CALLBACK_OK, $this->votes->callback($site, (string) $site['secret'], $token, self::NOW + 5));
        self::assertSame(1100.0, (float) $this->planetOf($user)['metal']);
    }

    public function testPostbackTokenIsSingleUseAndRewardsLandOnce(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_POSTBACK);
        $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);
        $token = $this->tokenOf($site);

        self::assertSame(VoteSites::CALLBACK_OK, $this->votes->callback($site, (string) $site['secret'], $token, self::NOW + 5));
        self::assertSame(VoteSites::CALLBACK_USED, $this->votes->callback($site, (string) $site['secret'], $token, self::NOW + 6));
        self::assertSame(VoteSites::CALLBACK_UNKNOWN_TOKEN, $this->votes->callback($site, (string) $site['secret'], str_repeat('0', 32), self::NOW + 7));
        self::assertSame(VoteSites::CALLBACK_UNKNOWN_TOKEN, $this->votes->callback($site, (string) $site['secret'], 'not-a-token', self::NOW + 7));

        $planet = $this->planetOf($user);
        self::assertSame(1100.0, (float) $planet['metal']);
        self::assertSame(500.0, (float) $planet['crystal']);
        self::assertSame(0.0, (float) $planet['deuterium']);
    }

    public function testPostbackOnCooldownConsumesTheTokenWithoutReward(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_POSTBACK);
        $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);
        $first = $this->tokenOf($site);
        // a second token for the same window (clicked before the first callback arrived)
        $this->db->insert('INSERT INTO %%VOTE_LOG%% (`site_id`, `user_id`, `token`, `clicked_at`, `ip`) VALUES (:site, :user, :token, :now, :ip);', [
            ':site' => $site['id'], ':user' => $user['id'], ':token' => ($first[0] === 'b' ? 'c' : 'b') . substr($first, 1), ':now' => self::NOW + 1, ':ip' => '127.0.0.1',
        ]);

        self::assertSame(VoteSites::CALLBACK_OK, $this->votes->callback($site, (string) $site['secret'], $first, self::NOW + 5));
        self::assertSame(VoteSites::CALLBACK_COOLDOWN, $this->votes->callback($site, (string) $site['secret'], 'b' . substr($first, 1), self::NOW + 6));
        self::assertSame(1100.0, (float) $this->planetOf($user)['metal']);
    }

    public function testSiteWithoutPostbackRefusesCallbacks(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_NONE);
        $click = $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);
        parse_str((string) parse_url((string) $click['url'], PHP_URL_QUERY), $query);

        self::assertSame(VoteSites::CALLBACK_FORBIDDEN, $this->votes->callback($site, (string) $site['secret'], (string) $query['t'], self::NOW + 5));
    }

    public function testPurgeRemovesOldLogEntriesOnly(): void
    {
        [$user] = $this->player();
        $site = $this->site(VoteSites::VERIFY_NONE, 1);
        $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW - VoteSites::LOG_RETENTION - 10);
        $this->votes->click($site, (int) $user['id'], '127.0.0.1', self::NOW);

        $this->votes->purge(self::NOW);

        self::assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%VOTE_LOG%% WHERE `site_id` = :id;', [':id' => $site['id']], 'n'));
    }

    /**
     * the token of the most recent log row of the site
     *
     * @param array<string, mixed> $site
     */
    private function tokenOf(array $site): string
    {
        return (string) $this->db->selectSingle('SELECT `token` FROM %%VOTE_LOG%% WHERE `site_id` = :id ORDER BY `id` DESC LIMIT 1;', [':id' => $site['id']], 'token');
    }
}
