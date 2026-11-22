<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\AbstractGameController;
use App\Controller\Game\BuddyListController;
use App\Controller\Game\BuildingsController;
use App\Controller\Game\NotesController;
use App\Controller\Game\PlayerCardController;
use App\Controller\Game\ResearchController;
use App\Controller\Game\ResourcesController;
use App\Controller\Game\ShipyardController;
use App\Game\Core\EndOfRequest;
use App\Game\Core\Language;
use App\Game\Core\Player\PlanetManager;
use App\Game\Core\Player\VacationMode;
use App\Game\Core\ResourceUpdate;
use App\Game\Cronjob\DailyCronjob;
use App\Game\Cronjob\InactiveMailCronjob;
use App\Game\Settings\SettingsRefused;
use App\Game\Settings\SettingsUpdater;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use App\Game\Enum\Mission;

/**
 * Regression tests of the economy / player pages review (round 2, R2-ECO-01 ... 16): vacation exit rebuilds production,
 * research cannot be torn down, the inactivity mail query, the daily level reset without retroactive production, locked user
 * rows, buddy request abuse guards, caps, the name race, abandon recalls foreign fleets, silo demolition with queued missiles.
 */
final class EconomyReviewFixesTest extends GameTestCase
{
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

    private function planets(): PlanetManager
    {
        return new PlanetManager($this->db, $this->configs, $this->state);
    }

    private function vacation(): VacationMode
    {
        return new VacationMode($this->db, $this->configs, $this->state);
    }

    /**
     * @param array<string, mixed> $user @param array<string, mixed> $planet @param array<string, mixed> $input
     * @param array<mixed> $input
     * @param array<string, mixed> $planet
     */
    private function prepare(array $user, array $planet, array $input, string $method = 'POST'): void
    {
        $this->state->USER = $user + ['messages' => 0, 'signalColors' => '', 'colors' => ''];
        $this->state->PLANET = $planet;
        $this->state->requestInput = $input;
        $this->state->requestPost = $method === 'POST' ? $input : [];
        $this->state->requestQuery = $method === 'GET' ? $input : [];
        $this->state->requestServer = ($this->state->requestServer ?? []);
        $this->state->requestServer['REQUEST_METHOD'] = $method;
    }

    /** runs the controller action and swallows the request end (redirect, message, JSON) */
    private function call(AbstractGameController $controller, string $action): void
    {
        $property = new \ReflectionProperty(AbstractGameController::class, 'ecoObj');
        $property->setValue($controller, new ResourceUpdate($this->db, $this->configs, $this->state));
        ob_start();
        try {
            $controller->withGameServices($this->db, $this->configs, $this->state);
            $controller->setRouter(self::container()->get('router'));
            $controller->$action();
        } catch (EndOfRequest) {
        } finally {
            ob_end_clean();
        }
    }

    // ---- 01 vacation exit

    public function testLeavingVacationModeRebuildsProductionAndEnergy(): void
    {
        $now = $this->state->runtime->timestamp;
        [$user, $planet] = $this->createPlayer(['urlaubs_modus' => 0], ['metal_mine' => 10, 'solar_plant' => 10, 'last_update' => $now - 100]);
        [$user, $planet] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, true);
        $this->assertGreaterThan(0, (float) $this->loadPlanet((int) $planet['id'])['metal_perhour']);

        $players = $this->vacation();
        $user = $this->loadUser((int) $user['id']);
        $planet = $this->loadPlanet((int) $planet['id']);
        $players->enable_vmode($user, $planet);
        $this->assertEquals(0, $this->loadPlanet((int) $planet['id'])['metal_perhour']);

        $this->db->update('UPDATE %%USERS%% SET `urlaubs_until` = 0, `urlaubs_start` = :t WHERE id = :id;', [':t' => $now - 100, ':id' => $user['id']]);
        $user = $this->loadUser((int) $user['id']);
        $planet = $this->loadPlanet((int) $planet['id']);
        $players->disable_vmode($user, $planet);
        $this->assertGreaterThan(0, (float) $this->loadPlanet((int) $planet['id'])['metal_perhour'], 'the request that ends the vacation already rebuilds production');

