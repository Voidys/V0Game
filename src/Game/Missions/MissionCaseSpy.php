<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Missions;

use App\Game\Enum\PlanetType;
use App\Game\Enum\MessageType;
use App\Game\Enum\Mission;
use App\Game\Core\StoredData;
use App\Game\Core\ResourceUpdate;

/**
 * Mission "espionage" (6): probes spy on a planet or moon and deliver a spy report; the defender may shoot them down.
 */
class MissionCaseSpy extends MissionFunctions implements MissionInterface
{
    use SpyMissionSupport;

    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet   = $Fleet;
    }

    /**
     *
     * @param array<string, mixed> $targetPlanet
     * @return array[]
     *
     * call AFTER stayfleets calculation, or calculate those yourself
     *
     */
    private function getPlanetFleet($targetPlanet)
    {
        $e = $this->elements();
        $planetfleet = [];
        $resourceIDs = $e->ids('fleet');

        foreach ($resourceIDs as $shiptype) {
            $planetfleet[$shiptype] = $targetPlanet[$e->column($shiptype)];
        }

        return $planetfleet;
    }

    /**
     * Arrival of the probes. Turns around when the planet is gone or the owner is in vacation mode. Otherwise:
     * 1. Brings the target planet up to date (arrival time) and adds the ships of hold fleets stationed there.
     * 2. Information level: with spy tech levels s (sender) and t (target), both at least 1, the probes needed for
     *    fleets are n0 = +-((|s-t|*SPY_DIFFENCE_FACTOR)^2) (negative if the sender has the better tech); defense
     *    is visible with n0+1*SPY_VIEW_FACTOR probes, buildings n0+3*, research n0+5*. Resources are always shown.
     * 3. Counter-espionage chance (OGame rule): min(round(2^(t-s) * probes * ships_and_defenses_on_planet * 0.0025), 100)
     *    percent against a random roll 1..100; planets without ships or defense never shoot.
     * 4. Collects the report data (also ships in the repair dock queue, cargo needed to loot half of the planet,
     *    danger = summed attack, energy class) and sends it as JSON to the owner (spy report message); the
     *    target owner gets a "foreign fleet seen" notice every time.
     * 5. If the counter-espionage roll succeeded (chance >= roll) the probes are destroyed: their debris
     *    (fleet_debris_percentage of the probe cost) is added to the debris field (moon: parent planet) and the
     *    destroyed-ships stat is counted; otherwise the probes return home.
     */
    public function TargetEvent()
    {
        $e = $this->elements();
        $db = $this->db;

        $sql = 'SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;';
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted
        if ($targetPlanet == false) {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($this->returnIfTargetInVacation($targetPlanet['id_owner'])) {
            return;
        }

        [$senderUser, $targetUser, $senderPlanet] = $this->loadParticipants();

        $LNG = $this->getLanguage($senderUser['lang']);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);
        [$targetUser, $targetPlanet] = $planetUpdater->CalcResource(
            $targetUser,
            $targetPlanet,
            true,
            $this->_fleet['arrival_time'],
        );

        $targetPlanet = $this->addHoldFleets($targetPlanet);

        $fleetAmount = $this->_fleet['ship_amount'];

        $senderSpyTech = max($senderUser['spy_tech'], 1);
        $targetSpyTech = max($targetUser['spy_tech'], 1);

        [$classIDs, $SpyFleet] = $this->visibleClasses($fleetAmount, $senderSpyTech, $targetSpyTech);

        // what is seen by the 'attacker' is already calculated above.
        // if target planet does not have fleet or def, it is safe to set $targetChance to -1
        // so probes are no longer destroyed by thin air or something

        $totalShipDefCount = 0;
        foreach (array_values($this->getPlanetFleet($targetPlanet)) as $ships) {
            $totalShipDefCount += $ships;
        }
        foreach ($e->ids('defense') as $defenseId) {
            $totalShipDefCount += (int) ($targetPlanet[$e->column($defenseId)] ?? 0);
        }

        $targetChance = $this->getDetectionChance($senderSpyTech, $targetSpyTech, $fleetAmount, $totalShipDefCount);
        $spyChance = random_int(1, 100);

        $spyData = $this->collectSpyData($classIDs, $senderUser, $targetUser, $targetPlanet);
        $repair_order = $this->addRepairDockShips($spyData, $SpyFleet, $targetPlanet);

        $reportData = $this->buildReportData($senderUser, $targetUser, $targetPlanet, $spyData, $repair_order, $targetChance, $spyChance, $totalShipDefCount);

        $this->sendReport($reportData, $LNG);

        $this->notifyTargetOwner($senderUser, $targetUser, $senderPlanet, $targetPlanet);

        if ($totalShipDefCount > 0 && $targetChance >= $spyChance) {
            $this->destroyProbes($fleetAmount);
        } else {
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
        }
    }

    /**
     * The content of the spy report (stored as JSON in the message): what the probes saw, the danger value of the
     * visible ships and defense, the cargo ships needed to loot half of the planet, the planet's energy class (to
     * spot players building gravitons), and both counter-espionage numbers.
     *
     * @param array<string, mixed> $senderUser fleet owner (users + settings)
     * @param array<string, mixed> $targetUser target owner
     * @param array<string, mixed> $targetPlanet planet row (resources as of the arrival)
     * @param array<int, array<int, int|float|string>> $spyData see collectSpyData()
     * @param array<int|string, int|float|string> $repair_order ships in the repair dock
     * @return array<string, mixed>
     */
    private function buildReportData(array|false $senderUser, array|false $targetUser, array $targetPlanet, array $spyData, array $repair_order, int|float $targetChance, int $spyChance, int|float $totalShipDefCount): array
    {
        [$smallCargoNeeded, $largeCargoNeeded] = $this->cargoNeeded($targetPlanet);

        // Scavengers Tool Box

        $stbSettings = $this->playerLookup()->player_stb_settings($senderUser);

        // determine if the spy report detects ships or deff
        $danger = $this->getDangerValue($spyData);
        $dangerClass = $danger > 0 ? "danger" : "nonedanger";

        // get the energy to indicate if someone is pushing to gravi
        $energy = $targetPlanet["energy"];
        if ($energy > 250000) {
            $energyClass = "lowRess";
        } elseif ($energy > 100000) {
            $energyClass = "midRess";
        } else {
            $energyClass = "";
        }

        $reportData = [
            'targetUserName'    => $targetUser['username'],
            'time'              => $this->_fleet['stay_time'],
            'targetInactive'    => $targetUser['onlinetime'] < $this->_fleet['stay_time'] - INACTIVE, // for the farm list's "woke up" marker
            'stbEnabled'        => $stbSettings['stb_enabled'],
            'danger'            => $danger,
            'dangerClass'       => $dangerClass,
            'smallCargoNeeded'  => $smallCargoNeeded,
            'largeCargoNeeded'  => $largeCargoNeeded,
            'energy'            => $energy,
            'energyClass'       => $energyClass,
            'spyData'           => $spyData,
            'repair_order'      => $repair_order,
            'targetPlanet'      => [
                'name'          => $targetPlanet['name'],
                'planet_type'   => $targetPlanet['planet_type'],
                'galaxy'        => $targetPlanet['galaxy'],
                'system'        => $targetPlanet['system'],
                'planet'        => $targetPlanet['planet'],
            ],
            'targetChance'      => $targetChance,
            'spyChance'         => $spyChance,
            'totalShipDefCount' => $totalShipDefCount,
            'isBattleSim'       => ENABLE_SIMULATOR_LINK == true && $this->viewHelpers()->isModuleAvailable(MODULE_SIMULATOR),
        ];

        return $reportData;
    }

    /**
     * Loads the rows the report needs.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} sender user (with settings),
     *         target user (with settings), name and type of the sender's start planet
     */
    private function loadParticipants(): array
    {
        $db = $this->db;
        $sql = 'SELECT * FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id` 
            WHERE u.`id` = :userId;';
        $senderUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['owner'],
        ]);

        $targetUser = $db->selectSingle($sql, [
            ':userId'   => $this->_fleet['target_owner'],
        ]);

        $sql = 'SELECT `name`, `planet_type` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $senderPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ]);

        return [$senderUser, $targetUser, $senderPlanet];
    }

    /**
     * Adds the ships of the hold fleets that stand at the target at the arrival time to the planet's ship counts (the
     * probes see them too). Locks those fleet rows (FOR UPDATE).
     *
     * @param array<string, mixed> $targetPlanet planet row
     * @return array<string, mixed> the planet row with the stationed ships added (in memory only)
     */
    private function addHoldFleets(array $targetPlanet): array
    {
        $e = $this->elements();
        $sql = 'SELECT * FROM %%FLEETS%%
            WHERE `end_id` = :planetId
            AND `mission` = :mission
            AND `arrival_time` <= :time
            AND `stay_time` >= :time FOR UPDATE;';

        $targetStayFleets = $this->db->select($sql, [
            ':planetId' => $this->_fleet['end_id'],
            ':time'     => $this->_fleet['arrival_time'],
            ':mission'  => Mission::Hold->value,
        ]);

        foreach ($targetStayFleets as $fleetRow) {
            $fleetData = $this->fleetFunctions()->unserialize($fleetRow['ships']);
            foreach ($fleetData as $shipId => $shipAmount) {
                $targetPlanet[$e->column($shipId)] += $shipAmount;
            }
        }

        return $targetPlanet;
    }

    /**
     * Information level of the report: with the spy techs s (sender) and t (target) the probes needed for fleets are
     * n0 = +-((|s-t|*SPY_DIFFENCE_FACTOR)^2) (negative if the sender has the better tech); defense is visible with
     * n0+1*SPY_VIEW_FACTOR probes, buildings n0+3*, research n0+5*. Resources are always shown.
     *
     * @return array{0: array<int, array<int, int>>, 1: bool} element ids per class (900 resources, 200 fleet, 400 defense,
     *         0 buildings, 100 research; only the visible classes), and whether the fleet is visible
     */
    private function visibleClasses(int|float|string $fleetAmount, int|float|string $senderSpyTech, int|float|string $targetSpyTech): array
    {
        $e = $this->elements();
        $techDifference = abs($senderSpyTech - $targetSpyTech);
        $MinAmount = ($senderSpyTech > $targetSpyTech ? -1 : 1) * pow($techDifference * SPY_DIFFENCE_FACTOR, 2);
        $SpyFleet = $fleetAmount >= $MinAmount;
        $SpyDef = $fleetAmount >= $MinAmount + 1 * SPY_VIEW_FACTOR;
        $SpyBuild = $fleetAmount >= $MinAmount + 3 * SPY_VIEW_FACTOR;
        $SpyTechno = $fleetAmount >= $MinAmount + 5 * SPY_VIEW_FACTOR;

        $classIDs[900] = array_merge($e->resourceIds(1), $e->resourceIds(2));

        if ($SpyFleet) {
            $classIDs[200] = $e->ids('fleet');
        }

        if ($SpyDef) {
            $classIDs[400] = array_merge($e->ids('defense'), $e->ids('missile'));
        }

        if ($SpyBuild) {
            $classIDs[0] = $e->ids('build');
        }

        if ($SpyTechno) {
            $classIDs[100] = $e->ids('tech');
        }

        return [$classIDs, $SpyFleet];
    }

    /**
     * The amounts of the visible element classes: from the target user row if the column lives there (research),
     * otherwise from the planet; the decoy counts as missile launchers. The sender's "only show what exists" setting
     * drops zero entries.
     *
     * @param array<int, array<int, int>> $classIDs see visibleClasses()
     * @param array<mixed> $senderUser
     * @param array<string, mixed> $targetPlanet
     * @param array<mixed> $targetUser
     * @return array<int, array<int, int|float|string>> class id => element id => amount
     */
    private function collectSpyData(array $classIDs, array|false $senderUser, array|false $targetUser, array $targetPlanet): array
    {
        $e = $this->elements();
        $spyData = [];

        foreach ($classIDs as $classID => $elementIDs) {
            foreach ($elementIDs as $elementID) {
                if (!$this->buildFunctions()->isEnabled($elementID)) {
                    continue;
                }
                if (isset($targetUser[$e->column($elementID)])) {
                    if ($elementID == DECOY) {
                        $spyData[$classID][MISSILE_LAUNCHER] = $targetPlanet[$e->column($elementID)];
                    } else {
                        $spyData[$classID][$elementID] = $targetUser[$e->column($elementID)];
                    }
                } else {
                    if ($elementID == DECOY) {
                        $spyData[$classID][MISSILE_LAUNCHER] += $targetPlanet[$e->column($elementID)];
                    } else {
                        $spyData[$classID][$elementID] = $targetPlanet[$e->column($elementID)];
                    }
                }
            }

            if ($senderUser['spyMessagesMode'] == 1) {
                $spyData[$classID] = array_filter($spyData[$classID]);
            }
        }

        return $spyData;
    }

    /**
     * Shows the ships in the repair dock queue: adds them (with 0 if not on the planet) to the fleet block of the
     * report data. Wreck fields of moon fights are stored under the parent planet id.
     *
     * @param array<int, array<int, int|float|string>> $spyData report data, changed in place (class 200)
     * @param array<string, mixed> $targetPlanet
     * @return array<int|string, int|float|string> the decoded repair order (empty when not visible or nothing repairing)
     */
    private function addRepairDockShips(array &$spyData, bool $spyFleet, array $targetPlanet): array
    {
        $db = $this->db;
        $repair_order = [];
        if ($spyFleet && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) {
            // Wreck fields of moon fights are stored under the parent planet id
            $wreckPlanetID = $this->_fleet['end_id'];
            if ($targetPlanet['planet_type'] == PlanetType::Moon->value) {
                $wreckPlanetID = $db->selectSingle(
                    "SELECT `id` FROM %%PLANETS%% WHERE `id_luna` = :moonId;",
                    [':moonId' => $this->_fleet['end_id']],
                    'id',
                ) ?: $wreckPlanetID;
            }
            $sql = "SELECT `repair_order` FROM %%PLANET_WRECKFIELD%%
                WHERE `planetID` = :planetID;";
            $repairing = $db->selectSingle($sql, [
                ':planetID' => $wreckPlanetID,
            ], 'repair_order');
            if (!empty($repairing)) {
                $repair_order = StoredData::decode($repairing);
                foreach ($repair_order as $elementID => $amount) {
                    if (!isset($spyData[200][$elementID])) {
                        $spyData[200][$elementID] = 0;
                    }
                }
            }
        }

        return $repair_order;
    }

    /**
     * Sends the "foreign fleet seen" notice (spy defence message) to the target owner, in his language: who (sender
     * planet, owner, coordinates) probed which of his planets. Sent on every probe arrival.
     *
     * @param array<mixed> $senderPlanet
     * @param array<mixed> $senderUser
     * @param array<string, mixed> $targetPlanet
     * @param array<mixed> $targetUser
     */
    private function notifyTargetOwner(array|false $senderUser, array|false $targetUser, array|false $senderPlanet, array $targetPlanet): void
    {
        $LNG = $this->getLanguage($targetUser['lang']);

        $sourceLink = $LNG->format(
            'sys_mess_spy_link',
            $senderPlanet['name'],
            $senderUser['username'],
            $this->_fleet['start_galaxy'],
            $this->_fleet['start_system'],
            $this->_fleet['start_planet'],
        );

        $targetLink = $LNG->format(
            'sys_mess_spy_link',
            $targetPlanet['name'],
            $LNG['type_planet_short_' . $targetPlanet['planet_type']],
            $this->_fleet['end_galaxy'],
            $this->_fleet['end_system'],
            $this->_fleet['end_planet'],
        );

        $targetMessage = $LNG->format(
            'sys_mess_spy_ennemyfleet_' . $senderPlanet['planet_type'],
            $sourceLink,
            $targetLink,
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $LNG['sys_mess_spy_control'],
            MessageType::SpyDefence->value,
            $LNG['sys_mess_spy_activity'],
            $targetMessage,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * Counter-espionage succeeded: the probes are shot down. Their debris (fleet_debris_percentage of the probe cost)
     * goes to the debris field (a moon: its parent planet), the destroyed-ships stat is counted, the fleet is deleted.
     */
    private function destroyProbes(int|float|string $fleetAmount): void
    {
        $e = $this->elements();
        $config = $this->configs->get($this->_fleet['universe']);
        $whereCol = $this->_fleet['end_type'] == PlanetType::Moon->value ? "id_luna" : "id";

        $sql = 'UPDATE %%PLANETS%% SET
			der_metal   = der_metal + :metal,
			der_crystal = der_crystal + :crystal,
            tf_active = :new_debris
			WHERE ' . $whereCol . ' = :planetId;';

        $this->db->update($sql, [
            ':metal'    => $fleetAmount * $e->cost(SHIP_PROBE)->metal * $config->fleet_debris_percentage / 100,
            ':crystal'  => $fleetAmount * $e->cost(SHIP_PROBE)->crystal * $config->fleet_debris_percentage / 100,
            ':new_debris' => 1,
            ':planetId' => $this->_fleet['end_id'],
        ]);

        $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], SHIP_PROBE, $fleetAmount);

        $this->KillFleet();
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Probes are back: lands them at the start planet (no message; the report was sent on arrival).
     */
    public function ReturnEvent()
    {
        $this->RestoreFleet();
    }
}
