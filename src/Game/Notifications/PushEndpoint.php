<?php

declare(strict_types=1);

namespace App\Game\Notifications;

/**
 * Whitelist of Web Push service hosts: the server only POSTs to endpoints of known push services (no SSRF through
 * a browser-supplied subscription URL).
 */
final class PushEndpoint
{
    /** hosts that match exactly */
    private const array HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];

    /** suffixes (with leading dot) that match any subdomain */
    private const array SUFFIXES = ['.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'];

    /** Whether the URL is https on port 443, without userinfo, and its host (no IP literal, case-insensitive) is a known push service. */
    public static function isAllowed(string $url): bool
    {
        if ($url === '' || strlen($url) > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (
            $parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || str_contains(explode('?', explode('#', $url, 2)[0], 2)[0], '@')
        ) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if ($host === '' || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }
        if (in_array($host, self::HOSTS, true)) {
            return true;
        }
        foreach (self::SUFFIXES as $suffix) {
            if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a user may store a subscription for an endpoint: the endpoint is new, already his, or held by someone
     * else with the same p256dh and auth keys (the same browser, now signed in as another player).
     *
     * @param array{user_id: int|string, p256dh: string, auth: string}|null $existing the stored row of the endpoint
     */
    public static function mayStore(?array $existing, int $userId, string $p256dh, string $auth): bool
    {
        return $existing === null
            || (int) $existing['user_id'] === $userId
            || (hash_equals((string) $existing['p256dh'], $p256dh) && hash_equals((string) $existing['auth'], $auth));
    }
}
