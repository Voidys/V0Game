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

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\CombatReportValue;
use App\Routing\GameRoute;
use App\Game\Core\BattleReportStore;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;

/**
 * Battle simulator: prepares the form with the player's techs and resources and renders the simulated report.
 */
#[\App\Controller\RequireModule('MODULE_SIMULATOR')]
class BattleSimulatorController extends AbstractGameController
{
    /** Most ships and defenses of all slots together one simulation may contain (a fight of 20 million per side takes about 17 s of CPU). */
    public const MAX_SIMULATED_UNITS = 10000000;

    /**
     * Runs a simulation (XHR). Request: battleinput (slots with fleets, techs and resources; sanitized, ids numeric and amounts capped). Throttled to 10
     * stored simulations per user per minute (marker `sim:<userId>` in RW.attacker). Simulates with the battle engine on virtual fleets (fixed 1:33:7
     * coordinates), stores the report in RW and answers the report id as JSON, or 0 for invalid or rejected input.
     */
    #[GameRoute('game', 'game/simulator', 'battleSimulator', 'send', ['POST'])]
    public function send(): Response
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;
        if (!isset($this->state->requestInput['battleinput'])) {
            return $this->jsonResponse(0);
        }

        $BattleArray = $this->sanitizeBattleInput($this->state->requestInput['battleinput']);
        if ($BattleArray === null) {
            return $this->jsonResponse(0);
        }

        // Throttle: stored simulator reports are marked in `attacker`, at most 10 per user per minute
        $simMarker = 'sim:' . $this->state->USER['id'];
        if ($this->recentSimulations($simMarker) >= 10) {
            return $this->jsonResponse(0);
        }

        [$fleetAttack, $fleetDefend, $battleElementAmount] = $this->simulatedFleets($BattleArray);

        //Move defending planet to the end to match mission attack
        if (empty($fleetAttack) || !isset($fleetDefend[0])) {
            return $this->jsonResponse(0);
        }
        $tempFleet = $fleetDefend[0];
        unset($fleetDefend[0]);
        $fleetDefend[0] = $tempFleet;
        // the fleets are keyed by their list position (as before); the planet is fleet 0 and fires last

        if ($battleElementAmount > self::MAX_SIMULATED_UNITS) {
            return $this->jsonResponse($this->state->LNG->format('bs_too_many_units', $this->viewHelpers()->prettyNumber(self::MAX_SIMULATED_UNITS)));
        }

