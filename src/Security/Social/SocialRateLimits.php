<?php

declare(strict_types=1);

namespace App\Security\Social;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Abuse limits of the social login (limiters in config/packages/framework.yaml): the start and the callback of the
 * flow per client IP, the password of the "link your account" step per IP and account, the registration step per IP.
 * Each call counts one attempt and says whether it is still allowed.
 */
class SocialRateLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.social_flow')]
        private readonly RateLimiterFactoryInterface $flow,
        #[Autowire(service: 'limiter.social_link_password')]
        private readonly RateLimiterFactoryInterface $linkPassword,
        #[Autowire(service: 'limiter.social_complete')]
        private readonly RateLimiterFactoryInterface $complete,
    ) {}

    /** Start and callback of the OAuth flow per client IP. */
    public function allowFlow(string $ip): bool
    {
        return $this->flow->create($ip)->consume()->isAccepted();
    }

    /** Password attempts of the "link your account" step per client IP and target account. */
    public function allowLinkPassword(string $ip, int $accountId): bool
    {
        return $this->linkPassword->create($ip . '|' . $accountId)->consume()->isAccepted();
    }

    /** Submits of the "complete registration" step per client IP. */
    public function allowComplete(string $ip): bool
    {
        return $this->complete->create($ip)->consume()->isAccepted();
    }
}
