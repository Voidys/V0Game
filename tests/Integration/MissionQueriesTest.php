<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FleetFunctions;
use App\Game\Core\FlyingFleetsTable;
use App\Game\Missions\MissionCaseMIP;
use App\Game\Missions\MissionCaseRecycling;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;
use App\Game\Enum\PlanetType;

/**
 * Pins the SQL that the missions and the fleet table assemble at runtime (debris collection, missile damage, the fleet
 * list with its filters, the generic fleet update) after it moved to the QueryBuilder / Database::updateRows(): the
 * rows must be what the plain SQL used to produce (the fleet list is compared with the old statement text as oracle).
 */
final class MissionQueriesTest extends GameTestCase
{
    /**
     * Sends a fleet from $from to $target with the given ships (arrived in the past) and returns its row.
     *
     * @param array<mixed> $from
     * @param array<mixed> $fromPlanet
     * @param array<mixed> $ships
     * @param array<mixed> $to
     * @param array<mixed> $toPlanet
     * @return array<mixed>
     */
    private function fleet(array $from, array $fromPlanet, array $to, array $toPlanet, int $mission, array $ships, int $missileTarget = 0): array
    {
        $now = time();
        $this->state->USER = $from;
        $this->state->PLANET = $fromPlanet;
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            $ships,
            $mission,
            (int) $from['id'],
            (int) $fromPlanet['id'],
            (int) $fromPlanet['galaxy'],
            (int) $fromPlanet['system'],
            (int) $fromPlanet['planet'],
            1,
            (int) $to['id'],
            (int) $toPlanet['id'],
            (int) $toPlanet['galaxy'],
            (int) $toPlanet['system'],
            (int) $toPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now + 5000,
            0,
            $missileTarget,
        );

