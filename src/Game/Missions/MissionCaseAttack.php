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

/**
 * Fleet mission "attack" (also as ACS group attack): the fight and everything around it is in AbstractBattleMission;
 * this class adds the chance to create a moon from the debris.
 */
class MissionCaseAttack extends AbstractBattleMission
{
    protected bool $deleteAcsWhenTargetIsGone = true;
    protected bool $acsLeadIsFirstFleet = true;
    protected bool $acsFleetsLocked = false;
    protected bool $targetUserWithSettings = true;

    /**
     * Arrival at the target: fights, applies losses, loot and debris, maybe creates a moon, reports to everybody and sends
     * the surviving fleets home. The fleet turns around when the target planet is gone, in vacation mode, or the engine
     * gives no result.
     */
    public function TargetEvent()
    {
        $config = $this->configs->get($this->_fleet['universe']);
        $stealResource = [
            RESOURCE_METAL      => 0,
            RESOURCE_CRYSTAL    => 0,
            RESOURCE_DEUT       => 0,
        ];
        $messageHTML = $this->getMessageText();

        $target = $this->lockTarget();
        if ($target === null) {
            return;
        }
        [$targetUser, $targetPlanet, $targetPlanetID] = $target;
        $setup = $this->collectParticipants($targetUser, $targetPlanet);

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $combatResult = $battleHandler->engine->fight($setup->fleetAttack, $setup->fleetDefend, $this->_fleet['universe']);
        $reportID = bin2hex(random_bytes(16));
        if (!$combatResult) {
            $this->cancelFight($setup->userAttack, $setup->userDefend, $targetPlanet);
            return;
        }

        if ($config->noob_protection && $config->rebuild_protection) {
            $battleHandler->CheckRebuildProtection($setup->currentTotalFleetState, $combatResult, $this->_fleet['arrival_time'], $config->noob_protection_time);
        }

        $this->applyLosses($setup, $reportID);

        if ($combatResult->won == ATTACKER) {
            $stealResource = $battleHandler->calculateSteal($setup->fleetAttack, $targetPlanet, $this->_fleet['universe']);
        }

        [$debris, $planetDebris] = $this->computeDebris($targetPlanet, $combatResult);
        [$reportInfo, $planetDebris] = $this->rollMoon($setup, $targetUser, $targetPlanet, $debris, $planetDebris, $stealResource);

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, REAL_FIGHT, $setup->fleetAttack, $setup->fleetDefend, $battleHandler->engine->getName());

        $battleHandler->GenerateWreckField($targetPlanetID, $combatResult, $targetUser['id'], $this->_fleet['universe']);

        foreach ($setup->userAttack as $attackerId => $fleet) {
            $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_ATTACK, $this->_fleet['arrival_time']);
        }
        foreach ($setup->userDefend as $defenderId => $fleet) {
            $this->statsHandler->setTimestamp($defenderId, AdvancedStatsEnum::FIRST_DEFENSE, $this->_fleet['arrival_time']);
        }
        $units = $combatResult->losses->totalValue();

        $class = $this->recordResultStats($setup, $combatResult, $units);
        $this->storeReport($setup, $battleHandler, $reportData, $reportID);
        $topkb = $this->sendReportMessages($setup, $combatResult, $reportID, $messageHTML, $class, $stealResource, $debris, $units);
        $this->writeDebris($planetDebris);
        $this->takeLoot($stealResource);
        if ($topkb) {
            $this->insertTopKb($combatResult, $reportID, $units);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Moon creation: the chance grows with the debris (moon_factor per 100000, capped by moon_chance; none when the
     * planet has a moon or the target is a moon); the cascading chance adds to it until the player got a first moon.
     * On success the moon is created for the target owner, stats are counted and the debris is cleared (debris_moon).
     *
     * @param array<int, int|float> $debris       new debris
     * @param array<int, int|float> $planetDebris debris field after the fight
     * @param array<int, int|float> $stealResource loot
     * @param array<string, mixed> $targetPlanet
     * @param array<mixed> $targetUser
     * @return array{0: array<string, mixed>, 1: array<int, int|float>} report info for the report, debris field after the moon roll
     */
    private function rollMoon(BattleSetup $setup, array $targetUser, array $targetPlanet, array $debris, array $planetDebris, array $stealResource): array
    {
        $config = $this->configs->get($this->_fleet['universe']);
        $userAttack = $setup->userAttack;
        $debrisResource = [RESOURCE_METAL, RESOURCE_CRYSTAL];

        $debrisTotal = array_sum($debris);

        $moonFactor = $config->moon_factor;
        $maxMoonChance = $config->moon_chance;

        if ($targetPlanet['id_luna'] == 0 && $targetPlanet['planet_type'] == PlanetType::Planet->value) {
            $chanceCreateMoon = floor($debrisTotal / 100000 * $moonFactor);
            $chanceCreateMoon = min($chanceCreateMoon, $maxMoonChance);
        } else {
            $chanceCreateMoon = 0;
        }

        $moons_received = $this->statsHandler->getValue($targetUser['id'], AdvancedStatsEnum::MOONS_RECEIVED);
        $additionalChance = 0;
        $triesWithThisFight = null;
        if ($config->cascading_moon_chance > 0 && $chanceCreateMoon == $maxMoonChance && $moons_received == 0) {
            $trys = $this->statsHandler->getValue($targetUser['id'], AdvancedStatsEnum::FIRST_MOON_TRYS);
            $additionalChance = $config->cascading_moon_chance * $trys;
            $this->statsHandler->increment($targetUser['id'], AdvancedStatsEnum::FIRST_MOON_TRYS, 1);
            $triesWithThisFight = $trys + 1;
        }

        $reportInfo = [
            'thisFleet'             => $this->_fleet,
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => $chanceCreateMoon,
            'additionalChance'      => $additionalChance,
            'moonDestroy'           => false,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => null,
        ];

        $moonId = false;
        $randChance = $this->roll(1, 100);
        if ($randChance <= $chanceCreateMoon + $additionalChance) {
            $LNG = $this->getLanguage($targetUser['lang']);
            $moonId = $this->planetManager()->createMoon(
                $this->_fleet['universe'],
                $this->_fleet['end_galaxy'],
                $this->_fleet['end_system'],
                $this->_fleet['end_planet'],
                $targetUser['id'],
                $chanceCreateMoon,
                $LNG['type_planet_3'],
            );

            // createMoon() gives false when the parent planet is gone or has a moon already: nothing was built
            if ($moonId) {
                $reportInfo['moonName'] = $LNG['type_planet_3'];
                $this->statsHandler->increment($targetUser['id'], AdvancedStatsEnum::MOONS_RECEIVED, 1);
                $this->achievementGuard()->run(fn() => $this->battleAchievements()->checkMoonAchievements(array_keys($userAttack), $chanceCreateMoon + $additionalChance));
                foreach ($userAttack as $attackerId => $fleet) {
                    $this->statsHandler->setTimestamp($attackerId, AdvancedStatsEnum::FIRST_MOON, $this->_fleet['arrival_time']);
                    $this->statsHandler->increment($attackerId, AdvancedStatsEnum::MOONS_CREATED, 1);
                }

                if ($config->debris_moon == 1) {
                    foreach ($debrisResource as $elementID) {
                        $planetDebris[$elementID] = 0;
                    }
                }
            }
        }

        if ($triesWithThisFight !== null && !$moonId) {
            $this->achievementGuard()->run(fn() => $this->fleetAchievements()->checkMoonTries($targetUser['id'], $triesWithThisFight, $moons_received));
        }

        return [$reportInfo, $planetDebris];
    }
}
