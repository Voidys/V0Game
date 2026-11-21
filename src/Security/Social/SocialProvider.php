<?php

declare(strict_types=1);

namespace App\Security\Social;

/**
 * One OAuth2 provider of the social login: its endpoints, scopes and the Lucide sprite icon of its button.
 * The catalog is code, not configuration, so an admin can only switch known providers on (App\Game\Core\Config\Schema\BasicSocialFields).
 */
final readonly class SocialProvider
{
    public function __construct(
        public string $id,
        public string $label,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $scope,
        public string $icon,
        public string $consoleUrl,
    ) {}

    /**
     * All providers by id, in display order.
     *
     * @return array<string, SocialProvider>
     */
    public static function catalog(): array
    {
        return [
            'discord' => new self('discord', 'Discord', 'https://discord.com/oauth2/authorize', 'https://discord.com/api/oauth2/token', 'identify email', 'discord', 'https://discord.com/developers/applications'),
            'facebook' => new self('facebook', 'Facebook', 'https://www.facebook.com/v19.0/dialog/oauth', 'https://graph.facebook.com/v19.0/oauth/access_token', 'email public_profile', 'facebook', 'https://developers.facebook.com/apps/'),
            'github' => new self('github', 'GitHub', 'https://github.com/login/oauth/authorize', 'https://github.com/login/oauth/access_token', 'read:user user:email', 'github', 'https://github.com/settings/developers'),
            'google' => new self('google', 'Google', 'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'openid email profile', 'globe', 'https://console.cloud.google.com/apis/credentials'),
        ];
    }

    /** The provider with this id, null for anything that is not in the whitelist. */
    public static function find(string $id): ?self
    {
        return self::catalog()[$id] ?? null;
    }
}
