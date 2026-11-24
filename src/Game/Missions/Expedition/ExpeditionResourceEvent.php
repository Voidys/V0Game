<?php

namespace App\Game\Missions\Expedition;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Engagement\Events\EventModifiers;

/**
 * Expedition event "found resources": by card size picks a flavour text and a factor, draws which resource (by the
 * universe chances for metal/crystal, else deuterium; crystal counts half, deuterium a third), and puts the amount
 * (limited by the fleet's free cargo room) into the fleet cargo.
 *
 * Randomness: the sequence of random_int calls is part of the game balance and must not change.
 */
class ExpeditionResourceEvent extends ExpeditionEvent
{
    /**
     * Runs the event: updates the fleet cargo and its log, counts the advanced stats.
     *
     * @return string report text of the event
     */
    public function run(): string
    {
        $e = $this->elements();

        $LNG = $this->LNG;
        $config = $this->configs->get($this->fleet['universe']);
        $factor = 0;

        $fleetData = $this->fleetValue()->pointsAndCapacity($this->fleet);
        $fleetPoints = $fleetData[0];
        $fleetCapacity = $fleetData[1];

        // Use size category from deck, not generate a new one
        $sizeCategory = ExpeditionSize::category($this->ctx->eventsize);

        switch ($sizeCategory) {
            case 0:
                $Message = $LNG['sys_expe_found_ress_1_' . random_int(1, 4)];
                $factor = random_int(10, 50);
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_RES_SMALL, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_SMALL, $this->fleet['stay_time']);
                break;
            case 1:
                $Message = $LNG['sys_expe_found_ress_2_' . random_int(1, 3)];
                $factor = random_int(50, 100);
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_RES_MEDIUM, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_MEDIUM, $this->fleet['stay_time']);
                break;
            case 2:
                $Message = $LNG['sys_expe_found_ress_3_' . random_int(1, 2)];
                $factor = random_int(100, 200);
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_RES_LARGE, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_RES_LARGE, $this->fleet['stay_time']);
                break;
        }

        $chanceToFound = random_int(1, 100);
        if ($chanceToFound <= $config->expo_ress_met_chance) {
            $resourceId = RESOURCE_METAL;
        } elseif ($chanceToFound <= $config->expo_ress_met_chance + $config->expo_ress_crys_chance) {
            $resourceId = RESOURCE_CRYSTAL;
            $factor = $factor / 2;
        } else {
            $resourceId = RESOURCE_DEUT;
            $factor = $factor / 3;
        }

        // universe event (expedition finds +x %): scales the drawn factor, the cargo room limit below still holds
        $factor *= $this->eventModifiers()->factor((int) $this->fleet['universe'], EventModifiers::EXPEDITION_FIND) * $this->relicFindFactor(); // + relics of the start planet

        $foundResources = $factor * max(min($fleetPoints, $this->fleetFunctions()->calculateMaxFactorRes($this->fleet['universe'])), 200);

        if ($fleetCapacity < $foundResources) {
            $foundResources = $fleetCapacity;
            $Message .= '<br><br>' . $LNG->format(
                'sys_expe_found_goods',
                $this->viewHelpers()->prettyNumber($foundResources),
                $LNG['tech'][$resourceId],
            );
            $this->ctx->logbook = $LNG['sys_expe_found_ress_logbook_' . random_int(1, 4)] . '<br>' . $this->ctx->logbook;
        } else {
            $Message .= '<br><br>' . $LNG->format(
                'sys_expe_found_goods',
                $this->viewHelpers()->prettyNumber($foundResources),
                $LNG['tech'][$resourceId],
            );
        }
        $this->ctx->eventvalue = '{"' . $resourceId . '":' . $foundResources . "}";
        $fleetColName = 'resource_' . $e->column($resourceId);
        $this->ctx->updateFleet($fleetColName, $this->fleet[$fleetColName] + $foundResources);
        $logColName = 'gained_' . $e->column($resourceId);
        $this->ctx->updateFleetLog($logColName, $foundResources);
        $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::getExpoFound($resourceId), $foundResources);
        $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::getExpoFirst($resourceId), $this->fleet['stay_time']);

        return $Message;
    }
}
