<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Enum\Mission;
use App\Game\Notifications\NotificationDetector;
use App\Game\Notifications\NotificationDispatcher;
use App\Game\Notifications\NotificationTransport;
use App\Tests\Support\GameTestCase;

/**
 * The notification dispatcher (preferences, channels, expired subscriptions) with a recording transport, and the
 * detection queries (hostile fleets, returned fleets, finished queues, duplicates).
 */
final class NotificationsTest extends GameTestCase
{
    private const NOW = 1900000000;

    private function transport(string $pushResult = NotificationTransport::PUSH_OK): object
    {
        return new class ($pushResult) implements NotificationTransport {
            /** @var list<array{string, string}> */
            public array $pushes = [];
            /** @var list<array{string, string}> */
            public array $discord = [];

            public function __construct(private string $result) {}

            public function sendPush(array $subscription, string $payload): string
            {
                $this->pushes[] = [$subscription['endpoint'], $payload];

                return $this->result;
            }

            public function sendDiscord(string $webhookUrl, string $content): bool
            {
                $this->discord[] = [$webhookUrl, $content];

                return true;
            }
        };
    }

    private function subscribe(int $userId, string $endpoint = 'https://push.example/abc'): void
    {
        $this->db->insert('INSERT INTO %%PUSH_SUBSCRIPTIONS%% (user_id, endpoint, p256dh, auth, created_at) VALUES (:u, :e, \'p\', \'a\', 1);', [':u' => $userId, ':e' => $endpoint]);
    }

