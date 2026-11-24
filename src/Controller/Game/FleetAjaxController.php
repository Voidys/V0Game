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

use App\Game\Enum\Mission;
use App\Game\Engagement\MapContent\NpcTargets;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\AdvancedStats\AdvancedStatsEnum;

/** Fleet shortcuts from the galaxy view (XHR): spy probes, recyclers and missiles, answered as JSON codes. */
class FleetAjaxController extends AbstractGameController
{
    public $returnData = [];

    /** A confirmed attack on a stronger player: the outlaw status is written when the fleet is really sent. */
    private bool $outlawPending = false;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * The answer {code, mess, slots, ...}: unwinds the nested checks as a FleetAjaxAnswer,
     * which show() turns into the JSON response.
     */
    private function sendData($Code, $Message): never
    {
        $this->returnData['code'] = $Code;
        $this->returnData['mess'] = $Message;

        throw new FleetAjaxAnswer($this->returnData);
    }

    /**
     * Galaxy view shortcuts (XHR). Request: planetID (target planet), mission (spy, recycling, missile, pirate base variants), confirm.
     * Checks fleet slots, ships and target restrictions (noob protection, vacation, own planet), may send probes or recyclers directly, and
     * answers JSON codes. Missile attacks are only checked here; they are launched by the missile page.
     */
    #[GameRoute('game', 'game/fleet/ajax', 'fleetAjax', 'show', ['POST'])]
    public function show(): Response
    {
        // user row, then planet row (the order of FleetDispatch): slot count, ships and fuel are read from the locked
        // rows, so parallel requests cannot all see a free slot. Every answer unwinds with sendData() (FleetAjaxAnswer, caught below);
        // nothing is written before the fleet is sent, so committing on it is right for refusals and success alike.
        $db = $this->db;
        $db->startTransaction();
        try {
            $this->lockPlayerAndPlanet();
            $this->handleRequest();
        } catch (FleetAjaxAnswer $answer) {
            $db->commit();

            return $answer->data === null ? new Response() : $this->jsonResponse($answer->data);
        } catch (\Throwable $failure) {
            $db->rollback();
            throw $failure;
        }
        $db->commit();

        return new Response();
    }

    /** Locks the user and the current planet row and refreshes the planet copy (ships, fuel) from the locked row. */
    private function lockPlayerAndPlanet(): void
    {
        $this->db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :userId FOR UPDATE;', [':userId' => $this->state->USER['id']]);
        $planet = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;', [':planetId' => $this->state->PLANET['id']]);
        if (!empty($planet)) {
            $this->state->PLANET = array_merge($this->state->PLANET, $planet);
        }
    }

