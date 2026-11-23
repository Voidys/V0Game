<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FleetFunctions;
use App\Game\Missions\MissionCaseSpy;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;
use App\Game\Enum\MessageType;

/**
 * Runs the espionage mission end to end and pins what it writes: the report message (JSON; the volatile roll and times
 * left out), the foreign-fleet notice, the probes' fate (return, or shot down with debris when the detection chance is
 * 100 percent). Golden values come from the code before the long TargetEvent() was split into steps.
 */
final class SpyMissionTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'big tech report' => ['stbEnabled' => 0, 'danger' => 15, 'dangerClass' => 'danger', 'smallCargoNeeded' => 2, 'largeCargoNeeded' => 1, 'energy' => 0, 'energyClass' => '', 'spyData' => [900 => [901 => 5000, 902 => 3000, 903 => 1000], 200 => [202 => 3], 400 => [], 0 => [1 => 4], 100 => [106 => 1]], 'repair_order' => [], 'targetChance' => 0, 'totalShipDefCount' => 3, 'isBattleSim' => true, 'targetInactive' => false],
        'no tech report' => ['stbEnabled' => 0, 'danger' => 15, 'dangerClass' => 'danger', 'smallCargoNeeded' => 2, 'largeCargoNeeded' => 1, 'energy' => 0, 'energyClass' => '', 'spyData' => [900 => [901 => 5000, 902 => 3000, 903 => 1000], 200 => [202 => 3], 400 => []], 'repair_order' => [], 'targetChance' => 0, 'totalShipDefCount' => 3, 'isBattleSim' => true, 'targetInactive' => false],
        'debris of shot down probes' => [0.0, 30000.0],
    ];
    // GOLDEN-END

    /**
     * @param array<mixed> $targetPlanetColumns
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} sender, target, target planet; the fleet is stored in $this->fleet
     */
    private function spy(int $probes, int $senderSpyTech, array $targetPlanetColumns): array
    {
        $resource = $this->elements()->columnMap();
        [$sender, $senderPlanet] = $this->createPlayer(['spy_tech' => $senderSpyTech], [$resource[210] => $probes, 'deuterium' => 1e6]);
        [$target, $targetPlanet] = $this->createPlayer(['spy_tech' => 1], $targetPlanetColumns + ['metal' => 5000, 'crystal' => 3000, 'deuterium' => 1000, $resource[1] => 4]);
        $now = time();
        $this->state->USER = $sender;
        $this->state->PLANET = $senderPlanet;
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [210 => $probes],
            Mission::Spy->value,
            (int) $sender['id'],
            (int) $senderPlanet['id'],
            (int) $senderPlanet['galaxy'],
            (int) $senderPlanet['system'],
            (int) $senderPlanet['planet'],
            1,
            (int) $target['id'],
            (int) $targetPlanet['id'],
            (int) $targetPlanet['galaxy'],
            (int) $targetPlanet['system'],
            (int) $targetPlanet['planet'],
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now + 5000,
        );
        $this->fleetId = (int) $id;
        $fleet = $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
        (new MissionCaseSpy($fleet, $this->db, $this->configs, $this->state))->TargetEvent();

        return [$sender, $target, $targetPlanet];
    }

    private int $fleetId = 0;

    /**
     * @param array<mixed> $sender
     * @return array<string, mixed> the report of the sender without the volatile parts (roll, times, names)
     */
    private function report(array $sender): array
    {
        $text = (string) $this->db->selectSingle('SELECT m.message_text FROM %%MESSAGES%% m WHERE m.message_owner = :id AND m.message_type = :type ORDER BY m.message_id DESC LIMIT 1;', [':id' => $sender['id'], ':type' => MessageType::SpyReport->value], 'message_text');
        $data = json_decode($text, true);
        unset($data['spyChance'], $data['time'], $data['targetUserName'], $data['targetPlanet']);

        return $data;
    }

    public function testProbesWithTheBetterTechSeeEverythingAndReturn(): void
    {
        [$sender, $target] = $this->spy(20, 10, [$this->elements()->column(202) => 3]);

        $this->goldenAssert('big tech report', $this->report($sender));
        $fleet = $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $this->fleetId]);
        $this->assertEquals(FLEET_RETURN, $fleet['status']);
        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%MESSAGES%% WHERE message_owner = :id AND message_type = :t;', [':id' => $target['id'], ':t' => MessageType::SpyDefence->value], 'c'), 'the target owner is told about the foreign fleet');
    }

    public function testProbesWithoutTechOnlySeeTheResources(): void
    {
        [$sender] = $this->spy(1, 1, [$this->elements()->column(202) => 3]);

        $this->goldenAssert('no tech report', $this->report($sender));
    }

    public function testCertainDetectionShootsTheProbesDownAndLeavesDebris(): void
    {
        $resource = $this->elements()->columnMap();
        [$sender, , $targetPlanet] = $this->spy(100, 1, [$resource[202] => 1000]);

        $this->assertFalse($this->db->selectSingle('SELECT id FROM %%FLEETS%% WHERE id = :id;', [':id' => $this->fleetId]), 'the probes are gone');
        $planet = $this->loadPlanet((int) $targetPlanet['id']);
        $this->assertGreaterThan(0, (float) $planet['der_crystal']);
        $this->assertEquals(1, $planet['tf_active']);
        $this->goldenAssert('debris of shot down probes', [(float) $planet['der_metal'], (float) $planet['der_crystal']]);
        $this->assertNotEmpty($this->report($sender), 'the report is sent anyway');
    }
}