        $LNG->includeData(['FLEET']);


        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);
        // one simulation at a time per player: the throttle above only counts stored reports, parallel requests would all pass it
        $lockName = $this->db->getDatabaseName() . ':' . $simMarker;
        if (!(int) $this->db->selectSingle('SELECT GET_LOCK(:name, 0) AS acquired;', [':name' => $lockName], 'acquired')) {
            return $this->jsonResponse($this->state->LNG['bs_busy']);
        }
        try {
            $combatResult = $battleHandler->engine->fight($fleetAttack, $fleetDefend, $this->state->USER['universe'], true);
        } finally {
            $this->db->selectSingle('SELECT RELEASE_LOCK(:name) AS released;', [':name' => $lockName], 'released');
        }

        if (!$combatResult) {
            return $this->jsonResponse(0); // return
        }

        // resources of the defending planet (slot 0) the attackers can plunder
        $defenderResources = [
            'metal' => getNumber($BattleArray[0][1][RESOURCE_METAL]),
            'crystal' => getNumber($BattleArray[0][1][RESOURCE_CRYSTAL]),
            'deuterium' => getNumber($BattleArray[0][1][RESOURCE_DEUT]),
        ];

        [$stealResource, $capacityNeeded] = $this->plunder($battleHandler, $combatResult, $fleetAttack, $defenderResources);

        $debris = [];

        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL] as $elementID) {
            $debris[$elementID] = $combatResult->debris->total($elementID);
        }

        $stealResourceInformation = $this->stealInformation($capacityNeeded);

        $reportInfo = $this->simulationReportInfo($debris, $stealResource, $this->moonChance($debris), $stealResourceInformation);

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, SIM_FIGHT, $fleetAttack, $fleetDefend, $battleHandler->engine->getName());
        unset($reportData['repaired']);

        return $this->jsonResponse($this->storeReport($reportData, $battleHandler->engine->getName(), $simMarker));
    }

    /** The text about how many small and large cargos the plunder of the defending planet would need. */
    private function stealInformation(float|int $capacityNeeded): string
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;

        return $LNG->format(
            'bs_steal_raport',
            $this->viewHelpers()->prettyNumber(ceil($capacityNeeded / $e->capacity(SHIP_SMALL_CARGO))),
            $LNG['tech'][SHIP_SMALL_CARGO],
            $this->viewHelpers()->prettyNumber(ceil($capacityNeeded / $e->capacity(SHIP_LARGE_CARGO))),
            $LNG['tech'][SHIP_LARGE_CARGO],
        );
    }

    /**
     * Plunder of the simulated fight: the resources the winning attackers would steal (zero unless the attackers won) and the
     * cargo capacity that would be needed to carry everything the defending planet holds.
     *
     * @param array<int, BattleFleet> $fleetAttack
     * @param array<string, int|float> $defenderResources metal, crystal and deuterium of the defending planet
     * @return array{0: array<int|string, int|float>, 1: int|float} stolen resources, capacity needed
     */
    private function plunder(BattleHandler $battleHandler, object $combatResult, array $fleetAttack, array $defenderResources): array
    {
        if ($combatResult->won == ATTACKER) {
            $stealResource = $battleHandler->calculateSteal($fleetAttack, $defenderResources, $this->state->USER['universe'], true);
        } else {
            $stealResource = [
                RESOURCE_METAL => 0,
                RESOURCE_CRYSTAL => 0,
                RESOURCE_DEUT => 0,
            ];
        }

        return [$stealResource, $battleHandler->calculateMinCapacity($defenderResources)];
    }

    /**
     * Chance (percent) that the simulated battle would create a moon: debris per 100000 times the universe's moon factor, rounded
     * down and capped at the universe's maximum moon chance.
     *
     * @param array<int, int|float> $debris metal and crystal debris
     */
    private function moonChance(array $debris): int|float
    {
        $debrisTotal = array_sum($debris);

        $moonFactor = $this->configs->get()->moon_factor;
        $maxMoonChance = $this->configs->get()->moon_chance;

        $chanceCreateMoon = floor($debrisTotal / 100000 * $moonFactor);

        return min($chanceCreateMoon, $maxMoonChance);
    }

    /**
     * Report info block of a simulated fight: the virtual start/end position 1:33:7, debris, plunder, moon chance and the cargo hint;
     * all moon/fleet destruction fields stay empty (nothing is destroyed by a simulation).
     *
     * @param array<int, int|float> $debris
     * @param array<int|string, int|float> $stealResource
     * @return array<string, mixed>
     */
    private function simulationReportInfo(array $debris, array $stealResource, int|float $chanceCreateMoon, string $stealResourceInformation): array
    {
        return [
            'thisFleet'             => [
                'start_galaxy'    => 1,
                'start_system'    => 33,
                'start_planet'    => 7,
                'start_type'      => 1,
                'end_galaxy'      => 1,
                'end_system'      => 33,
                'end_planet'      => 7,
                'end_type'        => 1,
                'arrival_time'      => $this->state->runtime->timestamp,
            ],
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => $chanceCreateMoon,
            'additionalChance'      => 0,
            'moonDestroy'           => false,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => null,
            'additionalInfo'        => $stealResourceInformation,
        ];
    }

    /**
     * Number of simulator reports the user stored in the last minute (the stored reports carry the marker in `attacker`).
     */
    private function recentSimulations(string $simMarker): int|string
    {
        $sql = "SELECT COUNT(*) AS cnt FROM %%RW%% WHERE `attacker` = :marker AND `time` > :since;";

        return $this->db->selectSingle($sql, [
            ':marker' => $simMarker,
            ':since'  => $this->state->runtime->timestamp - 60,
        ], 'cnt');
    }

    /**
     * Builds the attacking and defending fleets of all slots from the sanitized input. Slots without ships are skipped
     * (except slot 0); non-ship entries and, for attackers, solar satellites are dropped. Counts the ships and defenses
     * for the overall size limit.
     *
     * @param array<int, array<int, array<int, int|float>>> $BattleArray slot => side (0 attacker, 1 defender) => element id => amount
     * @return array{0: list<BattleFleet>, 1: list<BattleFleet>, 2: int|float} attackers, defenders, total amount of elements
     */
    private function simulatedFleets(array $BattleArray): array
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;
        $battleElementAmount = 0;
        $fleetDefend = [];
        $fleetAttack = [];
        foreach ($BattleArray as $BattleSlotID => $BattleSlot) {
            if (isset($BattleSlot[0]) && ($this->slotHasUnits($BattleSlot[0], $e->ids('fleet')) || $BattleSlotID == 0)) {
                $attackerPlayer = [
                    'id' => (1000 + $BattleSlotID + 1),
                    'username'  => $LNG['bs_atter'] . ' Nr.' . ($BattleSlotID + 1),
                    'military_tech' => getNumber($BattleSlot[0][MILITARY_TECH]),
                    'shield_tech' => getNumber($BattleSlot[0][SHIELD_TECH]),
                    'defence_tech' => getNumber($BattleSlot[0][DEFENCE_TECH]),
                ];

                foreach ($BattleSlot[0] as $ID => $Count) {
                    $isFleet = in_array($ID, $e->ids('fleet'));
                    if (!$isFleet || getNumber($BattleSlot[0][$ID]) <= 0 || $ID == SHIP_SOLSAT) {
                        unset($BattleSlot[0][$ID]);
                    } elseif ($isFleet) {
                        $battleElementAmount += getNumber($BattleSlot[0][$ID]);
                    }
                }

                $fleetAttack[] = new BattleFleet(count($fleetAttack), $attackerPlayer, $BattleSlot[0], $this->simulatedFleetDetail());
            }

            if (isset($BattleSlot[1]) && ($this->slotHasUnits($BattleSlot[1], array_merge($e->ids('fleet'), $e->ids('defense'))) || $BattleSlotID == 0)) {
                $defenderPlayer = [
                    'id' => (2000 + $BattleSlotID + 1),
                    'username'  => $LNG['bs_deffer'] . ' Nr.' . ($BattleSlotID + 1),
                    'military_tech' => getNumber($BattleSlot[1][MILITARY_TECH]),
                    'shield_tech' => getNumber($BattleSlot[1][SHIELD_TECH]),
                    'defence_tech' => getNumber($BattleSlot[1][DEFENCE_TECH]),
                ];

                foreach ($BattleSlot[1] as $ID => $Count) {
                    $isBattleElement = in_array($ID, $e->ids('fleet')) || in_array($ID, $e->ids('defense'));
                    if (!$isBattleElement || getNumber($BattleSlot[1][$ID]) <= 0) {
                        unset($BattleSlot[1][$ID]);
                    } elseif ($isBattleElement) {
                        $battleElementAmount += getNumber($BattleSlot[1][$ID]);
                    }
                }

                $fleetDefend[] = new BattleFleet(count($fleetDefend), $defenderPlayer, $BattleSlot[1], $this->simulatedFleetDetail());
            }
        }

        return [$fleetAttack, $fleetDefend, $battleElementAmount];
    }

    /**
     * Whether one side of a slot holds any ship or defense unit (the tech levels and resources of the slot do not count).
     *
     * @param array<int, int|float> $side element id => amount
     * @param array<int, int> $unitIds ids that count as units
     */
    private function slotHasUnits(array $side, array $unitIds): bool
    {
        foreach ($side as $id => $count) {
            if (in_array($id, $unitIds) && getNumber($count) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores the simulated report under a fresh random id (RW row marked with the throttle marker; rust reports as
     * compressed JSON, the PHP engine's as serialized data).
     *
     * @param array<string, mixed> $reportData generated battle report
     * @return string the report id
     */
    private function storeReport(array $reportData, string $engineName, string $simMarker): string
    {
        $reportID = bin2hex(random_bytes(16));

        (new BattleReportStore($this->db))->insert($reportID, $reportData, $engineName, $this->state->runtime->timestamp, $simMarker);

        return $reportID;
    }

    /**
     * Fleet row of a simulated fleet: every slot starts and ends at the virtual position 1:33:7 and carries no resources.
     *
     * @return array<string, int>
     */
    private function simulatedFleetDetail(): array
    {
        return [
            'start_galaxy' => 1,
            'start_system' => 33,
            'start_planet' => 7,
            'start_type' => 1,
            'end_galaxy' => 1,
            'end_system' => 33,
            'end_planet' => 7,
            'end_type' => 1,
            'resource_metal' => 0,
            'resource_crystal' => 0,
            'resource_deuterium' => 0,
        ];
    }

    /**
     * Normalises the posted slots: only numeric ids, amounts cast to non-negative numbers (capped), techs capped.
     * Slot 0 needs both an attacker [0] and a defender [1] entry, otherwise null is returned.
     *
     * @return array<mixed>
     */
    private function sanitizeBattleInput(mixed $input): ?array
    {
        if (!is_array($input) || !isset($input[0][0], $input[0][1]) || !is_array($input[0][0]) || !is_array($input[0][1])) {
            return null;
        }

        $techs = [MILITARY_TECH, SHIELD_TECH, DEFENCE_TECH];
        $clean = [];
        foreach ($input as $slotID => $slot) {
            if (!is_numeric($slotID) || !is_array($slot) || count($clean) >= 16) {
                continue;
            }
            foreach ([0, 1] as $side) {
                if (!isset($slot[$side]) || !is_array($slot[$side])) {
                    continue;
                }
                foreach ($slot[$side] as $ID => $amount) {
                    if (!is_numeric($ID) || !is_scalar($amount)) {
                        continue;
                    }
                    $amount = floor(max(0, (float) getNumber($amount)));
                    $clean[(int) $slotID][$side][(int) $ID] = in_array((int) $ID, $techs)
                        ? min($amount, 255)
                        : min($amount, 135000000);
                }
            }
        }

        if (!isset($clean[0][0], $clean[0][1])) {
            return null;
        }
        foreach ([0, 1] as $side) {
            foreach ($techs as $tech) {
                $clean[0][$side][$tech] ??= 0;
            }
        }
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $res) {
            $clean[0][1][$res] ??= 0;
        }
        foreach ($clean as $slotID => $slot) {
            foreach ($slot as $side => $values) {
                foreach ($techs as $tech) {
                    $clean[$slotID][$side][$tech] ??= 0;
                }
            }
        }

        return $clean;
    }

    /**
     * Simulator page. Request: slots (number of slots), battleinput[] (prefill, e.g. from a spy report; otherwise the form starts with the player's
     * own techs).
     */
    #[GameRoute('game', 'game/simulator', 'battleSimulator')]
    public function show(): Response
    {
        return $this->simulatorPage('');
    }

    /** Reloads the form with one more attacker slot (POST of the form: slots, battleinput). */
    #[GameRoute('game', 'game/simulator/more-slots', 'battleSimulator', 'moreSlots', ['GET', 'POST'])]
    public function moreSlots(): Response
    {
        if ($redirect = $this->redirectUnlessPost('game_battle_simulator_show')) {
            return $redirect;
        }

        return $this->simulatorPage('moreslots');
    }

    /** Reloads the form with attackers and defenders swapped (POST of the form: slots, battleinput). */
    #[GameRoute('game', 'game/simulator/switch', 'battleSimulator', 'switch', ['GET', 'POST'])]
    public function switch(): Response
    {
        if ($redirect = $this->redirectUnlessPost('game_battle_simulator_show')) {
            return $redirect;
        }

        return $this->simulatorPage('switch');
    }

    /** The simulator form for the action of the route ('' = just show, 'moreslots', 'switch'). */
    private function simulatorPage(string $action): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $e = $this->elements();
        $Slots = $this->input()->int('slots', 1);

        $BattleArray[0][0][MILITARY_TECH] = $USER[$e->column(MILITARY_TECH)];
        $BattleArray[0][0][SHIELD_TECH] = $USER[$e->column(SHIELD_TECH)];
        $BattleArray[0][0][DEFENCE_TECH] = $USER[$e->column(DEFENCE_TECH)];

        if (empty($this->state->requestInput['battleinput'])) {
            foreach ($e->resourceIds(1) as $ID) {
                $BattleArray[0][0][$ID] = floor($PLANET[$e->column($ID)]);
            }
            foreach ($e->ids('fleet') as $ID) {
                $BattleArray[0][0][$ID] = $PLANET[$e->column($ID)];
            }
            foreach ($e->ids('defense') as $ID) {
                $BattleArray[0][0][$ID] = $PLANET[$e->column($ID)];
            }
        } else {
            $BattleArray = $this->input()->stringTree('battleinput');
            // "simulate again" from a combat report: raise the attacker's ships by a percentage (0 to 1000)
            $boost = max(0, min(1000, $this->input()->int('boost', 0)));
            $BattleArray = CombatReportValue::boostAttackers($BattleArray, $e->ids('fleet'), $boost);
        }

        if ($action == 'moreslots') {
            $Slots += 1;
        } elseif ($action == 'switch') {
            foreach ($BattleArray as $slot => $a) {
                $temp = $BattleArray[$slot][0];
                $BattleArray[$slot][0] = $BattleArray[$slot][1];
                $BattleArray[$slot][1] = $temp;
                unset($temp);
            }
        }

        if (isset($this->state->requestInput['im'])) {
            foreach ($this->input()->bigNumberMap('im') as $ID => $Count) {
                $BattleArray[0][1][$ID] = floatToString($Count);
            }
        }

        $this->addScript('battlesim.js');

        return $this->render('game/page.battleSimulator.default.html.twig', [
            'Slots'         => $Slots,
            'battleinput'   => $BattleArray,
            'fleetList'     => $e->ids('fleet'),
            'defensiveList' => $e->ids('defense'),
        ]);
    }
}