        // the next update, some time later, recalculates production and energy (before: stayed 0 until the hash changed)
        $this->db->update('UPDATE %%PLANETS%% SET last_update = :t WHERE id = :id;', [':t' => $now - 50, ':id' => $planet['id']]);
        [, $planet] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($this->loadUser((int) $user['id']), $this->loadPlanet((int) $planet['id']), true);
        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertGreaterThan(0, (float) $row['metal_perhour']);
        $this->assertGreaterThan(0, (float) $row['energy']);
    }

    // ---- 02 research destroy

    public function testResearchCannotBeTornDown(): void
    {
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([], [$resource[RESEARCH_LABORATORY] => 3, 'metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000]);

        $this->prepare($user, $planet, ['cmd' => 'destroy', 'tech' => ENERGY_TECH]);
        $this->call(new ResearchController(), 'show');

        $after = $this->loadUser((int) $user['id']);
        $this->assertSame('', (string) $after['b_tech_queue'], 'no queue entry');
        $this->assertEquals(0, $after['b_tech']);
        $this->assertEquals(100000, $this->loadPlanet((int) $planet['id'])['crystal'], 'nothing was paid');
    }

    // ---- 03 inactive mail query

    public function testInactiveMailQueryReturnsTheAccountAddress(): void
    {
        [$user] = $this->createPlayer(['onlinetime' => 1000]);

        $rows = (new InactiveMailCronjob($this->db, $this->configs, $this->state))->inactiveUsers(2000);

        $mine = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['id'] === (int) $user['id']));
        $this->assertCount(1, $mine);
        $this->assertStringEndsWith('@example.com', $mine[0]['email']);
        $this->assertSame([], array_filter((new InactiveMailCronjob($this->db, $this->configs, $this->state))->inactiveUsers(500), static fn(array $row): bool => (int) $row['id'] === (int) $user['id']));
    }

    // ---- 04 inactive levels are reset without retroactive production

    public function testInactiveLevelResetDoesNotCreditProductionRetroactively(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->setUniverseConfig(['uni_status' => STATUS_OPEN]);
        [$user, $planet] = $this->createPlayer(['onlinetime' => $now - 20 * 86400], [
            'metal_mine' => 10, 'solar_plant' => 10, 'metal' => 0, 'metal_mine_porcent' => '0', 'last_update' => $now - 10 * 86400, 'metal_max' => 1e9, 'eco_hash' => '',
        ]);
        $this->db->update("UPDATE %%PLANETS%% SET metal_mine_porcent = '0' WHERE id = :id;", [':id' => $planet['id']]);

        (new DailyCronjob($this->db, $this->configs, $this->state))->updateInactiveMines(1);

        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('10', (string) $row['metal_mine_porcent'], 'the level is back at 100 %');
        $this->assertGreaterThanOrEqual($now - 5, (int) $row['last_update'], 'the stock was brought up to now first');
        // 10 days at 0 % mine: basic income only (20 * 2500/2500 per hour), not 10 days of a level-10 mine
        $this->assertLessThan(20 * 24 * 10 * 1.1 + 1, (float) $row['metal']);
    }

    // ---- 05 resources save uses the locked user row

    public function testResourcesSaveDoesNotOverwriteTheResearchQueueWithAStaleUser(): void
    {
        [$user, $planet] = $this->createPlayer([], ['last_update' => $this->state->runtime->timestamp - 100]);
        $queue = '[[113,1,60,' . ($this->state->runtime->timestamp + 3600) . ',' . $planet['id'] . ',{}]]';
        $this->db->update('UPDATE %%USERS%% SET b_tech = :t, b_tech_id = 113, b_tech_planet = :p, b_tech_queue = :q WHERE id = :id;', [
            ':t' => $this->state->runtime->timestamp + 3600, ':p' => $planet['id'], ':q' => $queue, ':id' => $user['id'],
        ]);
        // the page-start copy knows nothing of the research (a second tab started it meanwhile)
        $stale = $user;

        $this->prepare($stale, $planet, ['prod' => [1 => '5']]);
        $this->call(new ResourcesController(), 'send');

        $after = $this->loadUser((int) $user['id']);
        $this->assertSame($queue, (string) $after['b_tech_queue']);
        $this->assertSame(113, (int) $after['b_tech_id']);
    }

    // ---- 07 buddy requests

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function buddyFixture(): array
    {
        [$user, $planet] = $this->createPlayer();
        $this->state->USER = $user;

        return [$user, $planet];
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     */
    private function sendBuddyRequest(array $user, array $planet, int $targetId): void
    {
        $this->prepare($user, $planet, ['id' => $targetId, 'text' => 'hi']);
        $this->call(new BuddyListController(), 'send');
    }

    private function buddyRows(int $senderId): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BUDDY%% WHERE sender = :id;', [':id' => $senderId], 'c');
    }

    public function testBuddyRequestIsRefusedWhenTheTargetBlocksPrivateMessages(): void
    {
        [$user, $planet] = $this->buddyFixture();
        [$target] = $this->createPlayer();
        $this->db->update('UPDATE %%USERS_SETTINGS%% SET settings_blockPM = 1 WHERE user_id = :id;', [':id' => $target['id']]);

        $this->sendBuddyRequest($user, $planet, (int) $target['id']);

        $this->assertSame(0, $this->buddyRows((int) $user['id']));
    }

    public function testBuddyRequestsAreRateLimitedAndWithdrawingSendsNoMessage(): void
    {
        [$user, $planet] = $this->buddyFixture();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            [$target] = $this->createPlayer();
            $ids[] = (int) $target['id'];
            $this->sendBuddyRequest($user, $planet, (int) $target['id']);
        }
        $this->assertSame(5, $this->buddyRows((int) $user['id']), 'the sixth request within the window is refused');

        // a pending request cannot be sent twice
        $this->sendBuddyRequest($user, $planet, $ids[0]);
        $this->assertSame(5, $this->buddyRows((int) $user['id']));

        // the sender withdrawing his request tells nobody; the recipient rejecting does
        $buddy = $this->db->selectSingle('SELECT id FROM %%BUDDY%% WHERE sender = :s AND owner = :o;', [':s' => $user['id'], ':o' => $ids[0]]);
        $messages = static fn(self $t, int $userId): int => (int) $t->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TO_MESSAGES%% WHERE user_id = :id;', [':id' => $userId], 'c');
        $before = $messages($this, $ids[0]);
        $this->prepare($user, $planet, ['id' => $buddy['id']]);
        $this->call(new BuddyListController(), 'delete');
        $this->assertSame($before, $messages($this, $ids[0]), 'withdrawing sends no message');
        $this->assertSame(4, $this->buddyRows((int) $user['id']));
    }

    // ---- 08 caps

    public function testNotesAreCappedInCountAndLength(): void
    {
        [$user, $planet] = $this->createPlayer();
        $this->prepare($user, $planet, ['id' => 0, 'priority' => 1, 'title' => 't', 'text' => str_repeat('a', 20000)]);
        $this->call(new NotesController(), 'insert');
        $this->assertSame(10000, strlen((string) $this->db->selectSingle('SELECT text FROM %%NOTES%% WHERE owner = :id;', [':id' => $user['id']], 'text')));

        for ($i = 0; $i < 110; $i++) {
            $this->prepare($user, $planet, ['id' => 0, 'priority' => 1, 'title' => 't' . $i, 'text' => 'x']);
            $this->call(new NotesController(), 'insert');
        }
        $this->assertSame(100, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%NOTES%% WHERE owner = :id;', [':id' => $user['id']], 'c'));
    }

    public function testBlockListNeedsAnExistingOtherPlayerAndIsCapped(): void
    {
        [$user, $planet] = $this->createPlayer();
        [$target] = $this->createPlayer();
        $count = fn(): int => (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_BLOCKLIST%% WHERE blocking_user = :id;', [':id' => $user['id']], 'c');

        foreach ([999999999, (int) $user['id'], -5] as $bad) {
            $this->prepare($user, $planet, ['id' => $bad, 'blockDm' => 1, 'blockTrade' => 0]);
            $this->call(new PlayerCardController(), 'toggleBlock');
        }
        $this->assertSame(0, $count(), 'unknown ids and the own id are refused');

        $this->prepare($user, $planet, ['id' => $target['id'], 'blockDm' => 7, 'blockTrade' => 0]);
        $this->call(new PlayerCardController(), 'toggleBlock');
        $this->assertSame(1, $count());
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT block_dm FROM %%USERS_BLOCKLIST%% WHERE blocking_user = :id;', [':id' => $user['id']], 'block_dm'), 'the flag is 0/1');
    }

    // ---- 09 name race

    public function testLockedRowsDecideTheNameCooldown(): void
    {
        [$user] = $this->createPlayer();
        $now = $this->state->runtime->timestamp;
        $this->db->update('UPDATE %%USERS%% SET uctime = :t WHERE id = :id;', [':t' => $now, ':id' => $user['id']]);
        $this->state->USER = ['uctime' => 0] + $user; // stale copy: "never renamed"

        $updater = new SettingsUpdater($this->db, $this->configs, $this->state);
        $lock = new \ReflectionMethod($updater, 'lockPlayerRows');
        $lock->invoke($updater);
        $this->assertEquals($now, $this->state->USER['uctime'], 'the cooldown reads the locked row');

        $change = new \ReflectionMethod($updater, 'changeUsername');
        $this->expectException(SettingsRefused::class);
        $change->invoke($updater, 'brandnewname');
    }

    public function testPlayerNamesAreUniquePerUniverse(): void
    {
        [$first] = $this->createPlayer();
        [$second] = $this->createPlayer();
        $this->expectException(\Throwable::class);
        $this->db->update('UPDATE %%USERS%% SET username = :n WHERE id = :id;', [':n' => $first['username'], ':id' => $second['id']]);
    }

    // ---- 10 abandon recalls foreign fleets

    public function testAbandoningAPlanetRecallsForeignFleetsHeadingThere(): void
    {
        [$owner] = $this->createPlayer();
        $colonyId = $this->planets()->createPlanet(2, 21, 6, 1, (int) $owner['id'], 'Colony');
        $colony = $this->loadPlanet($colonyId);
        [$sender, $senderPlanet] = $this->createPlayer([], ['small_ship_cargo' => 20, 'metal' => 10000, 'crystal' => 10000, 'deuterium' => 10000]);
        $now = $this->state->runtime->timestamp;
        $fleetId = (new \App\Game\Core\FleetLauncher($this->db, $this->configs, $this->state))->sendFleet(
            [202 => 5],
            Mission::Transport->value,
            (int) $sender['id'],
            (int) $senderPlanet['id'],
            (int) $senderPlanet['galaxy'],
            (int) $senderPlanet['system'],
            (int) $senderPlanet['planet'],
            1,
            (int) $owner['id'],
            $colonyId,
            (int) $colony['galaxy'],
            (int) $colony['system'],
            (int) $colony['planet'],
            1,
            [901 => 1000, 902 => 0, 903 => 0],
            $now + 600,
            0,
            $now + 1200,
            0,
            0,
            0,
            50.0,
        );

        $this->planets()->deletePlanet($colonyId);

        $fleet = $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        $this->assertSame(FLEET_RETURN, (int) $fleet['status'], 'the foreign transport turned around');
    }

    // ---- 11 silo demolition and queued missiles

    public function testSiloCannotBeDemolishedWhileMissilesAreQueued(): void
    {
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([], [$resource[SHIPYARD] => 2, $resource[SILO] => 3, 'b_hangar_id' => '[[' . INTERCEPTOR_MISSILE . ',4]]']);
        $this->prepare($user, $planet, []);
        $controller = (new BuildingsController())->withGameServices($this->db, $this->configs, $this->state);
        $can = new \ReflectionMethod($controller, 'canQueueBuilding');

        $this->assertFalse($can->invoke($controller, SILO, false), 'queued missiles block the demolition');
        $this->assertTrue($can->invoke($controller, SILO, true));

        $planet['b_hangar_id'] = '';
        $this->state->PLANET = $planet;
        $this->assertTrue($can->invoke($controller, SILO, false), 'without missiles it is allowed');
    }

    // ---- 12 repaired deltas are reset

    public function testRepairedDeltasAreResetAfterASave(): void
    {
        [$user, $planet] = $this->createPlayer();
        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        $eco->setData($user, $planet);
        $st = (new \ReflectionProperty($eco, 'st'))->getValue($eco);
        $st->time = $this->state->runtime->timestamp;
        $st->repaired = [206 => 3];
        (new \ReflectionMethod($eco, 'SavePlanetToDB'))->invoke($eco, $user, $planet);

        $this->assertSame([], $st->repaired);
    }

    // ---- 13 shipyard re-checks on the locked row

    public function testShipyardRefusesAnOrderWhileTheShipyardIsUpgrading(): void
    {
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([$resource[COMBUSTION_TECH] => 2], [$resource[SHIPYARD] => 2, 'metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000]);
        // the upgrade is queued after the page started: the page-start copy does not know it
        $this->db->update('UPDATE %%PLANETS%% SET b_building = :t, b_building_id = :q WHERE id = :id;', [
            ':t' => $this->state->runtime->timestamp + 600,
            ':q' => '[[' . SHIPYARD . ',3,600,' . ($this->state->runtime->timestamp + 600) . ',"build",{}]]',
            ':id' => $planet['id'],
        ]);

        $this->prepare($user, $planet, ['fmenge' => [202 => 3]]);
        $this->call(new ShipyardController(), 'show');

        $row = $this->loadPlanet((int) $planet['id']);
        $this->assertSame('', (string) $row['b_hangar_id']);
        $this->assertEquals(100000, $row['metal'], 'nothing was paid');
    }

    // ---- 15 moon of an abandoned planet

    public function testNoMoonIsCreatedForADestroyedPlanet(): void
    {
        [$user] = $this->createPlayer();
        $players = $this->planets();
        $colonyId = $players->createPlanet(2, 22, 6, 1, (int) $user['id'], 'Colony');
        $this->db->update('UPDATE %%PLANETS%% SET destruyed = :t WHERE id = :id;', [':t' => time() + 86400, ':id' => $colonyId]);

        $this->assertEquals(0, $players->createMoon(1, 2, 22, 6, (int) $user['id'], 10.0));
        $this->assertEquals(0, $players->createMoon(1, 2, 22, 7, (int) $user['id'], 10.0), 'no planet at all');
    }

    // ---- 16 shipyard element keys

    public function testShipyardKeysAreIntegerIds(): void
    {
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([$resource[COMBUSTION_TECH] => 2], [$resource[SHIPYARD] => 2, 'metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000]);

        $this->prepare($user, $planet, ['fmenge' => ['202.0' => 3, '202abc' => 3, 202 => 2]]);
        $this->call(new ShipyardController(), 'order');

        $queue = (string) $this->loadPlanet((int) $planet['id'])['b_hangar_id'];
        $this->assertSame('[[202,2]]', str_replace(['.0', ' '], '', $queue), 'only the clean key is queued: ' . $queue);
    }
}
