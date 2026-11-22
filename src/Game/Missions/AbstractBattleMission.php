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
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\ResourceUpdate;

/**
 * Shared part of the fleet missions that end in a real fight at the target planet (attack and moon destruction):
 * locks and loads the target, collects the attacking (incl. ACS group) and defending fleets (stay fleets and the planet),
 * applies the fleet and planet losses, computes the debris, records the win/lose/draw statistics, stores the combat
 * report, sends the report messages, writes the debris and the top-KB rows and builds the report message template.
 *
 * The subclasses keep their own TargetEvent() (order of the steps and what is special: moon creation for the attack,
 * moon and fleet destruction for the destruction mission). The fight itself is done by BattleHandler.
 */
abstract class AbstractBattleMission extends MissionFunctions implements MissionInterface
{
    use BattleFleetHandling;
    use BattleReporting;

    /** an attack on a deleted planet also deletes the ACS group of the fleet */
    protected bool $deleteAcsWhenTargetIsGone = false;
    /** the attacker of an ACS is the first fleet of the group (otherwise this fleet) */
    protected bool $acsLeadIsFirstFleet = false;
    /** row-lock the fleets of the ACS group (FOR UPDATE); false = read them ordered by id instead */
    protected bool $acsFleetsLocked = true;
    /** load the target user together with the settings (the attack report needs them) */
    protected bool $targetUserWithSettings = false;

    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    /**
     * Locks the target planet (and its wreck field row) and loads the target owner with resources calculated up to the
     * arrival time. Turns the fleet around (and returns null) when the planet no longer exists or its owner is in vacation.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int|string}|null target user, target planet, planet id the
     *         wreck field belongs to (the parent planet for a moon); null when the fleet was sent back
     */
    protected function lockTarget(): ?array
    {
        $db = $this->db;
        $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;";
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted
        if ($targetPlanet == false) {
            if ($this->deleteAcsWhenTargetIsGone && $this->_fleet['group'] != 0) {
                $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                    FROM %%AKS%%
                    LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                    WHERE %%AKS%%.`id` = :acsId;';

                $db->delete($sql, [
                    ':acsId' => $this->_fleet['group'],
                ]);
            }
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return null;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($this->returnIfTargetInVacation($targetPlanet['id_owner'])) {
            return null;
        }

        // select wreckfield to lock it, can be empty.
        // The repair dock can only be built on planets (uni1_vars.onPlanetType = 1), never on moons, so the wrecks of
        // a moon fight belong to the parent planet's dock. Battle, spy report and dock all use the parent planet id.
        if ($targetPlanet['planet_type'] == PlanetType::Moon->value) {
            $sql = "SELECT * FROM %%PLANETS%% WHERE `id_luna` = :planetId;";
            $targetPlanetID = $db->selectSingle($sql, [
                ':planetId' => $this->_fleet['end_id'],
            ], 'id');
        } else {
            $targetPlanetID = $targetPlanet['id'];
        }
        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% 
            WHERE `planetId` = :planetId FOR UPDATE;";
        $db->selectSingle($sql, [
            ':planetId' => $targetPlanetID,
        ]);

        $sql = $this->targetUserWithSettings
            ? "SELECT * FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` 
            WHERE u.`id` = :userId;"
            : "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
        $targetUser = $db->selectSingle($sql, [
            ':userId'   => $targetPlanet['id_owner'],
        ]);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);

        [$targetUser, $targetPlanet] = $planetUpdater->CalcResource(
            $targetUser,
            $targetPlanet,
            true,
            $this->_fleet['arrival_time'],
        );

        return [$targetUser, $targetPlanet, $targetPlanetID];
    }

    /**
     * Debris of the fight added to the debris field of the target (of the parent planet for a moon).
     *
     * @param array<string, mixed> $targetPlanet target planet row (moon: gets the parent planet's debris added; modified)
     * @param BattleResult $combatResult result of the engine
     * @return array{0: array<int, int|float>, 1: array<int, int|float>} new debris of the fight, debris field after the fight, by resource
     */
    protected function computeDebris(array &$targetPlanet, BattleResult $combatResult): array
    {
        $e = $this->elements();
        $db = $this->db;
        $debris = [];
        $planetDebris = [];
        $debrisResource = [RESOURCE_METAL, RESOURCE_CRYSTAL];

        if ($this->_fleet['end_type'] == PlanetType::Moon->value) {
            // Use planet debris, if attack on moons
            $sql = "SELECT `der_metal`, `der_crystal` FROM %%PLANETS%% WHERE `id_luna` = :moonId FOR UPDATE;";
            $targetDebris = $db->selectSingle($sql, [
                ':moonId'   => $this->_fleet['end_id'],
            ]);
            $targetPlanet['der_metal'] += $targetDebris['der_metal'];
            $targetPlanet['der_crystal'] += $targetDebris['der_crystal'];
        }

        foreach ($debrisResource as $elementID) {
            $debris[$elementID] = $combatResult->debris->total($elementID);
            $planetDebris[$elementID] = $targetPlanet['der_' . $e->column($elementID)] + $debris[$elementID];
        }

        return [$debris, $planetDebris];
    }

