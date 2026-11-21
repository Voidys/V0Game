<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Social;

use App\Security\Social\OAuthClient;
use App\Security\Social\SocialIdentity;
use App\Security\Social\SocialLoginException;
use App\Security\Social\SocialProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The OAuth client without a network: authorize URL, code exchange (request shape) and the normalisation of every provider's
 * user data, including what counts as a verified e-mail address.
 */
final class OAuthClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse|array<mixed>> $answers responses in request order (an array is a 200 JSON answer)
     */
    private function client(array $answers): OAuthClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $answer = array_shift($answers);

            return $answer instanceof MockResponse ? $answer : new MockResponse(json_encode($answer, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return new OAuthClient($http);
    }

    private function provider(string $id): SocialProvider
    {
        return SocialProvider::find($id) ?? $this->fail('unknown provider');
    }

    public function testAuthorizeUrlCarriesStatePkceAndTheExactRedirectUri(): void
    {
        $url = $this->client([])->authorizeUrl($this->provider('discord'), 'cid', 'https://game.example/login/discord/callback', 'st4te', 'chall');
        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'response_type' => 'code',
            'client_id' => 'cid',
            'redirect_uri' => 'https://game.example/login/discord/callback',
            'scope' => 'identify email',
            'state' => 'st4te',
            'code_challenge' => 'chall',
            'code_challenge_method' => 'S256',
        ], $query);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> provider => authorize host path, scope */
    public static function authorizeEndpoints(): array
    {
        return [
            'discord' => ['discord', 'https://discord.com/oauth2/authorize', 'identify email'],
            'google' => ['google', 'https://accounts.google.com/o/oauth2/v2/auth', 'openid email profile'],
            'github' => ['github', 'https://github.com/login/oauth/authorize', 'read:user user:email'],
            'facebook' => ['facebook', 'https://www.facebook.com/v19.0/dialog/oauth', 'email public_profile'],
        ];
    }

    #[DataProvider('authorizeEndpoints')]
    public function testEndpointsAndScopesPerProvider(string $id, string $authorizeUrl, string $scope): void
    {
        $provider = $this->provider($id);

        $this->assertSame($authorizeUrl, $provider->authorizeUrl);
        $this->assertSame($scope, $provider->scope);
    }

    public function testUnknownProviderIsNotInTheWhitelist(): void
    {
        $this->assertNull(SocialProvider::find('twitter'));
        $this->assertNull(SocialProvider::find(''));
        $this->assertSame(['discord', 'facebook', 'github', 'google'], array_keys(SocialProvider::catalog()));
    }

    public function testDiscordIdentityAndTheCodeExchangeRequest(): void
    {
        $client = $this->client([
            ['access_token' => 'tok', 'token_type' => 'Bearer'],
            ['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly', 'email' => 'Nelly@Example.com', 'verified' => true],
        ]);

        $identity = $client->fetchIdentity($this->provider('discord'), 'cid', 'sec', 'the-code', 'https://game.example/login/discord/callback', 'ver1fier');

        $this->assertEquals(new SocialIdentity('discord', '80351110224678912', 'nelly@example.com', true, 'Nelly'), $identity);
        [$token, $user] = $this->requests;
        $this->assertSame(['POST', 'https://discord.com/api/oauth2/token'], [$token['method'], $token['url']]);
        parse_str((string) $token['options']['body'], $body);
        $this->assertSame([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'code' => 'the-code',
            'redirect_uri' => 'https://game.example/login/discord/callback',
            'grant_type' => 'authorization_code',
            'code_verifier' => 'ver1fier',
        ], $body);
        $this->assertSame(10.0, (float) $token['options']['timeout']);
        $this->assertSame(['GET', 'https://discord.com/api/users/@me'], [$user['method'], $user['url']]);
        $this->assertContains('Authorization: Bearer tok', $user['options']['headers']);
    }

    public function testDiscordUnverifiedEmailIsNotUsable(): void
    {
        $identity = $this->client([['access_token' => 't'], ['id' => '1', 'username' => 'u', 'email' => 'u@example.com', 'verified' => false]])
            ->fetchIdentity($this->provider('discord'), 'c', 's', 'x', 'https://r', 'v');

        $this->assertFalse($identity->emailVerified);
        $this->assertNull($identity->usableEmail());
        $this->assertSame('u', $identity->displayName, 'falls back to the username');
    }

    public function testGoogleIdentity(): void
    {
        $identity = $this->client([['access_token' => 't'], ['sub' => '1234', 'email' => 'g@example.com', 'email_verified' => true, 'name' => 'Gina']])
            ->fetchIdentity($this->provider('google'), 'c', 's', 'x', 'https://r', 'v');

        $this->assertEquals(new SocialIdentity('google', '1234', 'g@example.com', true, 'Gina'), $identity);
        $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', $this->requests[1]['url']);
    }

    public function testGoogleUnverifiedEmail(): void
    {
        $identity = $this->client([])->normalise('google', ['sub' => '1', 'email' => 'g@example.com', 'email_verified' => false]);

        $this->assertNull($identity->usableEmail());
    }

    public function testGithubUsesThePrimaryVerifiedEmailOfTheEmailList(): void
    {
        $identity = $this->client([
            ['access_token' => 't'],
            ['id' => 583231, 'login' => 'octocat', 'name' => null, 'email' => 'public@example.com'],
            [
                ['email' => 'other@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'Main@Example.com', 'primary' => true, 'verified' => true],
            ],
        ])->fetchIdentity($this->provider('github'), 'c', 's', 'x', 'https://r', 'v');

        $this->assertEquals(new SocialIdentity('github', '583231', 'main@example.com', true, 'octocat'), $identity);
        $this->assertSame(['https://github.com/login/oauth/access_token', 'https://api.github.com/user', 'https://api.github.com/user/emails'], array_column($this->requests, 'url'));
        $this->assertContains('Accept: application/json', $this->requests[0]['options']['headers']);
    }

    public function testGithubWithoutAPrimaryVerifiedEmailHasNone(): void
    {
        $identity = $this->client([])->normalise('github', ['id' => 5, 'login' => 'x', 'email' => 'public@example.com'], [
            ['email' => 'a@example.com', 'primary' => true, 'verified' => false],
            ['email' => 'b@example.com', 'primary' => false, 'verified' => true],
        ]);

        $this->assertNull($identity->email);
        $this->assertNull($identity->usableEmail());
    }

    public function testFacebookEmailIsNeverVerified(): void
    {
        $identity = $this->client([['access_token' => 't'], ['id' => '10159', 'name' => 'Fay', 'email' => 'fay@example.com']])
            ->fetchIdentity($this->provider('facebook'), 'c', 's', 'x', 'https://r', 'v');

        $this->assertSame('fay@example.com', $identity->email);
        $this->assertFalse($identity->emailVerified);
        $this->assertNull($identity->usableEmail());
        $this->assertSame('https://graph.facebook.com/me?fields=id,name,email', $this->requests[1]['url']);
    }

    public function testInvalidOrOverlongEmailIsDropped(): void
    {
        $client = $this->client([]);

        $this->assertNull($client->normalise('discord', ['id' => '1', 'email' => 'not-an-address', 'verified' => true])->email);
        $this->assertNull($client->normalise('discord', ['id' => '1', 'email' => str_repeat('a', 60) . '@example.com', 'verified' => true])->usableEmail());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function profilesWithoutUserId(): array
    {
        return [
            'discord' => ['discord', ['username' => 'x']],
            'google' => ['google', ['email' => 'a@example.com']],
            'github' => ['github', ['login' => 'x']],
            'facebook' => ['facebook', ['id' => ['nested']]],
            'empty id' => ['discord', ['id' => '']],
            'overlong id' => ['discord', ['id' => str_repeat('1', 192)]],
        ];
    }

    /** @param array<string, mixed> $profile */
    #[DataProvider('profilesWithoutUserId')]
    public function testMissingUserIdIsAnError(string $provider, array $profile): void
    {
        $this->expectException(SocialLoginException::class);
        $this->client([])->normalise($provider, $profile);
    }

    public function testUnknownProviderCannotBeNormalised(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->client([])->normalise('twitter', ['id' => '1']);
    }

    public function testTokenEndpointErrorsBecomeAnExceptionWithoutLeakingSecrets(): void
    {
        $client = $this->client([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])]);

        try {
            $client->fetchIdentity($this->provider('discord'), 'cid', 'TOP-SECRET', 'CODE-123', 'https://r', 'VERIFIER-456');
            $this->fail('expected an exception');
        } catch (SocialLoginException $e) {
            foreach (['TOP-SECRET', 'CODE-123', 'VERIFIER-456'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function testAnswerWithoutAccessTokenIsAnError(): void
    {
        // GitHub answers 200 with an error body for a bad code
        $this->expectException(SocialLoginException::class);
        $this->client([['error' => 'bad_verification_code']])->fetchIdentity($this->provider('github'), 'c', 's', 'x', 'https://r', 'v');
    }

    public function testNonJsonAnswerIsAnError(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->client([new MockResponse('<html>oops</html>')])->fetchIdentity($this->provider('google'), 'c', 's', 'x', 'https://r', 'v');
    }

    public function testUserEndpointFailureIsAnError(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->client([['access_token' => 't'], new MockResponse('{}', ['http_code' => 401])])->fetchIdentity($this->provider('google'), 'c', 's', 'x', 'https://r', 'v');
    }

    public function testTransportErrorIsAnError(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->client([new MockResponse('', ['error' => 'connection refused'])])->fetchIdentity($this->provider('google'), 'c', 's', 'x', 'https://r', 'v');
    }

    public function testSuggestedNameKeepsOnlyAllowedCharacters(): void
    {
        $this->assertSame('Ann bMüllerb_1', (new SocialIdentity('discord', '1', null, false, '  Ann <b>Müller</b>_1😀 '))->suggestedName());
        $this->assertSame(32, mb_strlen((new SocialIdentity('discord', '1', null, false, str_repeat('x', 50)))->suggestedName()));
        $this->assertSame('', (new SocialIdentity('discord', '1', null, false, '😀'))->suggestedName());
    }
}