    private function setPrefs(int $userId, array $prefs): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => "`$c` = :$c", array_keys($prefs)));
        $this->db->update("UPDATE %%USERS_SETTINGS%% SET $set WHERE user_id = :id;", [':id' => $userId] + array_combine(array_map(static fn(string $c): string => ':' . $c, array_keys($prefs)), array_values($prefs)));
    }

    private function dispatcher(object $transport): NotificationDispatcher
    {
        return new NotificationDispatcher($this->db, $this->configs, $this->state, $transport, 'https://game.example');
    }

    public function testDispatcherSendsOnlyWhatThePlayerSwitchedOn(): void
    {
        [$user] = $this->createPlayer();
        $this->subscribe((int) $user['id']);
        $transport = $this->transport();
        $dispatcher = $this->dispatcher($transport);

        $dispatcher->notify((int) $user['id'], NotificationDispatcher::TYPE_ATTACK, 'Attack', 'Body', 'game');
        $dispatcher->notify((int) $user['id'], NotificationDispatcher::TYPE_SPY, 'Spy', 'Body', 'game');

        self::assertCount(1, $transport->pushes, 'attack is on by default, espionage off');
        $payload = json_decode($transport->pushes[0][1], true);
        self::assertSame('Attack', $payload['title']);
        self::assertSame('https://game.example/game', $payload['url']);

        $this->setPrefs((int) $user['id'], ['notify_spy' => 1]);
        $dispatcher->notify((int) $user['id'], NotificationDispatcher::TYPE_SPY, 'Spy', 'Body', 'game');
        self::assertCount(2, $transport->pushes);
    }

    public function testDispatcherPostsToTheDiscordWebhookOnlyWhenValid(): void
    {
        [$user] = $this->createPlayer();
        $transport = $this->transport();
        $dispatcher = $this->dispatcher($transport);

        $this->setPrefs((int) $user['id'], ['notify_discord_webhook' => 'https://evil.test/api/webhooks/1/x']);
        $dispatcher->notify((int) $user['id'], NotificationDispatcher::TYPE_ATTACK, 'Attack', 'Body', 'game');
        self::assertSame([], $transport->discord);

        $this->setPrefs((int) $user['id'], ['notify_discord_webhook' => 'https://discord.com/api/webhooks/1/x']);
        $dispatcher->notify((int) $user['id'], NotificationDispatcher::TYPE_ATTACK, 'Attack', 'Body', 'game');
        self::assertCount(1, $transport->discord);
        self::assertStringContainsString('Attack', $transport->discord[0][1]);
    }

    public function testExpiredSubscriptionsAreDropped(): void
    {
        [$user] = $this->createPlayer();
        $this->subscribe((int) $user['id']);
        $this->dispatcher($this->transport(NotificationTransport::PUSH_GONE))->notify((int) $user['id'], NotificationDispatcher::TYPE_ATTACK, 'A', 'B', 'game');

        self::assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) c FROM %%PUSH_SUBSCRIPTIONS%% WHERE user_id = :u;', [':u' => $user['id']], 'c'));
    }

    private function fleet(int $owner, int $target, int $mission, int $arrival, int $start = self::NOW - 60, int $group = 0, int $status = 0): int
    {
        $this->db->insert('INSERT INTO %%FLEETS%% SET `group` = :g, universe = 1, mission = :m, owner = :o, target_owner = :t, ship_amount = 5, ships = \'202,5\', start_time = :s,
            arrival_time = :a, return_time = :r, start_id = 1, end_id = 1, status = :st;', [':g' => $group, ':m' => $mission, ':o' => $owner, ':t' => $target, ':s' => $start, ':a' => $arrival, ':r' => $arrival * 2 - $start, ':st' => $status]);

        return $this->db->lastInsertId();
    }

    private function detector(): NotificationDetector
    {
        return new NotificationDetector($this->db, $this->configs, $this->state);
    }

    public function testIncomingHostileFleetsRespectPreferencesAndGroups(): void
    {
        [$victim] = $this->createPlayer();
        [$attacker] = $this->createPlayer();
        $v = (int) $victim['id'];
        $a = (int) $attacker['id'];
        $this->setPrefs($v, ['notify_spy' => 0]);

        $this->fleet($a, $v, Mission::Attack->value, self::NOW + 600);
        $this->fleet($a, $v, Mission::Acs->value, self::NOW + 600, group: 77);
        $this->fleet($a, $v, Mission::Acs->value, self::NOW + 600, group: 77);
        $this->fleet($a, $v, Mission::Spy->value, self::NOW + 600);
        $this->fleet($a, $v, Mission::Transport->value, self::NOW + 600);
        $this->fleet($a, $v, Mission::Attack->value, self::NOW + 600, status: 1);
        $this->fleet($a, $v, Mission::Attack->value, self::NOW + 600, start: self::NOW - 86400);
        $this->fleet($v, $v, Mission::Attack->value, self::NOW + 600);

        $events = $this->detector()->incomingFleets(self::NOW);
        $mine = array_values(array_filter($events, static fn(array $e): bool => $e['user_id'] === $v));

        self::assertCount(2, $mine, 'one single attack and one ACS group; spying is off, transport/returning/old/own fleets never count');
        self::assertSame(NotificationDispatcher::TYPE_ATTACK, $mine[0]['type']);

        $this->setPrefs($v, ['notify_spy' => 1]);
        $withSpy = array_filter($this->detector()->incomingFleets(self::NOW), static fn(array $e): bool => $e['user_id'] === $v);
        self::assertCount(3, $withSpy);
    }

    public function testSeenEventsAreNotReportedAgain(): void
    {
        [$victim] = $this->createPlayer();
        [$attacker] = $this->createPlayer();
        $this->fleet((int) $attacker['id'], (int) $victim['id'], Mission::Attack->value, self::NOW + 600);
        $detector = $this->detector();

        $first = array_filter($detector->incomingFleets(self::NOW), static fn(array $e): bool => $e['user_id'] === (int) $victim['id']);
        self::assertCount(1, $first);
        $detector->markSeen($first, self::NOW);

        self::assertSame([], array_filter($detector->incomingFleets(self::NOW + 30), static fn(array $e): bool => $e['user_id'] === (int) $victim['id']));
    }

    public function testReturnedFleetIsReportedOnceItsRowIsGone(): void
    {
        [$owner] = $this->createPlayer();
        $o = (int) $owner['id'];
        $this->setPrefs($o, ['notify_fleet_return' => 1]);
        $fleetId = $this->fleet($o, 0, Mission::Transport->value, self::NOW - 100, start: self::NOW - 200, status: 1);

        self::assertSame([], $this->detector()->returnedFleets(self::NOW - 100), 'still flying: only remembered');
        $this->db->delete('DELETE FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        $events = $this->detector()->returnedFleets(self::NOW);
        self::assertCount(1, $events);
        self::assertSame('ret:' . $fleetId, $events[0]['key']);
        self::assertSame([], $this->detector()->returnedFleets(self::NOW + 60), 'the watch row is gone');
    }

    public function testFleetThatVanishedBeforeItsReturnTimeIsNotReported(): void
    {
        [$owner] = $this->createPlayer();
        $o = (int) $owner['id'];
        $this->setPrefs($o, ['notify_fleet_return' => 1]);
        $fleetId = $this->fleet($o, 0, Mission::Colonisation->value, self::NOW + 100, start: self::NOW - 200);
        $this->detector()->returnedFleets(self::NOW);
        $this->db->delete('DELETE FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);

        self::assertSame([], $this->detector()->returnedFleets(self::NOW + 10));
    }

    public function testFinishedBuildingAndResearchAreFoundOnce(): void
    {
        [$user, $planet] = $this->createPlayer();
        $u = (int) $user['id'];
        $this->setPrefs($u, ['notify_queue_build' => 1, 'notify_queue_research' => 1]);
        $this->db->update('UPDATE %%PLANETS%% SET b_building = :t, b_building_id = :q WHERE id = :id;', [':t' => self::NOW - 30, ':q' => json_encode([[1, 3, 100, self::NOW - 30, 'build']]), ':id' => $planet['id']]);
        $this->db->update('UPDATE %%USERS%% SET b_tech = :t, b_tech_id = 106 WHERE id = :id;', [':t' => self::NOW - 20, ':id' => $u]);

        $detector = $this->detector();
        $buildings = array_filter($detector->finishedBuildings(self::NOW), static fn(array $e): bool => $e['user_id'] === $u);
        $research = array_filter($detector->finishedResearch(self::NOW), static fn(array $e): bool => $e['user_id'] === $u);
        self::assertCount(1, $buildings);
        self::assertSame(1, array_values($buildings)[0]['data']['element']);
        self::assertSame(3, array_values($buildings)[0]['data']['level']);
        self::assertCount(1, $research);

        $detector->markSeen([...$buildings, ...$research], self::NOW);
        self::assertSame([], array_filter($detector->finishedBuildings(self::NOW + 60), static fn(array $e): bool => $e['user_id'] === $u));
        self::assertSame([], array_filter($detector->finishedResearch(self::NOW + 60), static fn(array $e): bool => $e['user_id'] === $u));

        $this->setPrefs($u, ['notify_queue_build' => 0, 'notify_queue_research' => 0]);
        self::assertSame([], array_filter($detector->finishedResearch(self::NOW + 60), static fn(array $e): bool => $e['user_id'] === $u));
    }

    public function testFinishedShipyardQueueIsFound(): void
    {
        [$user, $planet] = $this->createPlayer([], ['hangar' => 4, 'robot_factory' => 2]);
        $u = (int) $user['id'];
        $this->setPrefs($u, ['notify_queue_shipyard' => 1]);
        // a one ship queue that finished 50 seconds before "now": b_hangar (elapsed build time) is set to match
        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = 0, b_hangar_id = :q, last_update = :t WHERE id = :id;', [':q' => json_encode([[202, 1]]), ':t' => self::NOW - 100, ':id' => $planet['id']]);
        $endWithoutProgress = $this->detector()->hangarEndTime($this->loadUser($u), $this->loadPlanet((int) $planet['id']));
        self::assertGreaterThan(self::NOW - 100, $endWithoutProgress);
        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = :b WHERE id = :id;', [':b' => $endWithoutProgress - (self::NOW - 50), ':id' => $planet['id']]);

        $events = array_filter($this->detector()->finishedShipyards(self::NOW), static fn(array $e): bool => $e['user_id'] === $u);
        self::assertCount(1, $events);
        self::assertSame(NotificationDispatcher::TYPE_QUEUE_SHIPYARD, array_values($events)[0]['type']);

        $this->db->update('UPDATE %%PLANETS%% SET b_hangar = 0 WHERE id = :id;', [':id' => $planet['id']]);
        self::assertSame([], array_filter($this->detector()->finishedShipyards(self::NOW), static fn(array $e): bool => $e['user_id'] === $u), 'a queue that just started is not finished');
    }

    private function spyReport(int $spy, int $targetPlanet, int $time, bool $inactive): void
    {
        $text = json_encode(['time' => $time, 'targetInactive' => $inactive, 'targetPlanet' => ['galaxy' => 1, 'system' => 1, 'planet' => 1, 'planet_type' => 1], 'targetUserName' => 'x', 'spyData' => []]);
        $this->db->insert('INSERT INTO %%MESSAGES%% (message_group, message_owner, message_sender, message_time, message_type, message_from, message_subject, message_text, message_unread, message_universe) VALUES (:g, :u, 0, :t, :type, \'f\', \'s\', :x, 1, 1);', [':g' => 'm_' . $targetPlanet, ':u' => $spy, ':t' => $time, ':type' => \App\Game\Enum\MessageType::SpyReport->value, ':x' => $text]);
        $this->db->insert('INSERT INTO %%USERS_TO_MESSAGES%% (message_id, user_id, sender_id, created_at) VALUES (:m, :u, NULL, :t);', [':m' => $this->db->lastInsertId(), ':u' => $spy, ':t' => $time]);
    }

    public function testFarmThatWokeUpIsReportedOnce(): void
    {
        [$spy] = $this->createPlayer();
        [$target, $targetPlanet] = $this->createPlayer();
        $s = (int) $spy['id'];
        $t = (int) $target['id'];
        $this->setPrefs($s, ['notify_farm_awake' => 1]);
        $this->spyReport($s, (int) $targetPlanet['id'], self::NOW - 86400, true);
        $mine = static fn(array $e): bool => $e['user_id'] === $s;

        $this->db->update('UPDATE %%USERS%% SET onlinetime = :t, urlaubs_modus = 0 WHERE id = :id;', [':t' => self::NOW - 7 * 86400, ':id' => $t]);
        self::assertSame([], array_filter($this->detector()->awakeFarms(self::NOW), $mine), 'still inactive');

        $this->db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => self::NOW - 60, ':id' => $t]);
        $events = array_values(array_filter($this->detector()->awakeFarms(self::NOW), $mine));
        self::assertCount(1, $events);
        self::assertSame(NotificationDispatcher::TYPE_FARM_AWAKE, $events[0]['type']);
        self::assertSame($t, $events[0]['data']['owner']);

        $this->detector()->markSeen($events, self::NOW);
        self::assertSame([], array_filter($this->detector()->awakeFarms(self::NOW + 60), $mine), 'second run');

        $this->setPrefs($s, ['notify_farm_awake' => 0]);
        self::assertSame([], array_filter($this->detector()->awakeFarms(self::NOW + 60), $mine), 'preference off');
    }

    public function testFarmThatWasActiveAtReportTimeIsNotReported(): void
    {
        [$spy] = $this->createPlayer();
        [$target, $targetPlanet] = $this->createPlayer();
        $s = (int) $spy['id'];
        $this->setPrefs($s, ['notify_farm_awake' => 1]);
        $this->spyReport($s, (int) $targetPlanet['id'], self::NOW - 86400, false);
        $this->db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => self::NOW - 60, ':id' => $target['id']]);

        self::assertSame([], array_filter($this->detector()->awakeFarms(self::NOW), static fn(array $e): bool => $e['user_id'] === $s));
    }
}