    /**
     * Counts won/lost/drawn fights (and first-win/big-win timestamps, set-sail win) of all attackers and defenders.
     *
     * @return array{0: string, 1: string} CSS classes of the attacker and defender side of the report
     */
    protected function recordResultStats(BattleSetup $setup, BattleResult $combatResult, int|float $units): array
    {
        $db = $this->db;
        $userAttack = $setup->userAttack;
        $userDefend = $setup->userDefend;
        $mainAttacker = $setup->mainAttacker;

        switch ($combatResult->won) {
            case ATTACKER:
                // Win
                $class = ['raportWin', 'raportLose'];

                $sql = "SELECT dpath, css_dpath FROM %%USERS_SETTINGS%% WHERE user_id = :id;";
                $theme = $db->select($sql, [
                    ':id' => $mainAttacker,
                ])[0];

                if ($theme['dpath'] == "SetSail" && $theme['css_dpath'] == "SetSail") {
                    $this->statsHandler->increment($mainAttacker, AdvancedStatsEnum::SET_SAIL_WINS, 1);
                }

                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::WONS, 1);
                    $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_ATTACK_WIN, $this->_fleet['arrival_time']);
                    if ($units > 1000000) {
                        $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_BIG_ATTACK_WIN, $this->_fleet['arrival_time']);
                    }
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::LOOS, 1);
                }
                break;
            case DEFENDER:
                // Lose
                $class = ['raportLose', 'raportWin'];

                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::LOOS, 1);
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::WONS, 1);
                    $this->statsHandler->setTimestamp($defenderId, AdvancedStatsEnum::FIRST_DEFENSE_WIN, $this->_fleet['arrival_time']);
                    if ($units > 1000000) {
                        $this->statsHandler->setTimestamp($defenderId, AdvancedStatsEnum::FIRST_BIG_DEFENSE_WIN, $this->_fleet['arrival_time']);
                    }
                }
                break;
            default:
                // Draw
                $class = ['raportDraw', 'raportDraw'];

                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::DRAWS, 1);
                }
                foreach ($userDefend as $defenderId => $fleet) {
                    $this->statsHandler->increment($defenderId, AdvancedStatsEnum::DRAWS, 1);
                }
                break;
        }

        return $class;
    }

    /**
     * Writes the debris field after the fight into the target planet (the parent planet of a moon) and activates it.
     *
     * @param array<mixed> $planetDebris
     */
    protected function writeDebris(array $planetDebris): void
    {
        $db = $this->db;
        if ($this->_fleet['end_type'] == PlanetType::Moon->value) {
            $debrisType = 'id_luna';
        } else {
            $debrisType = 'id';
        }

        $sql = "UPDATE %%PLANETS%% SET
            `der_metal` = :metal,
            `der_crystal` = :crystal,
            `tf_active` = :new_debris
            WHERE $debrisType = :planetId;";

        $db->update($sql, [
            ':metal'    => $planetDebris[901],
            ':crystal'  => $planetDebris[902],
            ':new_debris' => 1,
            ':planetId' => $this->_fleet['end_id'],
        ]);
    }

    /**
     * Takes the loot from the target planet.
     *
     * @param array<mixed> $stealResource
     */
    protected function takeLoot(array $stealResource): void
    {
        $db = $this->db;
        $sql = 'UPDATE %%PLANETS%% SET
            `metal` = `metal` - :metal,
            `crystal` = `crystal` - :crystal,
            `deuterium` = `deuterium` - :deuterium
            WHERE `id` = :planetId;';

        $db->update($sql, [
            ':metal'        => $stealResource[901],
            ':crystal'      => $stealResource[902],
            ':deuterium'    => $stealResource[903],
            ':planetId'     => $this->_fleet['end_id'],
        ]);
    }

    /**
     * Random source of the mission rolls (moon creation, moon and fleet destruction chances): uniform integer in
     * [$min, $max] from random_int(). A seam so tests can force an outcome; production never overrides it.
     */
    protected function roll(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    /**
     * Attack missions have no hold phase; nothing to do.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * The surviving fleet is home again: sends the "fleet returned" message (cargo, lost ships) and lands ships and loot on the start planet.
     */
    public function ReturnEvent()
    {
        $this->sendAttackReturnMessage();

        $this->RestoreFleet();
    }
}
