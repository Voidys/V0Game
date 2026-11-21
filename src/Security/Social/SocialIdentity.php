<?php

declare(strict_types=1);

namespace App\Security\Social;

/**
 * The normalised answer of a provider about the person who logged in: stable user id, e-mail address (with the
 * provider's statement whether it is verified) and a display name. Holds no tokens.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $uid,
        public ?string $email,
        public bool $emailVerified,
        public string $displayName,
    ) {}

    /** The e-mail address for decisions (matching an account, registering): only a verified, valid one, lower case. */
    public function usableEmail(): ?string
    {
        if (!$this->emailVerified || $this->email === null || $this->email === '') {
            return null;
        }

        return $this->email;
    }

    /**
     * A player name made of the display name: only the characters the account name allows, at most 32 of them.
     */
    public function suggestedName(): string
    {
        $name = (string) preg_replace('/[^0-9a-zA-ZäöüÄÖÜß _.-]/u', '', $this->displayName);

        return trim(mb_substr(trim($name), 0, 32));
    }

    /**
     * @return array{provider: string, uid: string, email: ?string, verified: bool, name: string}
     */
    public function toArray(): array
    {
        return ['provider' => $this->provider, 'uid' => $this->uid, 'email' => $this->email, 'verified' => $this->emailVerified, 'name' => $this->displayName];
    }

    /** @param array<mixed> $data as written by toArray() */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['provider'] ?? null) || !is_string($data['uid'] ?? null) || $data['uid'] === '') {
            return null;
        }

        return new self(
            $data['provider'],
            $data['uid'],
            is_string($data['email'] ?? null) ? $data['email'] : null,
            ($data['verified'] ?? false) === true,
            is_string($data['name'] ?? null) ? $data['name'] : '',
        );
    }
}
