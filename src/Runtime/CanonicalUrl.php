<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Absolute URLs for links that leave the site (e-mails: password reset, account verification).
 *
 * They are built from the configured base URL (env DEFAULT_URI, written by the installer) and not from
 * the request's Host header, which an attacker controls (password reset poisoning). While DEFAULT_URI is unset
 * or still the placeholder http://localhost only development (kernel.debug) uses the request's own address;
 * otherwise no link is built (forPage() returns null and logs), so no mail with an attacker-chosen host is ever sent.
 */
final class CanonicalUrl
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = false,
    ) {}

    /** Is DEFAULT_URI set to a real address (not empty, not the placeholder localhost)? */
    public function isConfigured(): bool
    {
        $host = parse_url($this->defaultUri, PHP_URL_HOST);

        return is_string($host) && $host !== '' && $host !== 'localhost';
    }

    /** Does building mail links work (DEFAULT_URI set, or development where the request host is used)? */
    public function canBuildLinks(): bool
    {
        return $this->isConfigured() || $this->debug;
    }

    /**
     * Absolute URL for a mail. Returns null (and logs an error, once per refused mail) when DEFAULT_URI is not
     * configured outside development: the caller then must not send the mail.
     *
     * @param string $relative page URL relative to the universe directory, e.g. "verify?i=1&k=abc"
     */
    public function forPage(RuntimeContext $runtime, string $relative): ?string
    {
        if (!$this->isConfigured()) {
            if (!$this->debug) {
                error_log('Mail not sent: DEFAULT_URI is not configured (.env.local), the request host is not used for mail links.');

                return null;
            }

            return $runtime->httpPath . $relative;
        }

        $parts = parse_url($this->defaultUri);
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . $runtime->httpRoot . $relative;
    }

    /**
     * Absolute URL of a page below the site root (never inside a `uniN/` directory), for addresses that are registered
     * elsewhere, e.g. the OAuth callback of the social login. Built from DEFAULT_URI like forPage(); in development
     * without it from the request's own address; null otherwise (the caller must not use the host of the request).
     *
     * @param string $path e.g. "login/discord/callback"
     */
    public function siteUrl(RuntimeContext $runtime, string $path): ?string
    {
        if ($this->isConfigured()) {
            $parts = parse_url($this->defaultUri);
            $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return $origin . rtrim((string) ($parts['path'] ?? ''), '/') . '/' . $path;
        }

        return $this->debug ? $runtime->protocol . $runtime->httpHost . $runtime->httpBase . $path : null;
    }
}
