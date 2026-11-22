<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Game\AbstractGameController;
use App\Controller\Game\BuildingsController;
use App\Controller\Game\ResearchController;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\StoredData;
use App\Tests\Support\GameTestCase;

/**
 * Pins the build, research and shipyard queues: what adding, cancelling and removing queue entries writes into
 * `planets.b_building_id` / `users.b_tech_queue` (as the serialize() string of the decoded structure; the on-disk format is JSON, see shape()), how the
 * resources are deducted and refunded, the queue limits, how the queues are persisted, and how ResourceUpdate advances
 * a queue when time passes (finished entries raise levels, the next entry starts and is paid).
 *
 * Why: the queues used to be stored as PHP serialize() strings; the legacy renewal moved them
 * to JSON with a migration; these tests pin today's structure and semantics so the new format can be proven equivalent.
 *
 * The controllers' queue methods are private game logic; they are driven by reflection on the real controller classes,
 * exactly as the page actions call them (state->USER / state->PLANET are the shared references the game uses).
 */
final class QueueTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'building first: serialized' => 'a:1:{i:0;a:6:{i:0;i:1;i:1;i:1;i:2;d:54;i:3;d:T+54;i:4;s:5:"build";i:5;a:2:{i:901;d:60;i:902;d:15;}}}',
        'building first: b_building relative' => 54.0,
        'building first: resources in memory' => [940.0, 485.0, 0.0],
        'building several: serialized' => 'a:4:{i:0;a:6:{i:0;i:1;i:1;i:5;i:2;d:182;i:3;d:T+182;i:4;s:5:"build";i:5;a:2:{i:901;d:303.75;i:902;d:75.9375;}}i:1;a:5:{i:0;i:1;i:1;i:6;i:2;d:273;i:3;d:T+455;i:4;s:5:"build";}i:2;a:5:{i:0;i:4;i:PLANET;i:6;i:2;d:69;i:3;d:T+524;i:4;s:7:"destroy";}i:3;a:5:{i:0;i:2;i:1;i:1;i:2;d:34;i:3;d:T+558;i:4;s:5:"build";}}',
        'building several: count' => 4,
        'cancel building: serialized' => 'a:2:{i:0;a:6:{i:0;i:2;i:1;i:1;i:2;d:34;i:3;d:T+34;i:4;s:5:"build";i:5;a:2:{i:901;d:48;i:902;d:24;}}i:1;a:5:{i:0;i:3;i:1;i:1;i:2;d:144;i:3;d:T+178;i:4;s:5:"build";}}',
        'cancel building: refunded resources' => [255.75, 51.9375, 0.0],
        'remove building #3: serialized' => 'a:3:{i:0;a:6:{i:0;i:1;i:1;i:1;i:2;d:36;i:3;d:T+36;i:4;s:5:"build";i:5;a:2:{i:901;d:60;i:902;d:15;}}i:1;a:5:{i:0;i:2;i:1;i:1;i:2;d:34;i:3;d:T+70;i:4;s:5:"build";}i:2;a:5:{i:0;i:4;i:PLANET;i:1;i:2;d:50;i:3;d:T+120;i:4;s:5:"build";}}',
        'research first: serialized' => 'a:1:{i:0;a:6:{i:0;i:106;i:1;i:1;i:2;d:617;i:3;d:T+617;i:4;i:PLANET;i:5;a:3:{i:901;d:200;i:902;d:1000;i:903;d:200;}}}',
        'research first: user fields' => [106, true, 617.0],
        'research first: resources in database' => [4999800.0, 4999000.0, 4999800.0],
        'research several: serialized' => 'a:2:{i:0;a:6:{i:0;i:106;i:1;i:3;i:2;d:2468;i:3;d:T+2468;i:4;i:PLANET;i:5;a:3:{i:901;d:800;i:902;d:4000;i:903;d:800;}}i:1;a:5:{i:0;i:106;i:1;i:4;i:PLANET;d:4937;i:3;d:T+7405;i:4;i:PLANET;}}',
        'research four: serialized' => 'a:4:{i:0;a:6:{i:0;i:106;i:1;i:1;i:2;d:617;i:3;d:T+617;i:4;i:PLANET;i:5;a:3:{i:901;d:200;i:902;d:1000;i:903;d:200;}}i:1;a:5:{i:0;i:108;i:1;i:1;i:2;d:205;i:3;d:T+822;i:4;i:PLANET;}i:2;a:5:{i:0;i:113;i:1;i:1;i:2;d:411;i:3;d:T+1233;i:4;i:PLANET;}i:3;a:5:{i:0;i:106;i:1;i:2;i:2;d:1234;i:3;d:T+2467;i:4;i:PLANET;}}',
        'research remove #3: serialized' => 'a:3:{i:0;a:6:{i:0;i:106;i:1;i:1;i:2;d:617;i:3;d:T+617;i:4;i:PLANET;i:5;a:3:{i:901;d:200;i:902;d:1000;i:903;d:200;}}i:1;a:5:{i:0;i:108;i:1;i:1;i:2;d:205;i:3;d:T+822;i:4;i:PLANET;}i:2;a:5:{i:0;i:106;i:1;i:2;i:2;d:1234;i:3;d:T+2056;i:4;i:PLANET;}}',
        'research cancel: refund in database' => [200.0, 600.0, -400.0],
        'research cancel: serialized' => 'a:1:{i:0;a:6:{i:0;i:108;i:1;i:1;i:2;d:205;i:3;d:T+205;i:4;i:PLANET;i:5;a:2:{i:902;d:400;i:903;d:600;}}}',
        'elapsed build: queue' => 'a:2:{i:0;a:6:{i:0;i:2;i:1;i:1;i:2;d:103;i:3;d:T+203;i:4;s:5:"build";i:5;a:2:{i:901;d:48;i:902;d:24;}}i:1;a:5:{i:0;i:3;i:1;i:1;i:2;d:432;i:3;d:T+635;i:4;s:5:"build";}}',
        'elapsed build: levels and fields' => [4, 0, 0, 1, 203.0],
        'elapsed build: resources' => [99952.0, 99976.0, 100000.0],
        'elapsed research: queue' => 'a:1:{i:0;a:6:{i:0;i:108;i:1;i:1;i:2;d:205;i:3;d:T+305;i:4;i:PLANET;i:5;a:2:{i:902;d:400;i:903;d:600;}}}',
        'elapsed research: user' => [2, 0, 108, 305.0, true],
        'elapsed research: planet resources' => [100000.0, 99600.0, 99400.0],
        'elapsed shipyard: queue' => ['a:2:{i:0;a:2:{i:0;i:202;i:1;d:8;}i:1;a:2:{i:0;i:204;i:1;i:5;}}', 696.0],
        'elapsed shipyard: ships' => [7.0, 0],
    ];
    // GOLDEN-END

    private const RICH = ['metal' => 5.0e6, 'crystal' => 5.0e6, 'deuterium' => 5.0e6, 'laboratory' => 6, 'robot_factory' => 2];

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    private function controller(string $class, array $user, array $planet): AbstractGameController
    {
        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        // the research page sets the research network levels on the planet before queue actions (ResearchController::show)
        $planet['laboratory_inter'] = $eco->getNetworkLevel($user, $planet);
        $this->state->USER = $user;
        $this->state->PLANET = $planet;
        $controller = (new $class())->withGameServices($this->db, $this->configs, $this->state);
        (new \ReflectionProperty(AbstractGameController::class, 'ecoObj'))->setValue($controller, $eco);

        return $controller;
    }

    private function call(object $controller, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }

    /**
     * The stored queue (JSON) as the serialize() string the goldens were captured
     * from: decoding the storage and serializing the structure again proves it is identical to what the former
     * serialize()-based storage held. The clock and the planet id are made relative: "d:T+57;" instead of
     * "d:1794777123;" ($base defaults to the request timestamp), "i:4;i:PLANET;" instead of the planet id of a research entry.
     */
    private function shape(string $stored, ?int $base = null): string
    {
        $base ??= $this->state->runtime->timestamp;
        $serialized = serialize(StoredData::decode($stored));
        $serialized = (string) preg_replace('/i:4;i:\d+;/', 'i:4;i:PLANET;', $serialized);

        return (string) preg_replace_callback('/(d|i):(\d{10,});/', static fn(array $m): string => $m[1] . ':T+' . ((int) $m[2] - $base) . ';', $serialized);
    }

    /** @return array<string, mixed> */
    private function planetNow(): array
    {
        return $this->state->PLANET;
    }

    // --- building queue -------------------------------------------------------------------------------------------

    public function testBuildingQueueFirstEntryIsPaidAndStoredWithItsCost(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 1000, 'crystal' => 500, 'deuterium' => 0, 'robot_factory' => 1]);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);

        $this->call($buildings, 'addBuildingToQueue', 1, true);

        $now = $this->planetNow();
        $this->assertTrue(json_validate($now['b_building_id']), 'the queue is stored as JSON');
        $this->goldenAssert('building first: serialized', $this->shape($now['b_building_id']));
        $this->goldenAssert('building first: b_building relative', $now['b_building'] - $this->state->runtime->timestamp);
        $this->goldenAssert('building first: resources in memory', [$now['metal'], $now['crystal'], $now['deuterium']]);
        $stored = $this->loadPlanet((int) $planet['id']);
        $this->assertEquals([$now['metal'], $now['crystal'], $now['deuterium']], [$stored['metal'], $stored['crystal'], $stored['deuterium']], 'resources are deducted in the database too');
    }

    public function testBuildingQueueCannotStartWithoutResources(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 10, 'crystal' => 10]);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);

        $this->call($buildings, 'addBuildingToQueue', 1, true);

        $this->assertEmpty($this->planetNow()['b_building_id']);
        $this->assertEquals(10, $this->loadPlanet((int) $planet['id'])['metal']);
    }

    public function testBuildingQueueWithSeveralEntries(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH + ['metal_mine' => 4, 'solar_plant' => 6]);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);

        $this->call($buildings, 'addBuildingToQueue', 1, true);   // metal mine 5 (paid)
        $this->call($buildings, 'addBuildingToQueue', 1, true);   // metal mine 6 (queued, not paid)
        $this->call($buildings, 'addBuildingToQueue', 4, false);  // solar plant 6 -> destroy
        $this->call($buildings, 'addBuildingToQueue', 2, true);   // crystal mine 1

        $this->goldenAssert('building several: serialized', $this->shape($this->planetNow()['b_building_id']));
        $this->goldenAssert('building several: count', count(StoredData::decode($this->planetNow()['b_building_id'])));
    }

    public function testBuildingQueueLimit(): void
    {
        $this->setUniverseConfig(['max_elements_build' => 2]);
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);

        foreach ([1, 2, 3, 4] as $element) {
            $this->call($buildings, 'addBuildingToQueue', $element, true);
        }

        $this->assertCount(2, StoredData::decode($this->planetNow()['b_building_id']));
    }

    public function testBuildingQueueRejectsLevelsAboveTheMaximum(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH + ['small_ship_cargo' => 0, 'terraformer' => 0, 'mondbasis' => 0]);
        $planet['metal_mine'] = 255;
        $buildings = $this->controller(BuildingsController::class, $user, $planet);

        $this->call($buildings, 'addBuildingToQueue', 1, true);

        $this->assertEmpty($this->planetNow()['b_building_id']);
    }

    public function testCancelRunningBuildingRefundsAndRebuildsTheRestOfTheQueue(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH + ['metal_mine' => 4]);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);
        $this->call($buildings, 'addBuildingToQueue', 1, true);
        $this->call($buildings, 'addBuildingToQueue', 2, true);
        $this->call($buildings, 'addBuildingToQueue', 3, true);
        $afterAdd = $this->planetNow();

        $this->assertTrue($this->call($buildings, 'cancelBuildingFromQueue'));

        $now = $this->planetNow();
        $this->goldenAssert('cancel building: serialized', $this->shape($now['b_building_id']));
        $this->goldenAssert('cancel building: refunded resources', [$now['metal'] - $afterAdd['metal'], $now['crystal'] - $afterAdd['crystal'], $now['deuterium'] - $afterAdd['deuterium']]);
    }

    public function testCancelLastBuildingEmptiesTheQueue(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);
        $this->call($buildings, 'addBuildingToQueue', 1, true);

        $this->assertTrue($this->call($buildings, 'cancelBuildingFromQueue'));
        $this->assertSame('', $this->planetNow()['b_building_id']);
        $this->assertSame(0, (int) $this->planetNow()['b_building']);
        $this->assertFalse($this->call($buildings, 'cancelBuildingFromQueue'), 'an empty queue cannot be cancelled');
    }

    public function testRemoveQueuedBuilding(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);
        foreach ([1, 2, 3, 4] as $element) {
            $this->call($buildings, 'addBuildingToQueue', $element, true);
        }

        $this->assertFalse($this->call($buildings, 'removeBuildingFromQueue', 1), 'the running entry cannot be removed');
        $this->assertTrue($this->call($buildings, 'removeBuildingFromQueue', 3));

        $this->goldenAssert('remove building #3: serialized', $this->shape($this->planetNow()['b_building_id']));
    }

    public function testBuildingQueueIsPersistedByResourceUpdate(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $buildings = $this->controller(BuildingsController::class, $user, $planet);
        $this->call($buildings, 'addBuildingToQueue', 1, true);
        $this->call($buildings, 'addBuildingToQueue', 2, true);

        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        $eco->saveBuilingQueue($this->planetNow());

        $stored = $this->loadPlanet((int) $planet['id']);
        $this->assertSame($this->planetNow()['b_building_id'], $stored['b_building_id']);
        $this->assertSame([[1, 1, 1], [2, 1, 1]], array_map(static fn(array $entry): array => [$entry[0], $entry[1], 1], StoredData::decode($stored['b_building_id'])));
    }

    // --- research queue -------------------------------------------------------------------------------------------

    public function testResearchQueueFirstEntryIsPaidAndStoredOnTheUser(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $research = $this->controller(ResearchController::class, $user, $planet);

        $this->assertTrue($this->call($research, 'addResearchToQueue', 106, true));

        $now = $this->state->USER;
        $this->goldenAssert('research first: serialized', $this->shape($now['b_tech_queue']));
        $this->goldenAssert('research first: user fields', [$now['b_tech_id'], $now['b_tech_planet'] === $planet['id'], $now['b_tech'] - $this->state->runtime->timestamp]);
        $stored = $this->loadPlanet((int) $planet['id']);
        $this->goldenAssert('research first: resources in database', [$stored['metal'], $stored['crystal'], $stored['deuterium']]);
    }

    public function testResearchQueueWithSeveralEntries(): void
    {
        [$user, $planet] = $this->createPlayer(['spy_tech' => 2], self::RICH);
        $research = $this->controller(ResearchController::class, $user, $planet);

        $this->assertTrue($this->call($research, 'addResearchToQueue', 106, true));  // espionage 3
        $this->assertTrue($this->call($research, 'addResearchToQueue', 106, true));  // espionage 4 (queued)
        $this->assertFalse($this->call($research, 'addResearchToQueue', 108, true), 'the queue holds two entries (max_elements_tech)');

        $this->goldenAssert('research several: serialized', $this->shape($this->state->USER['b_tech_queue']));
    }

    public function testCancelAndRemoveResearch(): void
    {
        $this->setUniverseConfig(['max_elements_tech' => 4]);
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $research = $this->controller(ResearchController::class, $user, $planet);
        foreach ([106, 108, 113, 106] as $element) {
            $this->call($research, 'addResearchToQueue', $element, true);
        }
        $this->goldenAssert('research four: serialized', $this->shape($this->state->USER['b_tech_queue']));

        $this->assertTrue($this->call($research, 'removeResearchFromQueue', 3));
        $this->goldenAssert('research remove #3: serialized', $this->shape($this->state->USER['b_tech_queue']));

        $before = $this->loadPlanet((int) $planet['id']);
        $this->assertTrue($this->call($research, 'cancelResearchFromQueue'));
        $after = $this->loadPlanet((int) $planet['id']);
        $this->goldenAssert('research cancel: refund in database', [$after['metal'] - $before['metal'], $after['crystal'] - $before['crystal'], $after['deuterium'] - $before['deuterium']]);
        $this->goldenAssert('research cancel: serialized', $this->shape($this->state->USER['b_tech_queue']));
    }

    public function testResearchQueueIsPersistedByResourceUpdate(): void
    {
        [$user, $planet] = $this->createPlayer([], self::RICH);
        $research = $this->controller(ResearchController::class, $user, $planet);
        $this->call($research, 'addResearchToQueue', 106, true);

        (new ResourceUpdate($this->db, $this->configs, $this->state))->saveTechQueue($this->state->USER);

        $stored = $this->loadUser((int) $user['id']);
        $this->assertSame($this->state->USER['b_tech_queue'], $stored['b_tech_queue']);
        $this->assertSame(106, (int) $stored['b_tech_id']);
    }

    // --- queues advanced by time (ResourceUpdate) -----------------------------------------------------------------

    public function testElapsedBuildQueueFinishesEntriesAndStartsTheNextOne(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000, 'metal_mine' => 3, 'solar_plant' => 5]);
        $start = $planet['last_update'];
        $planet['b_building'] = $start + 100;
        $planet['b_building_id'] = StoredData::encode([
            [1, 4, 100.0, (float) ($start + 100), 'build', [901 => 100.0, 902 => 25.0]],
            [2, 1, 60.0, (float) ($start + 160), 'build'],
            [3, 1, 80.0, (float) ($start + 240), 'build'],
        ]);

        $eco = new ResourceUpdate($this->db, $this->configs, $this->state);
        [, $after] = [null, $eco->CalcResource($user, $planet, false, $start + 170)[1]];

        $this->goldenAssert('elapsed build: queue', $this->shape($after['b_building_id'], $start));
        $this->goldenAssert('elapsed build: levels and fields', [$after['metal_mine'], $after['crystal_mine'], $after['deuterium_sintetizer'], $after['field_current'] - $planet['field_current'], $after['b_building'] - $start]);
        $this->goldenAssert('elapsed build: resources', [$after['metal'], $after['crystal'], $after['deuterium']]);
    }

    public function testElapsedBuildQueueFinishesCompletely(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000, 'metal_mine' => 3]);
        $start = $planet['last_update'];
        $planet['b_building'] = $start + 100;
        $planet['b_building_id'] = StoredData::encode([[1, 4, 100.0, (float) ($start + 100), 'build', [901 => 100.0, 902 => 25.0]]]);

        [, $after] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, false, $start + 500) + [1 => null];

        $this->assertSame(4, (int) $after['metal_mine']);
        $this->assertSame('', $after['b_building_id']);
        $this->assertSame(0, (int) $after['b_building']);
    }

    public function testElapsedResearchQueueFinishesAndStartsTheNext(): void
    {
        [$user, $planet] = $this->createPlayer(['spy_tech' => 1], ['metal' => 100000, 'crystal' => 100000, 'deuterium' => 100000, 'laboratory' => 6]);
        $start = $planet['last_update'];
        $user['b_tech'] = $start + 100;
        $user['b_tech_id'] = 106;
        $user['b_tech_planet'] = $planet['id'];
        $user['b_tech_queue'] = StoredData::encode([
            [106, 2, 100.0, (float) ($start + 100), (int) $planet['id'], [901 => 200.0, 902 => 1000.0, 903 => 200.0]],
            [108, 1, 150.0, (float) ($start + 250), (int) $planet['id']],
        ]);

        [$afterUser, $afterPlanet] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, false, $start + 120);

        $this->goldenAssert('elapsed research: queue', $this->shape($afterUser['b_tech_queue'], $start));
        $this->goldenAssert('elapsed research: user', [$afterUser['spy_tech'], $afterUser['computer_tech'], $afterUser['b_tech_id'], $afterUser['b_tech'] - $start, $afterUser['b_tech_planet'] === $planet['id']]);
        $this->goldenAssert('elapsed research: planet resources', [$afterPlanet['metal'], $afterPlanet['crystal'], $afterPlanet['deuterium']]);
    }

    public function testElapsedShipyardQueueBuildsWholeShipsAndKeepsTheRest(): void
    {
        [$user, $planet] = $this->createPlayer([], ['metal' => 1000, 'crystal' => 1000, 'hangar' => 4, 'small_ship_cargo' => 5]);
        $start = $planet['last_update'];
        $planet['b_hangar'] = 0;
        $planet['b_hangar_id'] = StoredData::encode([[202, 10], [204, 5]]);

        [, $after] = (new ResourceUpdate($this->db, $this->configs, $this->state))->CalcResource($user, $planet, false, $start + 3000) + [1 => null];

        $this->goldenAssert('elapsed shipyard: queue', [serialize(StoredData::decode($after['b_hangar_id'])), $after['b_hangar']]);
        $this->goldenAssert('elapsed shipyard: ships', [$after['small_ship_cargo'], $after['light_hunter']]);
    }
}
