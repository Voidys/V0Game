<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks the application secret and the runtime mode for the admin dashboard. The secret signs remember-me cookies,
 * device links and proof-of-work challenges: a publicly known or short value lets anyone forge them. The installer
 * writes a random secret to .env.local; this catches servers that still run on a repository default or in debug mode.
 */
final class SecretAudit
{
    /** Values that were public at some point (repository .env.dev / .env.test, Symfony's placeholder). */
    private const PUBLIC_SECRETS = [
        '969653de3fda3c4eac642eda0382f974',
        '$ecretf0rt3st',
        'ThisTokenIsNotSoSecretChangeIt',
        'ThisTokenIsNotSoSecretChangeIt!',
        'change_me',
        'changeme',
    ];

    /** Shorter secrets are guessable (the installer writes 64 hex characters). */
    private const MIN_LENGTH = 32;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    /** True when the secret is a known public value or too short. */
    public function weakSecret(): bool
    {
        return in_array($this->secret, self::PUBLIC_SECRETS, true) || strlen($this->secret) < self::MIN_LENGTH;
    }

    /**
     * True when a live server runs in development mode (APP_ENV other than prod, or debug on). Local DDEV projects are
     * development setups on purpose and are not reported.
     */
    public function developmentMode(?bool $isDdev = null): bool
    {
        $isDdev ??= getenv('IS_DDEV_PROJECT') !== false;

        return !$isDdev && ($this->environment !== 'prod' || $this->debug);
    }
}
