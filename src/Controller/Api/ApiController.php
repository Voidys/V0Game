<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiTokens;
use App\Api\PlayerApi;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Singleton;
use App\Routing\GameRoute;
use App\Runtime\GameKernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only player API (/api/v1/..., optional `uniN/` prefix). Not a game page: no game session, no CSRF, no CORS; the
 * only credential is a personal token (`Authorization: Bearer v0_...`, see ApiTokens and the page /game/api). Every
 * answer is JSON; errors are `{"error": "<code>"}` with 401 (missing/unknown/revoked token or wrong universe), 403
 * (banned player, token without the scope) or 429 (60 requests per minute and token, header Retry-After).
 */
final class ApiController
{
    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly PlayerApi $api,
        private readonly GameKernel $kernel,
        private readonly Singleton $state,
        #[Autowire(service: 'limiter.api_token')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {}

    /** GET /api/v1/me: id, name, universe, points and rank (any token). */
    #[Route('/{uni}api/v1/me', name: 'api_v1_me', requirements: ['uni' => GameRoute::UNI_REQUIREMENT], defaults: ['uni' => ''], methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        return $this->handle($request, null, fn(array $token): array => $this->api->me($token['user_id']));
    }

    /** GET /api/v1/empire: all planets and moons with current resources, production, buildings, ships and defense (scope empire). */
    #[Route('/{uni}api/v1/empire', name: 'api_v1_empire', requirements: ['uni' => GameRoute::UNI_REQUIREMENT], defaults: ['uni' => ''], methods: ['GET'])]
    public function empire(Request $request): JsonResponse
    {
        return $this->handle($request, ApiTokens::SCOPE_EMPIRE, function (array $token): array {
            $this->bootGame($token['universe']);

            return $this->api->empire($token['user_id'], time());
        });
    }

    /** GET /api/v1/reports/spy?since=<unix time>&limit=<1..50>: the player's spy reports, newest first (scope reports). */
    #[Route('/{uni}api/v1/reports/spy', name: 'api_v1_reports_spy', requirements: ['uni' => GameRoute::UNI_REQUIREMENT], defaults: ['uni' => ''], methods: ['GET'])]
    public function spyReports(Request $request): JsonResponse
    {
        return $this->handle($request, ApiTokens::SCOPE_REPORTS, function (array $token) use ($request): array {
            $this->bootGame($token['universe']);

            return ['reports' => $this->api->spyReports($token['user_id'], $token['universe'], $this->since($request), $this->limit($request))];
        });
    }

    /** GET /api/v1/reports/combat?since=<unix time>&limit=<1..50>: the player's combat reports, newest first (scope reports). */
    #[Route('/{uni}api/v1/reports/combat', name: 'api_v1_reports_combat', requirements: ['uni' => GameRoute::UNI_REQUIREMENT], defaults: ['uni' => ''], methods: ['GET'])]
    public function combatReports(Request $request): JsonResponse
    {
        return $this->handle($request, ApiTokens::SCOPE_REPORTS, fn(array $token): array => [
            'reports' => $this->api->combatReports($token['user_id'], $this->since($request), $this->limit($request)),
        ]);
    }

    /**
     * Authenticates the request (bearer token, universe, rate limit, ban, scope), runs the endpoint and wraps the result as JSON.
     *
     * @param callable(array{id: int, user_id: int, universe: int, scopes: list<string>}): array<string, mixed> $endpoint
     */
    private function handle(Request $request, ?string $scope, callable $endpoint): JsonResponse
    {
        // not a game page, so the game is not booted: the database layer only needs its timing bag
        $this->state->time = ['start' => microtime(true), 'db' => 0.0];

        $token = $this->authenticate($request);
        if ($token instanceof JsonResponse) {
            return $token;
        }
        if ($this->tokens->isBanned($token['user_id'], time())) {
            return $this->error('banned', 403);
        }
        if ($scope !== null && !in_array($scope, $token['scopes'], true)) {
            return $this->error('missing_scope', 403, ['scope' => $scope]);
        }
        $this->tokens->touch($token['id'], time());

        try {
            return $this->json($endpoint($token));
        } catch (EndOfRequest) {
            return $this->error('unavailable', 503);
        }
    }

    /**
     * @return array{id: int, user_id: int, universe: int, scopes: list<string>}|JsonResponse the token, or the error answer
     */
    private function authenticate(Request $request): array|JsonResponse
    {
        $header = (string) $request->headers->get('Authorization', '');
        $plain = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : '';
        $token = $plain !== '' ? $this->tokens->find($plain) : null;

        // anything that fails to authenticate counts against the client IP (guessing), valid tokens against themselves
        $key = $token !== null ? 'token:' . $token['id'] : 'ip:' . ($request->getClientIp() ?? 'unknown');
        $limit = $this->limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            $response = $this->error('rate_limited', 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }
        if ($token === null) {
            $response = $this->error('unauthorized', 401);
            $response->headers->set('WWW-Authenticate', 'Bearer realm="v0game"');

            return $response;
        }

        // a `uniN/` prefix names the universe the token has to belong to; without it the token's own universe applies
        $prefix = (string) $request->attributes->get('uni');
        if ($prefix !== '' && (int) substr($prefix, 3, -1) !== $token['universe']) {
            return $this->error('unauthorized', 401);
        }

        return $token;
    }

    /** Boots the game data (element catalog, universe config) for the endpoints that need it, once per request. */
    private function bootGame(int $universe): void
    {
        if ($this->state->elementCatalog === null) {
            $this->state->universeCurrent = $universe;
            $this->kernel->bootCli();
        }
    }

    private function since(Request $request): int
    {
        return max(0, (int) $request->query->get('since', 0));
    }

    private function limit(Request $request): int
    {
        return (int) $request->query->get('limit', PlayerApi::DEFAULT_REPORTS);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @param array<string, mixed> $extra */
    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return $this->json(['error' => $code] + $extra, $status);
    }
}