        return $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
    }

    public function testRecyclersTakeTheWholeDebrisFieldWhenTheyHaveRoom(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer([], [$resource[209] => 5, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], ['der_metal' => 1000, 'der_crystal' => 500]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Recycling->value, [209 => 1]);

        (new MissionCaseRecycling($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        $planet = $this->loadPlanet((int) $ownerPlanet['id']);
        $this->assertEquals(0, $planet['der_metal']);
        $this->assertEquals(0, $planet['der_crystal']);
        $row = $this->db->selectSingle('SELECT resource_metal, resource_crystal, status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]);
        $this->assertEquals(1000, $row['resource_metal']);
        $this->assertEquals(500, $row['resource_crystal']);
        $this->assertEquals(FLEET_RETURN, $row['status']);
        $log = $this->db->selectSingle('SELECT gained_metal, gained_crystal FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]);
        $this->assertEquals(1000, $log['gained_metal']);
        $this->assertEquals(500, $log['gained_crystal']);
    }

    public function testRecyclersTakeAShareOfABigDebrisFieldAndLeaveTheRest(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer([], [$resource[209] => 5, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], ['der_metal' => 30000, 'der_crystal' => 10000]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Recycling->value, [209 => 1]);
        $capacity = $this->elements()->capacity(209);

        (new MissionCaseRecycling($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        $factor = min(1, $capacity / 40000);
        $takenMetal = ceil(30000 * $factor);
        $takenCrystal = ceil(10000 * $factor);
        $planet = $this->loadPlanet((int) $ownerPlanet['id']);
        $this->assertEquals(30000 - $takenMetal, $planet['der_metal']);
        $this->assertEquals(10000 - $takenCrystal, $planet['der_crystal']);
        $row = $this->db->selectSingle('SELECT resource_metal, resource_crystal FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]);
        $this->assertEquals($takenMetal, $row['resource_metal']);
        $this->assertEquals($takenCrystal, $row['resource_crystal']);
        // the report names the total the field held before the collection (it used to print 0: a backticked column key)
        $text = (string) $this->db->selectSingle('SELECT m.message_text FROM %%MESSAGES%% m WHERE m.message_owner = :id ORDER BY m.message_id DESC LIMIT 1;', [':id' => $sender['id']], 'message_text');
        $this->assertMatchesRegularExpression('/total of 30[.,]000 .* and 10[.,]000 /', $text);
    }

    public function testRecyclingAVanishedPlanetSendsTheFleetHome(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer([], [$resource[209] => 5, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], ['der_metal' => 1000]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Recycling->value, [209 => 1]);
        $this->db->delete('DELETE FROM %%PLANETS%% WHERE id = :id;', [':id' => $ownerPlanet['id']]);

        (new MissionCaseRecycling($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        $this->assertEquals(FLEET_RETURN, $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']], 'status'));
    }

    public function testMissilesAreInterceptedBeforeTheyHitTheDefense(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer(['impulse_motor_tech' => 5], [$resource[503] => 10, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], [$resource[502] => 20, $resource[401] => 50]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Missile->value, [503 => 10], 401);

        (new MissionCaseMIP($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        $planet = $this->loadPlanet((int) $ownerPlanet['id']);
        $this->assertEquals(10, $planet[$resource[502]], 'every missile was intercepted: 20 - 10 interceptors left');
        $this->assertEquals(50, $planet[$resource[401]], 'the defense is untouched');
        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]), 'missiles are used up');
    }

    public function testMissilesAtAVacationTargetAreSpentWithoutLeavingAZombieEvent(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer(['impulse_motor_tech' => 5], [$resource[503] => 10, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer(['urlaubs_modus' => 1], [$resource[401] => 50]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Missile->value, [503 => 10], 401);

        (new MissionCaseMIP($fleet, $this->db, $this->configs, $this->state))->TargetEvent();
        $turned = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]);
        $this->assertSame(FLEET_RETURN, (int) $turned['status'], 'turned around by the vacation rule');
        (new MissionCaseMIP($turned, $this->db, $this->configs, $this->state))->ReturnEvent();

        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]), 'the fleet is deleted');
        $this->assertFalse($this->db->selectSingle('SELECT fleetID FROM %%FLEETS_EVENT%% WHERE fleetID = :id;', [':id' => $fleet['id']]), 'and its event with it');
        $this->assertEquals(50, $this->loadPlanet((int) $ownerPlanet['id'])[$resource[401]]);
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%MESSAGES%% WHERE message_owner = :id;', [':id' => $sender['id']], 'n'), 'the sender is told that the missiles were lost');
    }

    public function testMissilesDestroyDefenseWhenSomeGetThrough(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer(['impulse_motor_tech' => 5, 'military_tech' => 10], [$resource[503] => 10, 'deuterium' => 1e6]);
        [$owner, $ownerPlanet] = $this->createPlayer([], [$resource[502] => 3, $resource[401] => 50]);
        $fleet = $this->fleet($sender, $senderPlanet, $owner, $ownerPlanet, Mission::Missile->value, [503 => 10], 401);

        (new MissionCaseMIP($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        $planet = $this->loadPlanet((int) $ownerPlanet['id']);
        $this->assertEquals(0, $planet[$resource[502]], 'the interceptors are all used up');
        $this->assertLessThan(50, (int) $planet[$resource[401]], 'seven missiles got through and hit the primary target');
        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]));
    }

    /** @return list<array<string, mixed>> the fleet rows the table lists for the given filters (private getFleets()) */
    private function listed(FlyingFleetsTable $table, int $acsId = 0): array
    {
        $method = new \ReflectionMethod($table, 'getFleets');

        return $method->invoke($table, $acsId);
    }

    /**
     * the statements getFleets() ran before it used the QueryBuilder: the oracle
     *
     * @param array<mixed> $param
     * @return array<mixed>
     */
    private function oracle(string $where, array $param): array
    {
        return $this->db->select('SELECT DISTINCT fleet.*, ownuser.`username` as own_username, targetuser.`username` as target_username,
            ownplanet.`name` as own_planetname, targetplanet.`name` as target_planetname
            FROM %%FLEETS%% fleet
            LEFT JOIN %%USERS%% ownuser ON (ownuser.`id` = fleet.`owner`)
            LEFT JOIN %%USERS%% targetuser ON (targetuser.`id` = fleet.`target_owner`)
            LEFT JOIN %%PLANETS%% ownplanet ON (ownplanet.`id` = fleet.`start_id`)
            LEFT JOIN %%PLANETS%% targetplanet ON (targetplanet.`id` = fleet.`end_id`)
            WHERE ' . $where . ' ORDER BY fleet.`id`;', $param);
    }

    public function testFleetTableListsTheSameRowsAsThePlainSql(): void
    {
        $resource = $this->elements()->columnMap();
        [$a, $aPlanet] = $this->createPlayer([], [$resource[202] => 50, $resource[209] => 5, 'deuterium' => 1e6]);
        [$b, $bPlanet] = $this->createPlayer([], [$resource[202] => 50, 'deuterium' => 1e6]);
        $this->fleet($a, $aPlanet, $b, $bPlanet, Mission::Transport->value, [202 => 1]);
        $this->fleet($a, $aPlanet, $b, $bPlanet, Mission::Recycling->value, [209 => 1]);
        $this->fleet($b, $bPlanet, $a, $aPlanet, Mission::Attack->value, [202 => 1]);
        $this->fleet($b, $bPlanet, $b, $bPlanet, Mission::Station->value, [202 => 1]);
        $rec = [':rec' => Mission::Recycling->value, ':trade' => Mission::Trade->value];
        $ownOrTargeted = '(fleet.`owner` = :userId OR (fleet.`target_owner` = :userId AND fleet.`mission` != :rec AND fleet.`mission` != :trade))';

        $own = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $own->setUser((int) $a['id']);
        $this->assertSame($this->oracle($ownOrTargeted, [':userId' => $a['id']] + $rec), $this->listed($own));
        $this->assertCount(3, $this->listed($own), 'own flights plus the foreign attack, without the recycler of someone else');

        $filtered = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $filtered->setUser((int) $a['id']);
        $filtered->setMissions('1, 3,x');
        $this->assertSame(
            $this->oracle($ownOrTargeted . ' AND fleet.`mission` IN (1,3)', [':userId' => $a['id']] + $rec),
            $this->listed($filtered),
        );
        $this->assertCount(2, $this->listed($filtered));

        $phalanx = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $phalanx->setPhalanxMode();
        $phalanx->setUser((int) $b['id']);
        $phalanx->setPlanet((int) $bPlanet['id']);
        $phalanxWhere = '(fleet.`start_id` = :planetId AND fleet.`start_type` = :type AND fleet.`mission` != :stay) OR'
            . ' (fleet.`end_id` = :planetId AND fleet.`end_type` = :type AND fleet.`mission` != :rec AND fleet.`mission` != :trade AND fleet.`status` IN (:outward, :return))';
        $this->assertSame($this->oracle($phalanxWhere, [
            ':planetId' => $bPlanet['id'], ':type' => PlanetType::Planet->value, ':stay' => Mission::Station->value,
            ':rec' => Mission::Recycling->value, ':trade' => Mission::Trade->value, ':outward' => FLEET_OUTWARD, ':return' => FLEET_RETURN,
        ]), $this->listed($phalanx));
        $this->assertNotEmpty($this->listed($phalanx));

        $group = $this->listed($own, 999);
        $this->assertSame([], $group, 'no fleet belongs to that ACS group');
    }

    public function testFleetUpdateWritesOnlyTheChangedColumns(): void
    {
        $resource = $this->elements()->columnMap();
        [$a, $aPlanet] = $this->createPlayer([], [$resource[202] => 50, 'deuterium' => 1e6]);
        [$b, $bPlanet] = $this->createPlayer();
        $fleet = $this->fleet($a, $aPlanet, $b, $bPlanet, Mission::Transport->value, [202 => 1]);
        $mission = new class ($fleet, $this->db, $this->configs, $this->state) extends \App\Game\Missions\MissionCaseTransport {
            public function change(): void
            {
                $this->UpdateFleet('resource_metal', 77);
                $this->UpdateFleetLog('gained_metal', 66);
                $this->SaveFleet();
            }
        };

        $mission->change();

        $row = $this->db->selectSingle('SELECT resource_metal, ship_amount FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleet['id']]);
        $this->assertEquals(77, $row['resource_metal']);
        $this->assertEquals($fleet['ship_amount'], $row['ship_amount']);
        $this->assertEquals(66, $this->db->selectSingle('SELECT gained_metal FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleet['id']], 'gained_metal'));
    }

    public function testPhalanxListsAnAcsGroupWithoutFailing(): void
    {
        $this->assertPhalanxListsAnAcsGroup('');
    }

    public function testPhalanxPageListsAnAcsGroupWithoutFailing(): void
    {
        $this->assertPhalanxListsAnAcsGroup('/game/phalanx?galaxy=1');
    }

    /** the phalanx table with an incoming ACS group used to fail with a string conversion error of the game state */
    private function assertPhalanxListsAnAcsGroup(string $requestUri): void
    {
        $previousServer = $this->state->requestServer;
        $this->state->requestServer = ['REQUEST_URI' => $requestUri];
        $resource = $this->elements()->columnMap();
        [$a, $aPlanet] = $this->createPlayer([], [$resource[202] => 50, 'deuterium' => 1e6]);
        [$b, $bPlanet] = $this->createPlayer();
        $now = time();
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [202 => 1],
            Mission::Attack->value,
            (int) $a['id'],
            (int) $aPlanet['id'],
            (int) $aPlanet['galaxy'],
            (int) $aPlanet['system'],
            (int) $aPlanet['planet'],
            1,
            (int) $b['id'],
            (int) $bPlanet['id'],
            (int) $bPlanet['galaxy'],
            (int) $bPlanet['system'],
            (int) $bPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now + 1000,
            $now + 1000,
            $now + 2000,
            55,
        );
        $this->state->USER = $b;
        $this->state->PLANET = $bPlanet;
        $table = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $table->setPhalanxMode();
        $table->setUser((int) $b['id']);
        $table->setPlanet((int) $bPlanet['id']);

        $events = $table->renderTable(true);

        $this->assertCount(1, $events, 'the incoming ACS attack is listed once');
        $this->state->requestServer = $previousServer;
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%PHALANX_FLEETS%% WHERE fleet_id = :id;', [':id' => $id], 'c'));
    }
}
