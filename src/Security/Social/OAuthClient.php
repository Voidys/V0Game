<?php

declare(strict_types=1);

namespace App\Security\Social;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth2 authorization-code flow against the four providers: the authorize URL (with state and PKCE challenge), the
 * exchange of the code for an access token and the user lookup, normalised to a SocialIdentity. The HTTP client is
 * injected (tests use MockHttpClient). Tokens and secrets are only used for the requests and never logged or put into
 * exception messages.
 */
final class OAuthClient
{
    private const TIMEOUT = 10;
    private const MAX_DURATION = 15;

    private const USER_ENDPOINTS = [
        'discord' => 'https://discord.com/api/users/@me',
        'google' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'github' => 'https://api.github.com/user',
        'facebook' => 'https://graph.facebook.com/me?fields=id,name,email',
    ];

    private const GITHUB_EMAILS = 'https://api.github.com/user/emails';

    public function __construct(private readonly HttpClientInterface $http) {}

    /** The provider page the browser is sent to (response_type=code, S256 PKCE, state). */
    public function authorizeUrl(SocialProvider $provider, string $clientId, string $redirectUri, string $state, string $challenge): string
    {
        return $provider->authorizeUrl . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $provider->scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the code (with the PKCE verifier) and loads the person behind the access token.
     *
     * @throws SocialLoginException when the provider refuses or answers nonsense, or is not reachable
     */
    public function fetchIdentity(SocialProvider $provider, string $clientId, string $clientSecret, string $code, string $redirectUri, string $verifier): SocialIdentity
    {
        try {
            $token = $this->exchangeCode($provider, $clientId, $clientSecret, $code, $redirectUri, $verifier);
            $profile = $this->getJson(self::USER_ENDPOINTS[$provider->id], $token);
            $emails = $provider->id === 'github' ? $this->getJson(self::GITHUB_EMAILS, $token) : [];
        } catch (ExceptionInterface|\JsonException $e) {
            throw new SocialLoginException('Social login: ' . $provider->id . ' request failed (' . $e::class . ').', 0, $e);
        }

        return $this->normalise($provider->id, $profile, $emails);
    }

    /**
     * Normalises the user data of a provider to {provider, uid, email, emailVerified, displayName}. Only a statement of
     * the provider counts for "verified" (Discord `verified`, Google `email_verified`, GitHub primary and verified
     * entry of /user/emails); Facebook's address is always treated as unverified.
     *
     * @param array<mixed> $profile answer of the user endpoint
     * @param array<mixed> $emails answer of GitHub's /user/emails (ignored for the others)
     * @throws SocialLoginException without a usable user id
     */
    public function normalise(string $provider, array $profile, array $emails = []): SocialIdentity
    {
        [$uid, $email, $verified, $name] = match ($provider) {
            'discord' => [$profile['id'] ?? null, $profile['email'] ?? null, ($profile['verified'] ?? false) === true, $profile['global_name'] ?? $profile['username'] ?? ''],
            'google' => [$profile['sub'] ?? null, $profile['email'] ?? null, ($profile['email_verified'] ?? false) === true || ($profile['email_verified'] ?? '') === 'true', $profile['name'] ?? ''],
            'github' => [...$this->githubEmail($emails, $profile)],
            'facebook' => [$profile['id'] ?? null, $profile['email'] ?? null, false, $profile['name'] ?? ''],
            default => throw new SocialLoginException('Social login: unknown provider.'),
        };

        if ((!is_string($uid) && !is_int($uid)) || (string) $uid === '' || strlen((string) $uid) > 191) {
            throw new SocialLoginException('Social login: ' . $provider . ' returned no usable user id.');
        }

        $email = is_string($email) ? strtolower(trim($email)) : null;
        if ($email === null || $email === '' || strlen($email) > 64 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
            $verified = false;
        }

        return new SocialIdentity($provider, (string) $uid, $email, $verified, is_string($name) ? mb_substr(trim($name), 0, 100) : '');
    }

    /**
     * GitHub: id and name from /user, the e-mail only from the primary verified entry of /user/emails.
     *
     * @param array<mixed> $emails
     * @param array<mixed> $profile
     * @return array{0: mixed, 1: ?string, 2: bool, 3: mixed}
     */
    private function githubEmail(array $emails, array $profile): array
    {
        $email = null;
        foreach ($emails as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                $email = $entry['email'];
                break;
            }
        }

        return [$profile['id'] ?? null, $email, $email !== null, $profile['name'] ?? $profile['login'] ?? ''];
    }

    /**
     * POST of the token endpoint; returns the access token.
     *
     * @throws SocialLoginException
     */
    private function exchangeCode(SocialProvider $provider, string $clientId, string $clientSecret, string $code, string $redirectUri, string $verifier): string
    {
        $response = $this->http->request('POST', $provider->tokenUrl, [
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'V0Game'],
            'body' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => 0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new SocialLoginException('Social login: ' . $provider->id . ' token endpoint answered ' . $response->getStatusCode() . '.');
        }
        $token = $response->toArray(false)['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new SocialLoginException('Social login: ' . $provider->id . ' returned no access token.');
        }

        return $token;
    }

    /**
     * GET with the bearer token; the decoded JSON body.
     *
     * @return array<mixed>
     * @throws SocialLoginException
     */
    private function getJson(string $url, string $token): array
    {
        $response = $this->http->request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'User-Agent' => 'V0Game'],
            'timeout' => self::TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => 0,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new SocialLoginException('Social login: user endpoint answered ' . $response->getStatusCode() . '.');
        }

        return $response->toArray(false);
    }
}
