<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Generic in-game request budget per account (audit F-14/F-23), applied by App\EventListener\RateLimitListener to
 * the game and account areas:
 *
 * - state-changing requests (every unsafe method, plus the GET links of CsrfPolicy::GET_ROUTES) use the
 *   "game_actions" token bucket;
 * - expensive reads (galaxy, player card, search, statistics, battle hall, battle reports, records, and the read-only
 *   AJAX modes of the fleet steps and the message player search) use "game_reads";
 * - visitors without a login are limited per client IP ("game_anonymous") on the public pages (battle reports, board).
 *
 * A page counts once, in the reads bucket when it is one of the expensive pages, otherwise in the actions bucket
 * when it changes state. Everything else (overview, menus, plain reads) is not limited. Buckets and the
 * switch (`app.game_rate_limit.enabled`) are configured in config/packages/framework.yaml and config/services.yaml.
 */
final class GameRateLimits
{
    /** route names of the expensive reads */
    private const EXPENSIVE_ROUTES = [
        'game_galaxy_show', 'game_galaxy_rows', 'game_player_card_show', 'game_search_show', 'game_search_result', 'game_statistics_show',
        'game_battle_hall_show', 'game_raport_show', 'game_records_show',
        'game_fleet_step1_check_target', 'game_fleet_step2_check_resources', 'game_messages_load_user',
    ];

    /** the chat poll (a read in the background): its own generous bucket, 60 per minute (an open chat polls 15 per minute per tab) */
    private const CHAT_POLL_ROUTE = 'game_chat_poll';

    /** route names that can be opened without a login */
    private const ANONYMOUS_ROUTES = ['game_raport_show', 'account_board_show', 'account_board_post'];

    private const LIMITED_AREAS = ['game', 'account'];

    public function __construct(
        #[Autowire(service: 'limiter.game_actions', lazy: true)]
        private readonly RateLimiterFactoryInterface $actions,
        #[Autowire(service: 'limiter.game_reads', lazy: true)]
        private readonly RateLimiterFactoryInterface $reads,
        #[Autowire(service: 'limiter.game_anonymous', lazy: true)]
        private readonly RateLimiterFactoryInterface $anonymous,
        private readonly CsrfPolicy $policy,
        #[Autowire('%app.game_rate_limit.enabled%')]
        private readonly bool $enabled,
        #[Autowire(service: 'limiter.chat_poll', lazy: true)]
        private readonly ?RateLimiterFactoryInterface $chatPoll = null,
    ) {}

    /**
     * Counts the request for the account and says how long to wait when the budget is used up.
     *
     * @phpstan-impure
     * @return int|null null = allowed (or not covered); otherwise the seconds until the next request is accepted (at least 1)
     */
    public function check(Request $request, int $accountId): ?int
    {
        if (!$this->enabled || $accountId <= 0 || !$this->policy->isGameRequest($request)) {
            return null;
        }
        [$area] = $this->policy->areaAndPage($request);
        if (!in_array($area, self::LIMITED_AREAS, true)) {
            return null;
        }

        if ($area === 'game' && $request->attributes->get('_route') === self::CHAT_POLL_ROUTE && $this->chatPoll !== null) {
            $factory = $this->chatPoll;
        } elseif ($area === 'game' && in_array($request->attributes->get('_route'), self::EXPENSIVE_ROUTES, true)) {
            $factory = $this->reads;
        } elseif ($this->policy->requiresToken($request)) {
            $factory = $this->actions;
        } else {
            return null;
        }

        $limit = $factory->create((string) $accountId)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    /**
     * Counts a request of a visitor without a login against his IP, for the public pages only (battle reports, board).
     *
     * @phpstan-impure
     * @return int|null null = allowed (or not covered); otherwise the seconds until the next request is accepted (at least 1)
     */
    public function checkAnonymous(Request $request, ?string $ip): ?int
    {
        if (!$this->enabled || $ip === null || !$this->policy->isGameRequest($request)) {
            return null;
        }
        if (!in_array($request->attributes->get('_route'), self::ANONYMOUS_ROUTES, true)) {
            return null;
        }
        $limit = $this->anonymous->create($ip)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