    /**
     * The request itself, run inside the locked transaction of show(): slot, vacation, ship and fuel checks for the
     * spy, recycle and missile shortcuts, then sends the fleet; every answer ends the request via sendData().
     */
    private function handleRequest()
    {
        $LNG = $this->state->LNG;
        $UserDeuterium = $this->state->PLANET['deuterium'];

        $planetID = $this->input()->int('planetID');
        $targetMission = $this->input()->int('mission');
        $confirmed = $this->input()->int('confirm') == 1;

        $activeSlots = $this->fleetFunctions()->getCurrentFleets($this->state->USER['id']);
        $maxSlots = $this->fleetFunctions()->getMaxFleetSlots($this->state->USER);

        $this->returnData['slots'] = $activeSlots;

        if (IsVacationMode($this->state->USER)) {
            $this->sendData(620, $LNG['fa_vacation_mode_current']);
        }

        if (empty($planetID)) {
            $this->sendData(601, $LNG['fa_planet_not_exist']);
        }

        if ($maxSlots <= $activeSlots) {
            $this->sendData(612, $LNG['fa_no_more_slots']);
        }

        $fleetArray = $this->fleetForMission($targetMission, $planetID);

        $targetData = $this->getTargetData($targetMission, $planetID, $this->state->USER['id']);

        if (empty($targetData) || ($targetData['destruyed'] != 0 && ($targetMission == Mission::Spy->value || $targetMission == Mission::Missile->value))) {
            $this->sendData(601, $LNG['fa_planet_not_exist']);
        }

        if ($targetMission == Mission::Spy->value || $targetMission == Mission::Missile->value) {
            $this->checkAttackRestrictions($targetMission, $targetData, $confirmed);
        }

        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $Distance = $this->fleetFunctions()->getTargetDistance(
            [$this->state->PLANET['galaxy'], $this->state->PLANET['system'], $this->state->PLANET['planet']],
            [$targetData['galaxy'], $targetData['system'], $targetData['planet']],
        );
        $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $this->state->USER);
        $Duration = $this->fleetFunctions()->getMissionDuration(Mission::Missile->value, $SpeedAllMin, $Distance, $SpeedFactor, $this->state->USER);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $Duration, $Distance, $this->state->USER, $SpeedFactor, (int) $this->state->PLANET['id']);

        $UserDeuterium -= $consumption;

        if ($UserDeuterium < 0) {
            $this->sendData(613, $LNG['fa_not_enough_fuel']);
        }

        if ($consumption > $this->fleetFunctions()->getFleetRoom($fleetArray)) {
            $this->sendData(613, $LNG['fa_no_fleetroom']);
        }

        if (connection_aborted()) {
            throw new FleetAjaxAnswer(null);
        }

        $this->returnData['slots']++;

        $this->dispatchFleet($fleetArray, $targetMission, $planetID, $targetData, $Duration, $consumption);
    }

    /**
     * Builds the fleet the shortcut sends for $targetMission: all allowed spy probes, the recyclers needed for the
     * debris field, or all interplanetary missiles. Fills returnData['ships'] with the ships left on the planet.
     * Answers an error code and ends the request when the module is off, the ships are missing or the mission is unknown.
     *
     * @return array<int, int|float> ship id => count (never empty)
     */
    private function fleetForMission(mixed $targetMission, mixed $planetID): array
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;
        $fleetArray = [];

        switch ($targetMission) {
            case Mission::Spy->value:
            case Mission::PiratebaseSpy->value:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                $ships = min($this->state->USER['spio_anz'], $this->state->PLANET[$e->column(SHIP_PROBE)]);

                if (empty($ships)) {
                    $this->sendData(611, $LNG['fa_no_spios']);
                }

                $fleetArray = [SHIP_PROBE => $ships];
                $this->returnData['ships'][SHIP_PROBE] = $this->state->PLANET[$e->column(SHIP_PROBE)] - $ships;
                break;
            case Mission::Recycling->value:
            case Mission::PiratebaseRecycling->value:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                $fleetArray = $this->recyclersFor($targetMission, $planetID);

                if (empty($fleetArray)) {
                    $this->sendData(611, $LNG['fa_no_recyclers']);
                }
                break;
            case Mission::Missile->value:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSILEATTACK)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                $ships = $this->state->PLANET[$e->column(INTERPLANETARY_MISSILE)];
                if (empty($ships)) {
                    $this->sendData(611, $LNG['ma_no_missiles']);
                }
                $fleetArray = [INTERPLANETARY_MISSILE => $ships];
                break;
            default:
                $this->sendData(610, $LNG['fa_not_enough_probes']);
        }

        $fleetArray = array_filter($fleetArray);

        if (empty($fleetArray)) {
            $this->sendData(610, $LNG['fa_not_enough_probes']);
        }

        return $fleetArray;
    }

    /**
     * The recyclers needed to collect the debris field of the target (planet or pirate base): per recycler type as many as the
     * debris needs, limited to what the planet holds, until the debris is covered. Fills returnData['ships'] with the recyclers left.
     *
     * @return array<int, int|float> ship id => count (empty when the planet has none)
     */
    private function recyclersFor(mixed $targetMission, mixed $planetID): array
    {
        $e = $this->elements();

        if ($targetMission == Mission::Recycling->value) {
            $sql = "SELECT (der_metal + der_crystal) as sum, tf_active FROM %%PLANETS%% WHERE id = :planetID AND universe = :universe;";
        } else {
            $sql = "SELECT (der_metal + der_crystal) as sum, 0 as tf_active FROM %%PIRATE_BASES%% WHERE id = :planetID AND universe = :universe;";
        }
        $field = $this->db->selectSingle($sql, [
            ':planetID' => $planetID,
            ':universe' => $this->universe()->current(),
        ]);
        $totalDebris = (float) ($field['sum'] ?? 0);

        if ($totalDebris <= 0) {
            if (empty($field['tf_active'])) {
                $this->sendData(611, $this->state->LNG['fa_no_debris']);
            }
            // wreck field without debris: one recycler is enough to collect it
            $totalDebris = 1;
        }

        $fleetArray = [];

        foreach ($e->shipTypeIds(SHIP_TYPE_RECYCLER) as $elementID) {
            $a = $e->capacity($elementID);
            $shipsNeed = min(ceil($totalDebris / $a), $this->state->PLANET[$e->column($elementID)]);
            $totalDebris -= ($shipsNeed * $a);

            $fleetArray[$elementID] = $shipsNeed;
            $this->returnData['ships'][$elementID] = $this->state->PLANET[$e->column($elementID)] - $shipsNeed;

            if ($totalDebris <= 0) {
                break;
            }
        }

        return $fleetArray;
    }

    /**
     * Target checks for spy and missile shortcuts: attack permission, vacation, newbie/strong-player protection (a
     * strong player needs the outlaw confirmation, which also marks the sender as outlaw and counts the activation),
     * and attacking oneself. Answers an error/confirmation code and ends the request when a check fails; for missiles
     * it always ends with code 600 (the missile page launches them).
     *
     * @param array<string, mixed> $targetData row from getTargetData()
     */
    private function checkAttackRestrictions(mixed $targetMission, array $targetData, bool $confirmed): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        if ($this->configs->get()->adm_attack == 1 && $targetData['authattack'] > $this->state->USER['authlevel']) {
            $this->sendData(619, $LNG['fa_action_not_allowed']);
        }

        if (IsVacationMode($targetData)) {
            $this->sendData(605, $LNG['fa_vacation_mode']);
        }
        if (
            in_array((int) $targetMission, \App\Game\Engagement\Returning\ReturningPlayers::AGGRESSIVE, true)
            && $this->returningPlayers()->protectedUntil((int) $this->state->USER['universe'], (int) $targetData['id_owner']) > 0
        ) {
            $this->sendData(605, $LNG['fl_player_is_returning']);
        }
        $sql = 'SELECT total_points
            FROM %%STATPOINTS%%
            WHERE id_owner = :userId AND stat_type = :statType';

        try {
            $this->state->USER += $db->selectSingle($sql, [
                ':userId' => $this->state->USER['id'],
                ':statType' => 1,
            ]) ?: ['total_points' => 0];
        } catch (Exception $exception) {
        }

        $IsNoobProtec = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($targetData));

        if ($IsNoobProtec['NoobPlayer']) {
            $this->sendData(603, $LNG['fa_week_player']);
        }

        if ($IsNoobProtec['StrongPlayer']) {
            $config = $this->configs->get();
            if (!$config->noob_protection_outlaw) {
                $this->sendData(604, $LNG['fa_strong_player']);
            }
            if (!$confirmed) {
                $message = $targetMission == Mission::Missile->value
                    ? 'fl_attack_confirm_outlaw'
                    : 'fl_spy_confirm_outlaw';
                $targetPlayerData = $this->playerLookup()->getPlayerById($targetData['id_owner']);
                $this->sendData(
                    602,
                    $LNG->format(
                        $message,
                        $targetPlayerData['username'],
                    ),
                );
            }

            if ($targetMission == Mission::Missile->value) {
                // Function is used for checks only, the actual fleet will be send in ShowFleetMissilePage
                $this->sendData(600, '');
            }

            // written by dispatchFleet() once every other check passed (a refused launch must not mark the sender)
            $this->outlawPending = true;
        }

        if ($targetMission == Mission::Missile->value) {
            // Function is used for checks only, the actual fleet will be send in ShowFleetMissilePage
            $this->sendData(600, '');
        }

        if ($this->state->USER['id'] == $targetData['id_owner']) {
            $this->sendData(618, $LNG['fa_not_spy_yourself']);
        }
    }

    /**
     * Deducts the fuel, sends the fleet (zero cargo, flight time = stay time for the outward leg) and answers with
     * code 600 and the "sending ..." message.
     *
     * @param array<int, int|float> $fleetArray ship id => count
     * @param array<string, mixed> $targetData row from getTargetData()
     */
    private function dispatchFleet(array $fleetArray, mixed $targetMission, mixed $planetID, array $targetData, mixed $Duration, mixed $consumption): void
    {
        $LNG = $this->state->LNG;
        $fleetResource = [
            901 => 0,
            902 => 0,
            903 => 0,
        ];

        $fleetStartTime = $Duration + $this->state->runtime->timestamp;
        $fleetStayTime = $fleetStartTime;
        $fleetEndTime = $fleetStayTime + $Duration;

        // last check: a refused launch must not use up the daily quota (audit F-14)
        $capped = $this->fleetCaps->consume((int) $this->state->USER['id'], $targetMission);
        if ($capped !== null) {
            $this->sendData(621, $LNG->format($capped['key'], $capped['limit'], $capped['hours'], $capped['minutes']));
        }

        if ($this->outlawPending) {
            $this->db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                ':outlawTime'   => $this->state->runtime->timestamp,
                ':userId'       => $this->state->USER['id'],
            ]);
            (new AdvancedStatsHandler($this->db, $this->configs, $this->state))->increment($this->state->USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
        }

        $shipID = array_keys($fleetArray);
        $this->state->PLANET['deuterium'] -= $consumption;

        $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $targetMission,
            $this->state->USER['id'],
            $this->state->PLANET['id'],
            $this->state->PLANET['galaxy'],
            $this->state->PLANET['system'],
            $this->state->PLANET['planet'],
            $this->state->PLANET['planet_type'],
            $targetData['id_owner'],
            $planetID,
            $targetData['galaxy'],
            $targetData['system'],
            $targetData['planet'],
            $targetData['planet_type'],
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            0,
            0,
            0,
            $consumption,
        );
        // spying (or any aggressive shortcut) ends the returning-player protection of the sender
        $this->returningPlayers()->endProtection((int) $this->state->USER['universe'], (int) $this->state->USER['id']);

        $this->sendData(
            600,
            sprintf(
                '%s %d %s %s %d:%d:%d ...',
                $LNG['fa_sending'],
                (int) floor(array_sum($fleetArray)),
                $LNG['tech'][$shipID[0]],
                $LNG['gl_to'],
                (int) $targetData['galaxy'],
                (int) $targetData['system'],
                (int) $targetData['planet'],
            ),
        );
    }

    /**
     * Loads the target planet with its owner and statistics (or pirate base) for $mission, or false when it does not exist.
     *
     * @return array<mixed>
     */
    private function getTargetData(int $mission, int $planetID, int $userId): array|bool
    {
        $db = $this->db;
        if ($mission == Mission::PiratebaseSpy->value || $mission == Mission::PiratebaseRecycling->value) {
            // derelict field (recycling) or pirate mothership (spying) of the map content features
            $npc = (new NpcTargets($db, $this->configs, $this->state))->byId($this->universe()->current(), $planetID);
            if ($npc !== null) {
                $fits = $npc['npc_kind'] === NpcTargets::KIND_DERELICT ? $mission == Mission::PiratebaseRecycling->value : $mission == Mission::PiratebaseSpy->value;

                return $fits ? ['id_owner' => 0, 'galaxy' => $npc['galaxy'], 'system' => $npc['system'], 'planet' => $npc['planet'], 'planet_type' => $npc['planet_type']] : [];
            }
            $config = $this->configs->get();
            $planet = $config->max_planets + 3;
            $sql = "SELECT `id_owner`, `galaxy`, `system`, $planet as planet, `planet_type`
                FROM %%PIRATE_BASES%%
                WHERE `id` = :planetID AND `id_owner` = :userId AND `universe` = :universe;";

            return $db->selectSingle($sql, [
                ':planetID' => $planetID,
                ':userId'   => $userId,
                ':universe' => $this->universe()->current(),
            ]) ?: [];
        } else {
            $sql = "SELECT p.`id_owner`, p.`galaxy`, p.`system`, p.`planet`, p.`planet_type`, p.`destruyed`, u.`id`, 
                    stat.`total_points`, u.`onlinetime`, u.`urlaubs_modus`, u.`banaday`, u.`authattack`, u.`rebuild_protection`, 
                    u.`rebuild_protection_attackers`, u.`outlaw_time`
                FROM %%PLANETS%% p
                INNER JOIN %%USERS%% u ON p.`id_owner` = u.`id`
                LEFT JOIN %%STATPOINTS%% as stat ON stat.`id_owner` = u.`id` AND stat.`stat_type` = '1'
                WHERE p.`id` = :planetID AND p.`universe` = :universe;";
        }

        return $db->selectSingle($sql, [
            ':planetID' => $planetID,
            ':universe' => $this->universe()->current(),
        ]);
    }
}
