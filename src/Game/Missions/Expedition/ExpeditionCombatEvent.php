<?php

namespace App\Game\Missions\Expedition;

use App\Game\Enum\MessageType;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;

/**
 * Expedition event "pirates or aliens": by card size builds an enemy fleet (a few bonus ships plus a share of the player's
 * fleet, with tech reduced for pirates and raised for aliens), lets the battle engine fight it, stores the report, applies
 * the surviving ships to the fleet (the fleet is deleted when nothing survives) and sends the battle report message.
 *
 * Randomness: the sequence of rand/random_int calls is part of the game balance and must not change.
 */
class ExpeditionCombatEvent extends ExpeditionEvent
{
    /**
     * Enemy setup of the drawn card: pirate or alien (flag in the context; original ratio of pirates 58/84), attack
     * strength and bonus ships by size, enemy ships as a share of the player's fleet (pirates round down, aliens up).
     *
     * @param array<int, int|float> $fleetArray ship id => amount of the player's fleet
     * @return array{message: string, fleetData: array<int, int|float>, techbonus: int, username: string}
     */
    // pirate or alien. original: pirate chance = 58/84 ~ 69%
    private function setupPiratesOrAliens(array $fleetArray): array
    {
        $LNG = $this->LNG;
        $targetFleetData = [];
        $pirates = $this->ctx->isEventPirate;
        $attackFactor = 0;
        $Message = "";
        $bonusShip = SHIP_LIGHT_FIGHTER;

        // Use size category from deck, not generate a new one
        $sizeCategory = ExpeditionSize::category($this->ctx->eventsize);

        switch ($sizeCategory) {
            case 0:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_1_' . rand(1, 5)] : $LNG['sys_expe_attack_2_1_' . rand(1, 4)];
                $attackFactor = $pirates ? (30 + random_int(-3, 3)) / 100 : (40 + random_int(-4, 4)) / 100;
                $bonusShip = $pirates ? SHIP_LIGHT_FIGHTER : SHIP_HEAVY_FIGHTER;
                $targetFleetData[$bonusShip] = 5;
                $this->ctx->stats->increment($this->fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_SMALL : AdvancedStatsEnum::EXPO_ALIENS_SMALL, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_SMALL : AdvancedStatsEnum::FIRST_EXPO_ALIENS_SMALL, $this->fleet['stay_time']);
                break;
            case 1:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_2_' . rand(1, 3)] : $LNG['sys_expe_attack_2_2_' . rand(1, 3)];
                $attackFactor = $pirates ? (50 + random_int(-5, 5)) / 100 : (60 + random_int(-6, 6)) / 100;
                $bonusShip = $pirates ? SHIP_CRUISER : SHIP_BATTLECRUISER;
                $targetFleetData[$bonusShip] = 3;
                $this->ctx->stats->increment($this->fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_MEDIUM : AdvancedStatsEnum::EXPO_ALIENS_MEDIUM, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_MEDIUM : AdvancedStatsEnum::FIRST_EXPO_ALIENS_MEDIUM, $this->fleet['stay_time']);
                break;
            case 2:
                $Message = $pirates
                    ? $LNG['sys_expe_attack_1_3_' . rand(1, 3)] : $LNG['sys_expe_attack_2_3_' . rand(1, 3)];
                $attackFactor = $pirates ? (80 + random_int(-8, 8)) / 100 : (90 + random_int(-9, 9)) / 100;
                $bonusShip = $pirates ? SHIP_BATTLESHIP : SHIP_DESTROYER;
                $targetFleetData[$bonusShip] = 2;
                $this->ctx->stats->increment($this->fleet['owner'], $pirates ? AdvancedStatsEnum::EXPO_PIRATES_LARGE : AdvancedStatsEnum::EXPO_ALIENS_LARGE, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], $pirates ? AdvancedStatsEnum::FIRST_EXPO_PIRATES_LARGE : AdvancedStatsEnum::FIRST_EXPO_ALIENS_LARGE, $this->fleet['stay_time']);
                break;
        }

        foreach ($fleetArray as $shipId => $shipAmount) {
            if (!isset($targetFleetData[$shipId])) {
                $targetFleetData[$shipId] = 0;
            }

            $rawShips = $shipAmount * $attackFactor;
            $targetFleetData[$shipId] += $pirates ? floor($rawShips) : ceil($rawShips);
        }

        $targetFleetData = array_filter($targetFleetData);

        $returnArray = [
            'message' => $Message,
            'fleetData' => $targetFleetData,
            'techbonus' => $pirates ? -3 : 3,
            'username' => $pirates ? $LNG['sys_expe_attackname_1'] : $LNG['sys_expe_attackname_2'],
        ];


