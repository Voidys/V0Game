<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Account\BoardController;
use App\Controller\Game\GalaxyController;
use App\Controller\Game\OverviewController;
use App\Controller\Game\ShipyardController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Security\AuthRateLimits;
use App\Security\CsrfPolicy;
use App\Security\GameRateLimits;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Audit F-14/F-22/F-23: the in-game token buckets (burst, paced play, switch) and the board's self-vote refusal and daily caps.
 */
final class GameRateLimitsTest extends GameTestCase
{
    private function limits(bool $enabled = true, int $actionBurst = 5, int $readBurst = 8): GameRateLimits
    {
        // the limiter reads microtime() and refills in whole seconds, so paced play is scripted with real one second sleeps
        $bucket = static fn(int $burst, int $perSecond): RateLimiterFactory => new RateLimiterFactory(
            ['id' => 't' . $burst, 'policy' => 'token_bucket', 'limit' => $burst, 'rate' => ['interval' => '1 second', 'amount' => $perSecond]],
            new InMemoryStorage(),
        );

        return new GameRateLimits($bucket($actionBurst, 1), $bucket($readBurst, 2), $bucket(3, 1), self::container()->get(CsrfPolicy::class), $enabled);
    }

    /**
     * @param class-string $controller
     * @param array<string, string> $query
     */
    private function request(string $route, string $controller, string $method = 'POST', array $query = []): Request
    {
        $request = Request::create('/game', $method, $method === 'GET' ? $query : []);
        $request->attributes->set('_route', $route);
        $request->attributes->set('_area', 'game');
        $request->attributes->set('_controller', $controller . '::show');

        return $request;
    }

    public function testBurstOfPostsTripsAfterTheBucketIsEmpty(): void
    {
        $limits = $this->limits();
        $request = $this->request('game_shipyard_order', ShipyardController::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($limits->check($request, 42), "request $i");
        }
        $retry = $limits->check($request, 42);
        $this->assertNotNull($retry);
        $this->assertGreaterThanOrEqual(1, $retry);
        // another player has his own bucket
        $this->assertNull($limits->check($request, 43));
    }

    public function testPacedPlayDoesNotTrip(): void
    {
        $limits = $this->limits(true, 2);
        $request = $this->request('game_shipyard_order', ShipyardController::class);
        // one request per second is the refill rate: it never runs dry
        for ($i = 0; $i < 6; $i++) {
            $this->assertNull($limits->check($request, 42), "request $i");
            usleep(1_050_000);
        }
    }

    public function testChatPollHasItsOwnBucket(): void
    {
        $poll = new RateLimiterFactory(['id' => 'poll', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute'], new InMemoryStorage());
        $bucket = new RateLimiterFactory(['id' => 'b', 'policy' => 'token_bucket', 'limit' => 5, 'rate' => ['interval' => '1 second', 'amount' => 1]], new InMemoryStorage());
        $limits = new GameRateLimits($bucket, $bucket, $bucket, self::container()->get(CsrfPolicy::class), true, $poll);
        $request = $this->request('game_chat_poll', \App\Controller\Game\ChatController::class, 'GET');
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($limits->check($request, 42), "poll $i");
        }
        $this->assertNotNull($limits->check($request, 42), 'the fourth poll within a minute is refused');
        $this->assertNull($limits->check($request, 43), 'per player');
        $this->assertNull($limits->check($this->request('game_overview_show', OverviewController::class, 'GET'), 42), 'other pages unaffected');
    }

    public function testPlainReadsAreNotCounted(): void
    {
        $limits = $this->limits();
        $request = $this->request('game_overview_show', OverviewController::class, 'GET');
        for ($i = 0; $i < 100; $i++) {
            $this->assertNull($limits->check($request, 42));
        }
    }

    public function testExpensiveReadsHaveTheirOwnBucket(): void
    {
        $limits = $this->limits();
        $galaxy = $this->request('game_galaxy_show', GalaxyController::class, 'GET');
        for ($i = 0; $i < 8; $i++) {
            $this->assertNull($limits->check($galaxy, 42), "read $i");
        }
        $this->assertNotNull($limits->check($galaxy, 42));
        // the action bucket is untouched
        $this->assertNull($limits->check($this->request('game_shipyard_order', ShipyardController::class), 42));
        // refill
        usleep(1_050_000);
        $this->assertNull($limits->check($galaxy, 42));
    }

    public function testReadOnlyAjaxModesCountInTheReadsBucket(): void
    {
        $limits = $this->limits();
        $check = $this->request('game_fleet_step1_check_target', \App\Controller\Game\FleetStep1Controller::class, 'GET');
        for ($i = 0; $i < 8; $i++) {
            $this->assertNull($limits->check($check, 42), "read $i");
        }
        $this->assertNotNull($limits->check($check, 42));
        // the fleet step page itself is not a read of this kind
        $plain = $this->request('game_fleet_step1_show', \App\Controller\Game\FleetStep1Controller::class, 'GET');
        $this->assertNull($limits->check($plain, 42));
    }

    public function testAnonymousPublicPagesAreLimitedPerIp(): void
    {
        $limits = $this->limits();
        $report = $this->request('game_raport_show', \App\Controller\Game\RaportController::class, 'GET');
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($limits->checkAnonymous($report, '10.0.0.1'), "hit $i");
        }
        $this->assertNotNull($limits->checkAnonymous($report, '10.0.0.1'));
        $this->assertNull($limits->checkAnonymous($report, '10.0.0.2'));
        // other pages are not covered
        $overview = $this->request('game_overview_show', OverviewController::class, 'GET');
        $this->assertNull($limits->checkAnonymous($overview, '10.0.0.1'));
        $this->assertNull($this->limits(false)->checkAnonymous($report, '10.0.0.1'));
    }

