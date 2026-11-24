<?php

namespace App\Game\Missions\Expedition;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Engagement\Events\EventModifiers;

/**
 * Expedition event "found abandoned ships": by card size a point budget is drawn, ships the fleet can find (see
 * ExpeditionFindableShipsHandler) are drawn until the budget is used up (at most 3 failed draws), and the found ships are
 * added to the fleet.
 *
 * Randomness: the sequence of rand/random_int calls is part of the game balance and must not change.
 */
class ExpeditionShipEvent extends ExpeditionEvent
{
    /**
     * Ships the fleet can find (depends on the ships it consists of, see ExpeditionFindableShipsHandler).
     *
     * @param array<int, int|float> $fleetArray
     * @return list<int> ship ids
     */
    private function determineFindableShips(array $fleetArray): array
    {
        return $this->expeditionShips()->findableShipsForFleet($this->fleet['universe'], $fleetArray);
    }

    /**
     * Draws ships until the point budget is used up: random findable ship, random count up to what the remaining points
     * pay for; a draw that finds nothing counts against 3 allowed failures. Sets the event value of the log.
     *
     * @param int       $foundPoints   point budget (ship cost in metal + crystal)
     * @param list<int> $findableShips ship ids that may be found
     * @return array<int, int> ship id => found amount
     */
    private function determineFoundShips(int $foundPoints, array $findableShips): array
    {
        $e = $this->elements();
        $Found = [];
        $upperValue = 3;

        $remainingPoints = $foundPoints;
        while ($findableShips !== [] && $upperValue > 0) {
            $ID = $findableShips[rand(0, count($findableShips) - 1)];
            $shipCost = ($e->cost($ID)->metal + $e->cost($ID)->crystal);
            if ($shipCost <= 0) {
                $upperValue--;
                continue;
            }

            $MaxFound = floor($remainingPoints / $shipCost);
            if ($MaxFound <= 0) {
                $upperValue--;
                continue;
            }

            $Count = random_int(0, $MaxFound);

            if ($Count <= 0) {
                $upperValue--;
                continue;
            }

            // determine how many shipX were found, subtract their points from $remainingPoints, break if negative
            if (array_key_exists($ID, $Found)) {
                $Found[$ID] += $Count;
            } else {
                $Found[$ID] = $Count;
            }
            $remainingPoints -= $Count * $shipCost;
            if ($remainingPoints <= 0) {
                break;
            }
        }
        $this->ctx->eventvalue = "{";
        foreach ($Found as $shipId => $shipAmount) {
            $this->ctx->eventvalue .= '"' . $shipId . '":' . $shipAmount . ",";
        }
        $this->ctx->eventvalue = rtrim($this->ctx->eventvalue, ",") . "}";
        return $Found;
    }

    /**
     * Runs the event: updates the fleet's ships and amount (and its log), counts the advanced stats.
     *
     * @return string report text of the event
     */
    public function run(): string
    {
        $e = $this->elements();

        $LNG = $this->LNG;
        $Size = 0;
        $Message = "";
        $fleetPoints = $this->fleetValue()->pointsAndCapacity($this->fleet)[0]; // only points, capacity is irrelevant
        $fleetArray = $this->fleetFunctions()->unserialize($this->fleet['ships']);

        // Use size category from deck, not generate a new one
        $sizeCategory = ExpeditionSize::category($this->ctx->eventsize);

        switch ($sizeCategory) {
            case 0:
                $Size = random_int(10, 50);
                $Message = $LNG['sys_expe_found_ships_1_' . random_int(1, 4)];
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_SMALL, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_SMALL, $this->fleet['stay_time']);
                break;
            case 1:
                $Size = random_int(52, 100);
                $Message = $LNG['sys_expe_found_ships_2_' . random_int(1, 2)];
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_MEDIUM, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_MEDIUM, $this->fleet['stay_time']);
                break;
            case 2:
                $Size = random_int(102, 200);
                $Message = $LNG['sys_expe_found_ships_3_' . random_int(1, 2)];
                $this->ctx->stats->increment($this->fleet['owner'], AdvancedStatsEnum::EXPO_SHIPS_LARGE, 1);
                $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::FIRST_EXPO_SHIPS_LARGE, $this->fleet['stay_time']);
                break;
        }

        // universe event (expedition finds +x %): scales the drawn size, the MaxPoints cap below still holds
        $Size *= $this->eventModifiers()->factor((int) $this->fleet['universe'], EventModifiers::EXPEDITION_FIND) * $this->relicFindFactor(); // + relics of the start planet

        $MaxPoints = $this->fleetFunctions()->calculateMaxFactor($this->fleet['universe']);

        if ($fleetPoints < $MaxPoints) {
            $this->ctx->logbook = $LNG['sys_expe_found_ships_logbook_' . random_int(1, 3)] . '<br>' . $this->ctx->logbook;
        }

        $FoundShipMess = "";
        $NewFleetArray = "";
        $CountTotal = 0;
        $foundPoints = max(round($Size * min($fleetPoints, $MaxPoints)), 10000);
        $findableShips = $this->determineFindableShips($fleetArray);
        $Found = $this->determineFoundShips($foundPoints, $findableShips);

        if (empty($Found)) {
            $Message .= '<br><br>' . $LNG['sys_expe_found_ships_nothing'];
        } else {
            foreach ($e->ids('fleet') as $ID) {
                $Count = 0;
                if (!empty($Found[$ID])) {
                    $Count += $Found[$ID];
                    $FoundShipMess .= '<br>' . $LNG['tech'][$ID] . ': ' . $this->viewHelpers()->prettyNumber($Count);
                    $this->ctx->stats->setTimestamp($this->fleet['owner'], AdvancedStatsEnum::getExpoFirst($ID), $this->fleet['stay_time']);
                }
                if (!empty($fleetArray[$ID])) {
                    $Count += $fleetArray[$ID];
                }

                if ($Count > 0) {
                    $NewFleetArray .= $ID . "," . floatToString($Count) . ';';
                    $CountTotal += $Count;
                }
            }
            $this->ctx->stats->updateFoundShipsAdvancedStats($this->fleet['owner'], $Found);
            $Message .= '<br><br>' . $LNG['sys_expe_back_home_ships_flound'] . $FoundShipMess;

            // only a find changes the fleet (with nothing found the fleet was written with no ships for the return flight)
            $this->ctx->updateFleet('ships', substr($NewFleetArray, 0, -1));
            $this->ctx->updateFleet('ship_amount', $CountTotal);
            $this->ctx->updateFleetLog('ships', substr($NewFleetArray, 0, -1));
            $this->ctx->updateFleetLog('ship_amount', $CountTotal);
        }

        return $Message;
    }

}