        return $returnArray;
    }

    /**
     * Builds the enemy side for the engine: fleet 0 owned by user id 0 at the expedition coordinates, ships from
     * setupPiratesOrAliens(), tech levels of the player's weapons/shield/armor plus the tech bonus (pirates -3,
     * aliens +3, never below 0), no resource bonuses.
     * @param BattleFleet $playerFleet the expedition fleet (attacker entry) as passed to the engine.
     * @return array{0: array<int, BattleFleet>, 1: string} defending fleets for the engine (the enemy as fleet 0), event message text.
     */
    private function determineEnemyData(BattleFleet $playerFleet): array
    {
        $encounterSetupData = $this->setupPiratesOrAliens($playerFleet->units);
        $techs = $playerFleet->techs->withBonus($encounterSetupData['techbonus']);

        $bonusList = $this->buildFunctions()->getBonusList();

        $enemy = new BattleFleet(
            0,
            [
                'id' => 0,
                'username' => $encounterSetupData['username'],
                'military_tech' => $techs->weapons,
                'shield_tech' => $techs->shields,
                'defence_tech' => $techs->armor,
                'factor' => array_fill_keys($bonusList, 0),
            ],
            $encounterSetupData['fleetData'],
            [
                'start_galaxy' => $this->fleet['end_galaxy'],
                'start_system' => $this->fleet['end_system'],
                'start_planet' => $this->fleet['end_planet'],
                'start_type' => 1,
                'end_galaxy' => $this->fleet['end_galaxy'],
                'end_system' => $this->fleet['end_system'],
                'end_planet' => $this->fleet['end_planet'],
                'end_type' => 1,
                'resource_metal' => 0,
                'resource_crystal' => 0,
                'resource_deuterium' => 0,
            ],
        );

        return [[0 => $enemy], $encounterSetupData['message']];
    }

    /**
     * Runs the fight.
     *
     * Side effects: report row, fleet ships/amount (or fleet deletion), battle report message to the owner.
     *
     * @return string report text of the event (including the report link)
     */
    public function run(): string
    {
        $LNG = $this->LNG;
        $config = $this->configs->get($this->fleet['universe']);
        $fleetArray = $this->fleetFunctions()->unserialize($this->fleet['ships']);

        $sql = 'SELECT * FROM %%USERS%% WHERE `id` = :userId;';

        $senderData = $this->db->selectSingle($sql, [
            ':userId' => $this->fleet['owner'],
        ]);

        $fleetID = $this->fleet['id'];

        $fleetAttack = [$fleetID => new BattleFleet($fleetID, $senderData, $fleetArray, $this->fleet)];

        // todo $Message as class variable? /discusswithself
        $enemyData = $this->determineEnemyData($fleetAttack[$fleetID]);
        $fleetDefend = $enemyData[0];
        $Message = $enemyData[1];

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);
        $combatResult = $battleHandler->engine->fight($fleetAttack, $fleetDefend, $this->fleet['universe']);
        if (!$combatResult) {
            // engine failure: no fight took place, the fleet stays as it is (the encounter text only)
            return $Message;
        }
        if (!$this->ctx->isEventPirate && ExpeditionSize::category($this->ctx->eventsize) === 2) {
            $this->achievementGuard()->run(fn() => $this->battleAchievements()->checkAlienHunter((int) $this->fleet['owner'], $combatResult));
        }
        $report = (new ExpeditionCombatReport($this->db, $this->configs, $this->state, $this->ctx))->generate($combatResult, $battleHandler, $fleetAttack, $fleetDefend);

        $fleetArray = '';
        $totalCount = 0;

        $fleetID = $this->fleet['id'];
        $fleetAttack[$fleetID]->units = array_filter($fleetAttack[$fleetID]->units);
        foreach ($fleetAttack[$fleetID]->units as $element => $amount) {
            $fleetArray .= $element . ',' . $amount . ';';
            $totalCount += $amount;
        }

        if ($totalCount <= 0) {
            $this->ctx->killFleet();
        } else {
            $this->ctx->updateFleet('ships', substr($fleetArray, 0, -1));
            $this->ctx->updateFleet('ship_amount', $totalCount);
            $this->ctx->updateFleetLog('ships', substr($fleetArray, 0, -1));
            $this->ctx->updateFleetLog('ship_amount', $totalCount);
            $this->ctx->updateFleetLog('report_id', $report['reportID']);
            $fleetArray = $this->fleetFunctions()->unserialize($fleetArray);
        }

        $Message .= '<br><br>' . $report['msg'];

        $this->playerMessenger()->sendMessage(
            $this->fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::BattleReport->value,
            $LNG['sys_mess_attack_report'],
            $report['msg'],
            $this->fleet['stay_time'],
            1,
            $this->fleet['universe'],
        );

        return $Message;
    }
}
