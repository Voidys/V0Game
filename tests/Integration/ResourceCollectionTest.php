<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Language;
use App\Game\Core\ResourceCollector;
use App\Game\Fleet\ResourceCollectionDispatcher;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Collecting resources from two planets through the regular fleet dispatch: ships, cargo and fuel are taken, one flight per source. */
final class ResourceCollectionTest extends GameTestCase
{
    /** @var list<int> */
    private array $fleetIds = [];

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

    protected function tearDown(): void
    {
        foreach ($this->fleetIds as $id) {
            foreach (['FLEETS_EVENT' => 'fleetID', 'LOG_FLEETS' => 'id', 'FLEETS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $id]);
            }
        }
        parent::tearDown();
    }

    /** Adds a second planet of the player at another position of the same system. */
    private function secondPlanet(array $user, array $first, array $values): array
    {
        $this->db->insert(
            'INSERT INTO %%PLANETS%% SET `name` = :n, `id_owner` = :o, `universe` = 1, `galaxy` = :g, `system` = :s, `planet` = :p, `planet_type` = 1, `last_update` = :t, `field_max` = 200;',
            [':n' => 'Colony', ':o' => $user['id'], ':g' => $first['galaxy'], ':s' => $first['system'], ':p' => $first['planet'] % 15 + 1, ':t' => time()],
        );
        $id = (int) $this->db->lastInsertId();
        foreach ($values as $column => $value) {
            $this->db->update("UPDATE %%PLANETS%% SET `$column` = :v WHERE id = :id;", [':v' => $value, ':id' => $id]);
        }

        return $this->loadPlanet($id);
    }

    public function testTwoSourcesEachSendOneTransportToTheTarget(): void
    {
        [$user, $target] = $this->createPlayer(['computer_tech' => 5], ['metal' => 0]);
        $a = $this->secondPlanet($user, $target, ['big_ship_cargo' => 4, 'small_ship_cargo' => 5, 'metal' => 60000, 'crystal' => 20000, 'deuterium' => 5000]);
        // a second colony at another position
        $this->db->insert(
            'INSERT INTO %%PLANETS%% SET `name` = :n, `id_owner` = :o, `universe` = 1, `galaxy` = :g, `system` = :s, `planet` = :p, `planet_type` = 1, `last_update` = :t, `field_max` = 200, `small_ship_cargo` = 10, `metal` = 30000, `deuterium` = 3000;',
            [':n' => 'Colony2', ':o' => $user['id'], ':g' => $target['galaxy'], ':s' => $target['system'], ':p' => ($target['planet'] + 1) % 15 + 1, ':t' => time()],
        );
        $b = $this->loadPlanet((int) $this->db->lastInsertId());
        $this->state->USER = $user;

        $dispatcher = new ResourceCollectionDispatcher($this->db, $this->configs, $this->state);
        $e = $this->elements();
        $capacity = [203 => $e->capacity(203), 202 => $e->capacity(202)];
        $userModel = (new \App\Game\Model\ModelFactory($this->state))->user($user);
        $fleet = new \App\Game\Core\FleetFunctions($this->db, $this->configs, $this->state);

        foreach ([$a, $b] as $source) {
            $distance = $fleet->getTargetDistance([$source['galaxy'], $source['system'], $source['planet']], [$target['galaxy'], $target['system'], $target['planet']]);
            $fuelFor = fn(array $ships): float => $fleet->fleetConsumptionFor($ships, $fleet->missionDurationFor(100, $fleet->fleetMaxSpeedFor($ships, $userModel), $distance, $fleet->getGameSpeedFactor(), $userModel), $distance, $userModel, $fleet->getGameSpeedFactor());
            $plan = ResourceCollector::plan(
                [901 => $source['metal'], 902 => $source['crystal'], 903 => $source['deuterium']],
                [203 => $source['big_ship_cargo'], 202 => $source['small_ship_cargo']],
                $capacity,
                [901 => true, 902 => true, 903 => true],
                500,
                $fuelFor,
            );
            $this->assertNotNull($plan);
            $outcome = $dispatcher->send($source, $target, $plan);
            $this->assertTrue($outcome['ok'], $outcome['message']);
            $this->assertNotSame('', $outcome['arrival']);

            $after = $this->loadPlanet((int) $source['id']);
            $this->assertSame((int) $source['metal'] - $plan['cargo'][901], (int) $after['metal']);
            $this->assertGreaterThanOrEqual(500, (int) floor($after['deuterium']));
        }

        $flights = $this->db->select('SELECT id, start_id, end_id, mission, resource_metal, owner FROM %%FLEETS%% WHERE owner = :o;', [':o' => $user['id']]);
        foreach ($flights as $flight) {
            $this->fleetIds[] = (int) $flight['id'];
            $this->assertSame((int) $target['id'], (int) $flight['end_id']);
            $this->assertSame(3, (int) $flight['mission']);
        }
        $this->assertCount(2, $flights);
        $this->assertEqualsCanonicalizing([(int) $a['id'], (int) $b['id']], array_map(static fn(array $f): int => (int) $f['start_id'], $flights));
    }
}
