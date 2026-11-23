<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\FarmListController;
use App\Controller\Game\LiveEventsController;
use App\Game\Core\Language;
use App\Game\Core\SpyReportTools;
use App\Game\Enum\MessageType;
use App\Game\Notifications\NotificationDetector;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The farm list query (planet join by id), its paging, the shipyard finder with a malformed queue and the read-only
 * events poll.
 */
final class FarmListAndPollsTest extends GameTestCase
{
    private const NOW = 1900000000;

    protected function setUp(): void
    {
        parent::setUp();
        $lng = new Language($this->configs, 'en');
        $lng->includeData(['L18N', 'INGAME', 'TECH', 'FLEET', 'CUSTOM']);
        $this->state->LNG = $lng;
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->time = ['start' => microtime(true), 'end_common' => microtime(true), 'db' => 0];
    }

    private function spyReport(int $spy, int $targetPlanet, int $time): int
    {
        $text = json_encode(['time' => $time, 'targetInactive' => false, 'targetPlanet' => ['galaxy' => 1, 'system' => 1, 'planet' => 1, 'planet_type' => 1], 'targetUserName' => 'x', 'spyData' => []]);
        $this->db->insert('INSERT INTO %%MESSAGES%% (message_group, message_owner, message_sender, message_time, message_type, message_from, message_subject, message_text, message_unread, message_universe) VALUES (:g, :u, 0, :t, :type, \'f\', \'s\', :x, 1, 1);', [':g' => 'm_' . $targetPlanet, ':u' => $spy, ':t' => $time, ':type' => MessageType::SpyReport->value, ':x' => $text]);
        $id = (int) $this->db->lastInsertId();
        $this->db->insert('INSERT INTO %%USERS_TO_MESSAGES%% (message_id, user_id, sender_id, created_at) VALUES (:m, :u, NULL, :t);', [':m' => $id, ':u' => $spy, ':t' => $time]);

        return $id;
    }

    public function testLatestReportsPicksTheNewestReportPerTargetPlanet(): void
    {
        [$spy] = $this->createPlayer();
        [, $targetA] = $this->createPlayer();
        [, $targetB] = $this->createPlayer();
        $this->spyReport((int) $spy['id'], (int) $targetA['id'], self::NOW - 500);
        $newestA = $this->spyReport((int) $spy['id'], (int) $targetA['id'], self::NOW - 100);
        $onlyB = $this->spyReport((int) $spy['id'], (int) $targetB['id'], self::NOW - 300);

        $tools = new SpyReportTools($this->db, $this->configs, $this->state);
        $rows = $tools->latestReports((int) $spy['id'], 1, 100);

        self::assertSame([$newestA, $onlyB], array_map(static fn(array $r): int => (int) $r['message_id'], $rows));
        self::assertSame([(int) $targetA['id'], (int) $targetB['id']], array_map(static fn(array $r): int => (int) $r['planet_id'], $rows));
    }

    public function testFarmListClampsThePageAndKeepsTheRows(): void
    {
        [$spy, $planet] = $this->createPlayer();
        [, $target] = $this->createPlayer();
        $this->spyReport((int) $spy['id'], (int) $target['id'], self::NOW - 100);
        $this->state->USER = $spy + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestServer['REQUEST_METHOD'] = 'GET';
        $this->state->requestQuery = [];
        $this->state->requestInput = ['page' => 99];
        $controller = (new FarmListController())->withGameServices($this->db, $this->configs, $this->state);

        $html = (string) $controller->show()->getContent();

        self::assertSame(200, FarmListController::PAGE_SIZE);
        self::assertStringContainsString('[1:1:1]', $html, 'a page past the end shows the last page');
        self::assertStringNotContainsString('farm_next', $html);
    }

    public function testShipyardFinderSurvivesAMalformedQueue(): void
    {
        [$bad, $badPlanet] = $this->createPlayer([], ['hangar' => 4]);
        [$good, $goodPlanet] = $this->createPlayer([], ['hangar' => 4, 'robot_factory' => 2]);
        foreach ([$bad, $good] as $u) {
            $this->db->update('UPDATE %%USERS_SETTINGS%% SET notify_queue_shipyard = 1 WHERE user_id = :id;', [':id' => $u['id']]);
        }
        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = 0, b_hangar_id = \'not json\', last_update = :t WHERE id = :id;', [':t' => self::NOW - 100, ':id' => $badPlanet['id']]);
        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = 0, b_hangar_id = :q, last_update = :t WHERE id = :id;', [':q' => json_encode([[202, 1]]), ':t' => self::NOW - 100, ':id' => $goodPlanet['id']]);
        $detector = new NotificationDetector($this->db, $this->configs, $this->state);
        $user = $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE id = :id;', [':id' => $good['id']]);
        $planet = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id = :id;', [':id' => $goodPlanet['id']]);
        $end = $detector->hangarEndTime($user, $planet);
        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = :b WHERE id = :id;', [':b' => $end - (self::NOW - 50), ':id' => $goodPlanet['id']]);

        $events = array_values(array_filter($detector->finishedShipyards(self::NOW), static fn(array $e): bool => in_array($e['user_id'], [(int) $bad['id'], (int) $good['id']], true)));

        self::assertCount(1, $events);
        self::assertSame((int) $good['id'], $events[0]['user_id']);
    }

    public function testEventsPollDoesNotWriteThePlanet(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal_mine' => 5]);
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => $this->state->runtime->timestamp - 5000, ':id' => $planet['id']]);
        $planet = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id = :id;', [':id' => $planet['id']]);
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestServer['REQUEST_METHOD'] = 'GET';
        $this->state->requestInput = [];
        $this->state->requestQuery = [];
        $controller = (new LiveEventsController())->withGameServices($this->db, $this->configs, $this->state);

        $controller->initialize();
        $response = $controller->events();

        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('now', $payload);
        $after = $this->db->selectSingle('SELECT last_update, metal FROM %%PLANETS%% WHERE id = :id;', [':id' => $planet['id']]);
        self::assertSame((int) $planet['last_update'], (int) $after['last_update']);
        self::assertSame((int) $planet['metal'], (int) $after['metal']);
    }
}