    public function testStateChangingGetLinkIsAnAction(): void
    {
        $limits = $this->limits(true, 2);
        // a state-changing GET route (CsrfPolicy::GET_ROUTES): the logout link
        $link = $this->request('account_logout_show', \App\Controller\Game\BuddyListController::class, 'GET');
        $link->attributes->set('_area', 'account');
        $this->assertNull($limits->check($link, 42));
        $this->assertNull($limits->check($link, 42));
        $this->assertNotNull($limits->check($link, 42));
    }

    public function testDisabledAndVisitorsAreNeverLimited(): void
    {
        $off = $this->limits(false, 1);
        $request = $this->request('game_shipyard_order', ShipyardController::class);
        for ($i = 0; $i < 50; $i++) {
            $this->assertNull($off->check($request, 42));
        }
        $on = $this->limits(true, 1);
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($on->check($request, 0));
        }
    }

    public function testDefaultsAreGenerous(): void
    {
        $container = self::container();
        $this->assertTrue($container->getParameter('app.game_rate_limit.enabled'));
        $this->assertGreaterThanOrEqual(30, $container->getParameter('app.game_rate_limit.action_burst'));
        $this->assertGreaterThanOrEqual(60, $container->getParameter('app.game_rate_limit.read_burst'));
    }

    // ------------------------------------------------------------------ board

    /** @return array{0: BoardController, 1: int} controller of a fresh account, its id */
    private function board(): array
    {
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'ACCOUNT']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('board_csrf', 'tok');
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0.0];
        $me = 900000 + random_int(1, 90000);
        self::container()->get('limiter.board_votes')->create((string) $me)->reset();
        self::container()->get('limiter.board_writes')->create((string) $me)->reset();
        $this->state->ACCOUNT = ['id' => $me, 'authlevel' => 0, 'db_deaktjava' => 0, 'lang' => 'en', 'accountname' => 'tester', 'timezone' => 'UTC', 'signalColors' => ''];
        $this->state->requestServer['REQUEST_METHOD'] = 'POST';
        $controller = (new BoardController())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setSecurityServices(self::container()->get(AuthRateLimits::class), self::container()->get(\App\Runtime\CanonicalUrl::class));
        $controller->setRouter(self::container()->get('router'));

        return [$controller, $me];
    }

    /** @param array<string, mixed> $input */
    private function act(BoardController $controller, string $method, array $input): string
    {
        $this->state->requestInput = $input + ['csrf' => 'tok'];
        $this->state->requestPost = $this->state->requestInput;
        $this->state->requestQuery = [];
        ob_start();
        try {
            $response = $controller->$method();
        } catch (EndOfRequest $end) {
            return ob_get_clean() . ($end->getResponse()?->getContent() ?? '') . $end->getOutput();
        }
        $echoed = (string) ob_get_clean();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response, 'the page should answer with a Response');

        return $echoed . $response->getContent();
    }

    private function insertPost(int $accountId): int
    {
        $this->db->insert('INSERT INTO %%BOARD_POSTS%% SET account_id = :a, title = :t, body = :b, created_at = :n, last_activity = :n2;', [':a' => $accountId, ':t' => 't', ':b' => 'b', ':n' => time(), ':n2' => time()]);

        return (int) $this->db->lastInsertId();
    }

    public function testOwnPostCannotBeVotedButOthersCan(): void
    {
        [$board, $me] = $this->board();
        $own = $this->insertPost($me);
        $foreign = $this->insertPost($me + 1);

        $out = json_decode($this->act($board, 'vote', ['type' => 'post', 'id' => $own, 'value' => 1]), true);
        $this->assertFalse($out['ok']);
        $this->assertSame($this->state->LNG['rl_board_err_self_vote'], $out['error']);
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT score FROM %%BOARD_POSTS%% WHERE id = :i;', [':i' => $own], 'score'));

        $out = json_decode($this->act($board, 'vote', ['type' => 'post', 'id' => $foreign, 'value' => 1]), true);
        $this->assertTrue($out['ok']);
        $this->assertSame(1, $out['score']);
    }

    public function testDailyVoteCap(): void
    {
        [$board, $me] = $this->board();
        $foreign = $this->insertPost($me + 1);
        $cap = (int) self::container()->getParameter('app.board_votes_per_day');
        $limiter = self::container()->get('limiter.board_votes')->create((string) $me);
        $limiter->consume($cap);

        $out = json_decode($this->act($board, 'vote', ['type' => 'post', 'id' => $foreign, 'value' => 1]), true);
        $this->assertFalse($out['ok']);
        $this->assertSame($this->state->LNG['rl_board_err_vote_cap'], $out['error']);
    }

    public function testDailyWriteCapRefusesPostsAndComments(): void
    {
        [$board, $me] = $this->board();
        $foreign = $this->insertPost($me + 1);
        $cap = (int) self::container()->getParameter('app.board_writes_per_day');
        self::container()->get('limiter.board_writes')->create((string) $me)->consume($cap);

        $out = $this->act($board, 'comment', ['post_id' => $foreign, 'parent_id' => 0, 'body' => 'hello']);
        $this->assertStringContainsString(htmlspecialchars($this->state->LNG['rl_board_err_write_cap'], ENT_QUOTES), $out);
        $out = $this->act($board, 'create', ['title' => 'A title', 'body' => 'hello world', 'flair' => 'general']);
        $this->assertStringContainsString(htmlspecialchars($this->state->LNG['rl_board_err_write_cap'], ENT_QUOTES), $out);
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOARD_COMMENTS%% WHERE account_id = :a;', [':a' => $me], 'c'));
    }
}
