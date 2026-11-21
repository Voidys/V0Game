<?php

declare(strict_types=1);

namespace App\Security\Social;

use App\Game\Core\ConfigRegistry;

/**
 * The admin settings of the social login (category `social`, App\Game\Core\Config\Schema\BasicSocialFields): which
 * providers are on and their credentials. A provider counts only when it is switched on and has client id and secret.
 */
class SocialSettings
{
    public function __construct(private readonly ConfigRegistry $configs) {}

    /**
     * The providers a visitor can use.
     *
     * @return list<SocialProvider>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(SocialProvider::catalog(), fn(SocialProvider $p): bool => $this->isUsable($p->id)));
    }

    /** Is the provider known, switched on and configured? */
    public function isUsable(string $id): bool
    {
        if (SocialProvider::find($id) === null || !$this->value('social_' . $id . '_enabled')) {
            return false;
        }
        [$clientId, $secret] = $this->credentials($id);

        return $clientId !== '' && $secret !== '';
    }

    /**
     * Client id and secret (the secret never leaves the server side).
     *
     * @return array{0: string, 1: string}
     */
    public function credentials(string $id): array
    {
        return [
            html_entity_decode(trim((string) $this->value('social_' . $id . '_client_id')), ENT_QUOTES, 'UTF-8'),
            html_entity_decode((string) $this->value('social_' . $id . '_client_secret'), ENT_QUOTES, 'UTF-8'),
        ];
    }

    private function value(string $key): mixed
    {
        try {
            return $this->configs->get()->$key;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
