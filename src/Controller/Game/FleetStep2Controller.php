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
use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\Core\FleetFunctions;
use App\Game\Core\MarketManager;
use App\Game\Core\Session;

/** Fleet dispatch, step 2: mission, cargo and trade options (modes: show, checkResources). */
#[\App\Controller\RequireModule('MODULE_FLEET_TABLE')]
class FleetStep2Controller extends AbstractGameController
{
    use FleetTargetProtection;

    /**
     * Fleet dispatch step 2 (mission choice). Request: token (fleet stored by step 1; unknown tokens go back to the fleet page), galaxy/system/planet/type
     * (target), speed (percent, validated against the allowed speeds), target_mission (-1 = pick), group (public id of an alliance combat group the
     * player is invited to). Loads the target (planet, moon, or the player's own pirate base; empty debris fields are refused), asks FleetFunctions
     * which missions are possible (none: message), chooses the mission when none was given (the best of the player's mission priorities, or
     * transport for own/befriended targets, spy for a lone probe, otherwise attack; attack on a combat group becomes ACS), computes distance, duration
     * and deuterium consumption (refused when the planet lacks the deuterium), and stores speed, distance and target in the session fleet entry.
     * Builds the confirmation questions (outlaw, diplomatic partner, buddy, colony warning), the stay times and the trade market rates for trade
     * missions, refuses missing ships and expeditions with probes only, and renders the form.
     */
    #[GameRoute('game', 'game/fleet/step2', 'fleetStep2', 'show', ['GET', 'POST'])]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $this->addScript('flotten.js');

        [$targetGalaxy, $targetSystem, $targetPlanet, $targetType, $targetMission, $fleetSpeed, $fleetGroupPublicId, $token] = $this->stepInput();

        // post/redirect/get: the posted choice becomes a GET url, so reload and browser back work and nothing is resubmitted
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST') {
            return $this->redirectToRoute('game_fleet_step2_show', [
                'token' => $token, 'galaxy' => $targetGalaxy, 'system' => $targetSystem, 'planet' => $targetPlanet, 'type' => $targetType,
                'target_mission' => $targetMission, 'speed' => $fleetSpeed, 'group' => $fleetGroupPublicId,
            ]);
        }

        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token])) {
            return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl());
        }

        $fleetArray = $fleet[$token]['fleet'];

        [$targetPlanetData, $fleetGroup] = $this->loadTarget($targetGalaxy, $targetSystem, $targetPlanet, $targetType, $fleetGroupPublicId);

        $MissionOutput = $this->possibleMissions($targetPlanetData, $fleetArray, $fleetGroup, $targetGalaxy, $targetSystem, $targetPlanet, $targetType);
        if ($MissionOutput instanceof Response) {
            return $MissionOutput;
        }

        // validate the speed first: the flight duration divides by it
        if (!$this->fleetFunctions()->checkUserSpeed((int) $fleetSpeed)) {
            return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl(0));
        }

        [$MaxFleetSpeed, $distance, $duration, $consumption] = $this->flightPlan($fleetArray, $targetGalaxy, $targetSystem, $targetPlanet, $fleetSpeed);

        if ($consumption > $PLANET['deuterium']) {
            return $this->backToFleetTable($LNG['fl_not_enough_deuterium'], $fleetArray, ['galaxy' => $targetGalaxy, 'system' => $targetSystem, 'planet' => $targetPlanet, 'planettype' => $targetType]);
        }

        $fleet = $this->storeFlight($session, $fleet, $token, [
            'speed' => $MaxFleetSpeed,
            'distance' => $distance,
            'targetGalaxy' => $targetGalaxy,
            'targetSystem' => $targetSystem,
            'targetPlanet' => $targetPlanet,
            'targetType' => $targetType,
            'fleetGroup' => $fleetGroup,
            'fleetSpeed' => $fleetSpeed,
            'ownPlanet' => $PLANET['id'],
        ]);

        $targetMission = $this->resolveMission($targetMission, $MissionOutput['MissionSelector'], $targetPlanetData, $fleetArray, $fleetGroup);

        $questionList = $this->confirmationQuestions($targetPlanetData, $targetGalaxy, $targetSystem);
        $fleetData  = [
            'fleetroom'     => floatToString($fleet[$token]['fleetRoom']),
            'consumption'   => floatToString($consumption),
        ];

        $tradeMarket = empty($MissionOutput['Exchange']) ? null : $this->tradeMarketData($fleetArray);

        if ($unavailable = $this->checkShipsAvailable($fleetArray, $targetMission)) {
            return $unavailable;
        }

        $variables = $this->formVariables($questionList, $fleetData, $consumption, $targetMission, $MissionOutput, $tradeMarket, $token, $duration, $fleet[$token]['predefinedResources']);

        return $this->render('game/page.fleetStep2.default.html.twig', [
            'galaxy' => $targetGalaxy,
            'system' => $targetSystem,
            'planet' => $targetPlanet,
            'type' => $targetType,
            'targetName' => (string) ($targetPlanetData['name'] ?? ''),
            'targetOwner' => (string) ($targetPlanetData['owner_name'] ?? ''),
            'protection' => $this->protectionNotice((int) ($targetPlanetData['id_owner'] ?? 0)),
            'FleetList' => $fleetArray,
        ] + $variables);
    }

    /**
     * Request fields of the step: galaxy, system, planet, type, target_mission (-1 = pick), speed, group (public combat group id), token.
     *
     * @return array{int, int, int, int, int, int, string, string}
     */
    private function stepInput(): array
    {
        $input = $this->input();

        return [
            $input->int('galaxy'), $input->int('system'), $input->int('planet'), $input->int('type'),
            $input->int('target_mission', -1), $input->int('speed'), $input->string('group'), $input->string('token'),
        ];
    }

    /**
     * The mission to preselect: -1 = the best of the player's mission priorities, 0 = guessed from target and fleet
     * (guessMission()); an attack on an alliance combat group becomes ACS. Same order as before the split.
     *
     * @param list<int>|array<int, mixed> $missionSelector possible missions
     * @param array<string, mixed> $targetPlanetData
     * @param array<int, int|string> $fleetArray
     */
    private function resolveMission(int $targetMission, array $missionSelector, array $targetPlanetData, array $fleetArray, mixed $fleetGroup): int
    {
        if ($targetMission == -1) {
            $targetMission = $this->preferredMission($missionSelector, $targetMission);
        }
        if ($targetMission == 0) {
            $targetMission = $this->guessMission($missionSelector, $targetPlanetData, $fleetArray);
        }
        if (!empty($fleetGroup) && $targetMission == Mission::Attack->value) {
            $targetMission = Mission::Acs->value;
        }

        return (int) $targetMission;
    }

    /**
     * Merges the flight data (speed, distance, target, combat group, origin) into the session fleet entry of the token and saves the session.
     *
     * @param array<string, array<string, mixed>> $fleet session fleets by token
     * @param array<string, mixed> $flight
     * @return array<string, array<string, mixed>> the updated fleets
     */
    private function storeFlight(\App\Game\Core\Session $session, array $fleet, string $token, array $flight): array
    {
        $fleet[$token] = array_merge($fleet[$token], $flight);
        $session->fleet = $fleet;
        $session->save();

        return $fleet;
    }

    /**
     * Asks FleetFunctions which missions are possible against the target. Returns the message page when the target
     * is an empty debris field or when no mission is possible.
     *
     * @param array<string, mixed> $targetPlanetData target row from loadTarget() (empty = nothing there)
     * @param array<int, int|float> $fleetArray ship id => count
     * @return array<string, mixed>|Response MissionSelector, StayBlock, Exchange
     */
    private function possibleMissions(array $targetPlanetData, array $fleetArray, int $fleetGroup, mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType): array|Response
    {
        $LNG = $this->state->LNG;
        if ($targetType == 2 && !empty($targetPlanetData) && ($targetPlanetData['der_metal'] + $targetPlanetData['der_crystal']) == 0
            && $targetPlanetData['tf_active'] == 0) {
            return $this->backToFleetTable($LNG['fl_error_empty_derbis'], $fleetArray, ['galaxy' => (int) $targetGalaxy, 'system' => (int) $targetSystem, 'planet' => (int) $targetPlanet, 'planettype' => (int) $targetType]);
        }

        $MissionOutput = $this->fleetFunctions()->getFleetMissions($this->state->USER, [
            'galaxy' => $targetGalaxy,
            'system' => $targetSystem,
            'planet' => $targetPlanet,
            'planettype' => $targetType,
            'IsAKS' => $fleetGroup,
            'Ship' => $fleetArray,
        ], $targetPlanetData);

        if (empty($MissionOutput['MissionSelector'])) {
            return $this->backToFleetTable($LNG['fl_empty_target'], $fleetArray, ['galaxy' => (int) $targetGalaxy, 'system' => (int) $targetSystem, 'planet' => (int) $targetPlanet, 'planettype' => (int) $targetType]);
        }

        return $MissionOutput;
    }

    /**
     * Flight data for the chosen target: the slowest ship's speed, the distance from the current planet, the flight duration
     * at the chosen speed percentage and the deuterium consumption.
     *
     * @param array<int, int|float> $fleetArray ship id => count
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed} max fleet speed, distance, duration, consumption
     */
    private function flightPlan(array $fleetArray, mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $fleetSpeed): array
    {
        $PLANET = $this->state->PLANET;
        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($this->state->USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$targetGalaxy, $targetSystem, $targetPlanet],
        );
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        return [$MaxFleetSpeed, $distance, $duration, $consumption];
    }

    /**
     * Template variables of the mission form: questions, fleet data, the chosen mission and the selectors from
     * FleetFunctions::getFleetMissions(), the trade market. The caller adds the target (coordinates, name, owner) and the ships.
     *
     * @param array<int, string> $questionList mission => confirmation question
     * @param array<string, mixed> $fleetData fleetroom and consumption
     * @param array<string, mixed> $MissionOutput result of getFleetMissions()
     * @param array<string, mixed>|null $tradeMarket from tradeMarketData()
     * @return array<string, mixed>
     */
    private function formVariables(array $questionList, array $fleetData, mixed $consumption, mixed $targetMission, array $MissionOutput, ?array $tradeMarket, mixed $token, mixed $duration, mixed $predefinedResources): array
    {
        $LNG = $this->state->LNG;

        return [
            'questionList'      => $questionList,
            'fleetdata'         => $fleetData,
            'consumption'       => floatToString($consumption),
            'mission'           => $targetMission,
            'MissionSelector'   => $MissionOutput['MissionSelector'],
            'StaySelector'      => $MissionOutput['StayBlock'],
            'Exchange'          => $MissionOutput['Exchange'],
            'tradeMarket'       => $tradeMarket,
            'fl_continue'       => $LNG['fl_continue'],
            'token'             => $token,
            'duration'          => $duration,
            'predefinedRes'     => $predefinedResources,
        ];
    }

    /**
     * The message page with $message and a back link to the fleet page (keeping the chosen ships and the target).
     *
     * @param array<int, int|float> $fleetArray ship id => count
     * @param array<string, int> $target galaxy/system/planet/planettype/target_mission query of the link
     */
    private function backToFleetTable(mixed $message, array $fleetArray = [], array $target = []): Response
    {
        // the ships ride along in the URL hash (fleetTable.js fills the amounts from it), so a refused target does not lose the selection
        $hash = [];
        foreach ($fleetArray as $shipId => $count) {
            $hash[] = 'ship_input[' . (int) $shipId . ']=' . (int) $count;
        }

        return $this->messageResponse($message, [[
            'label' => $this->state->LNG['sys_back'],
            'url'   => $this->router->generate('game_fleet_table_show', $target) . ($hash ? '#' . implode('&', $hash) : ''),
        ]]);
    }

    /**
     * Loads the target of the dispatch: the player's own pirate base (when $targetPlanet is the pirate slot) or the
     * planet/moon at the coordinates (debris fields hang off the planet row). For normal targets it also resolves the
     * public id of an alliance combat group the player is invited to.
     *
     * @return array{0: array<string, mixed>, 1: int} target row (empty = nothing there) and the internal combat group id (0 = none)
     */
    private function loadTarget(mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType, mixed $fleetGroupPublicId): array
    {
        $db = $this->db;
        $USER = $this->state->USER;
        $config = $this->configs->get();
        $fleetGroup = 0;

        if ($targetPlanet == $config->max_planets + 3) {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, 0 as `tf_active`, '' as `name`, '' as `owner_name`
                FROM %%PIRATE_BASES%% 
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND `system` = :targetSystem AND `id_owner` = :userId;";
            $targetPlanetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':userId' => $USER['id'],
            ]);
        } else {
            if (!empty($fleetGroupPublicId)) {
                $sql = "SELECT acs.`id`
                    FROM %%USERS_TO_ACS%% usersToAcs
                    INNER JOIN %%AKS%% acs ON usersToAcs.acsID = acs.`id`
                    WHERE usersToAcs.`userID` = :userID AND acs.`public_id` = :publicId;";
                $fleetGroup = (int) $db->selectSingle($sql, [
                    ':userID' => $USER['id'],
                    ':publicId' => $fleetGroupPublicId,
                ], 'id');
            }

            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, `tf_active`, `name`,
                    (SELECT `username` FROM %%USERS%% WHERE %%USERS%%.`id` = %%PLANETS%%.`id_owner`) as `owner_name`
                FROM %%PLANETS%%
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND
                `system` = :targetSystem AND `planet` = :targetPlanet AND `planet_type` = :planetType;";
            // A moon target needs the moon row; debris fields hang off the planet (type 1)
            $targetPlanetData = $db->selectSingle($sql, [
                ':planetType' => $targetType == 3 ? 3 : 1,
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':targetPlanet' => $targetPlanet,
            ]);
        }
        if (empty($targetPlanetData)) {
            $targetPlanetData = [];
        }

        return [$targetPlanetData, $fleetGroup];
    }

    /**
     * Picks the mission the player prefers (USERS_SETTINGS prioMission*, lower score wins) among the possible
     * missions. Keeps $current when none of the possible missions has a priority.
     *
     * @param list<int> $missionSelector possible missions
     */
    private function preferredMission(array $missionSelector, mixed $current): mixed
    {
        $sql = "SELECT `prioMission1` as '1', `prioMission2` as '2', `prioMission3` as '3', `prioMission4` as '4', `prioMission5` as '5',
                `prioMission6` as '6', `prioMission7` as '7', `prioMission8` as '8', `prioMission9` as '9', `prioMission17` as '17'
            FROM %%USERS_SETTINGS%%
            WHERE `user_id` = :userID ;";
        $missionprios = $this->db->selectSingle($sql, [
            ':userID'   => $this->state->USER['id'],
        ]);
        $targetMission = $current;
        $bestmission = 999;
        foreach ($missionSelector as $mission) {
            if (array_key_exists($mission, $missionprios)) {
                $score = $missionprios[$mission];
                if ($score < $bestmission) {
                    $targetMission = $mission;
                    $bestmission = $score;
                }
            }
        }

        return $targetMission;
    }

    /**
     * Allied-alliance row (diplomacy level 1, 2, 4 or 6, accepted) between the player's alliance and the alliance of
     * $targetPlayerId, or a falsy value when there is none.
     *
     * @return array<string, mixed>|false|null
     */
    private function alliedAllianceOf(int $targetPlayerId): array|false|null
    {
        $sql = "SELECT a.`id`, a.`ally_tag`, a.`ally_name`, d.`level`, d.`owner_2`
            FROM %%USERS%% u
            JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
            JOIN %%DIPLO%% d ON ((d.`owner_1` = u.`ally_id` AND d.`owner_2` = :ally_id)
                OR (d.`owner_2` = u.`ally_id` AND d.`owner_1` = :ally_id))
                AND d.`accept` = 1
            WHERE u.`universe` = :universe AND u.`id` = :targetPlayerId AND d.`level` IN (1,2,4,6);";

        return $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':targetPlayerId' => $targetPlayerId,
            ':ally_id' => $this->state->USER['ally_id'],
        ]);
    }

    /**
     * Row with the number (`anz`) of buddy relations between the player and $targetPlayerId.
     *
     * @return array<string, mixed>
     */
    private function buddyCount(int $targetPlayerId): array
    {
        $sql = "SELECT count(*) as anz FROM %%BUDDY%%
            WHERE (`sender` = :id AND `owner` = :targetPlayerId)
               OR (`sender` = :targetPlayerId AND `owner` = :id);";

        return $this->db->selectSingle($sql, [
            ':id' => $this->state->USER['id'],
            ':targetPlayerId' => $targetPlayerId,
        ]);
    }

    /**
     * Chooses the mission when the form asked for "auto" (0): the only possible one; transport on an own planet; spy for
     * a lone probe; transport for targets of allied alliances or buddies; otherwise attack.
     *
     * @param list<int> $missionSelector possible missions
     * @param array<string, mixed> $targetPlanetData target row, empty when nothing is there
     * @param array<int, int> $fleetArray ship id => count
     */
    private function guessMission(array $missionSelector, array $targetPlanetData, array $fleetArray): int
    {
        if (count($missionSelector) == 1) {
            return $missionSelector[0];
        }
        if (!empty($targetPlanetData) && $targetPlanetData['id_owner'] == $this->state->USER['id']) {
            return Mission::Transport->value;
        }
        if (count($fleetArray) == 1 && isset($fleetArray[SHIP_PROBE])) {
            return Mission::Spy->value;
        }
        if (empty($targetPlanetData)) {
            return Mission::Attack->value;
        }

        $targetAllianceData = $this->alliedAllianceOf($targetPlanetData['id_owner']);
        $buddy = $this->buddyCount($targetPlanetData['id_owner']);

        return ($targetAllianceData || $buddy['anz'] > 0) ? Mission::Transport->value : Mission::Attack->value;
    }

    /**
     * Confirmation questions the form asks before certain missions: attacks (and ACS / destruction) on outlaws, allied
     * alliances or buddies; hold and spy on outlaws; colonising a system where the player already has two colonies.
     *
     * @param array<string, mixed> $targetPlanetData target row, empty when nothing is there
     * @return array<int, string> mission => question text
     */
    private function confirmationQuestions(array $targetPlanetData, mixed $targetGalaxy, mixed $targetSystem): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $questionList = [];
        $question = '';

        if (!empty($targetPlanetData) && $targetPlanetData['id_owner'] != $USER['id']) {
            $targetAllianceData = $this->alliedAllianceOf($targetPlanetData['id_owner']);
            $buddy = $this->buddyCount($targetPlanetData['id_owner']);
            $config = $this->configs->get();

            if ($config->noob_protection_outlaw) {

                $targetPlayerData = $this->playerLookup()->getPlayerById($targetPlanetData['id_owner']);
                $IsNoobProtec = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetPlayerData));

                if ($IsNoobProtec['StrongPlayer']) {
                    $question = $LNG->format(
                        'fl_attack_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionHold = $LNG->format(
                        'fl_hold_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionSpy = $LNG->format(
                        'fl_spy_confirm_outlaw',
                        $targetPlayerData['username'],
                    );
                    $questionList[Mission::Hold->value] = $questionHold;
                    $questionList[Mission::Spy->value] = $questionSpy;
                }
            }

            // diplomacy / buddy questions are independent of the outlaw rule (the outlaw question wins when it applies)
            if ($question === '' && $targetAllianceData) {
                $question = $LNG->format(
                    'fl_attack_confirm_diplo',
                    (($targetAllianceData['owner_2'] == $targetAllianceData['id'] && $targetAllianceData['level'] == 1) ? $LNG['al_diplo_level'][0] : $LNG['al_diplo_level'][$targetAllianceData['level']]),
                    '[' . $targetAllianceData['ally_tag'] . '] ' . $targetAllianceData['ally_name'],
                );
            } elseif ($question === '' && $buddy['anz'] > 0) {
                $question = $LNG['fl_attack_confirm_buddy'];
            }

            $questionList[Mission::Attack->value] = $question;
            $questionList[Mission::Acs->value] = $question;
            $questionList[Mission::Destruction->value] = $question;

        }

        if (empty($targetPlanetData) && $USER['colonyWarn'] == 1) {
            $sql = "SELECT count(*) as anz 
                FROM %%PLANETS%% 
                WHERE `id_owner` = :id 
                  AND `planet_type` = '1' 
                  AND `galaxy` = :galaxy 
                  AND `system` = :system
                  AND `destruyed` = 0;";

            $coloniesInSys = $this->db->selectSingle($sql, [
                ':id' => $USER['id'],
                ':galaxy' => $targetGalaxy,
                ':system' => $targetSystem,
            ]);
            if ($coloniesInSys['anz'] >= 2) {
                $questionList[Mission::Colonisation->value] = $LNG['fl_colonize_confirm'];
            }
        }

        return $questionList;
    }

    /**
     * Trade market data for trade missions: the deuterium-relative rates of the three resources, the deuterium value
     * of the fleet's ships and the reference decimals.
     *
     * @param array<int, int> $fleetArray ship id => count
     * @return array<string, mixed>
     */
    private function tradeMarketData(array $fleetArray): array
    {
        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $deutRelative = $pMarket->getDeutRelativeRatios();

        return [
            'rates' => [
                'metal' => sprintf('%.12F', $deutRelative['metal']),
                'crystal' => sprintf('%.12F', $deutRelative['crystal']),
                'deuterium' => sprintf('%.12F', $deutRelative['deuterium']),
            ],
            'fleetDeut' => $pMarket->getFleetValue($fleetArray),
            'decimals' => MarketManager::REFERENCE_DECIMALS,
        ];
    }

    /**
     * Last sanity checks before the form is rendered: the message page when the planet no longer has
     * the ships, or when an expedition would be sent with probes only; null when all is fine.
     *
     * @param array<int, int> $fleetArray ship id => count
     */
    private function checkShipsAvailable(array $fleetArray, mixed $targetMission): ?Response
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;
        $containsOnlyProbes = true;
        foreach ($fleetArray as $Ship => $Count) {
            if ($Count > $this->state->PLANET[$e->column($Ship)]) {
                return $this->backToFleetTable($LNG['fl_not_all_ship_avalible']);
            }
            if ($Ship != SHIP_PROBE) {
                $containsOnlyProbes = false;
            }
        }
        if ($targetMission == Mission::Expedition->value && $containsOnlyProbes) {
            return $this->backToFleetTable($LNG['fl_no_probe_expos']);
        }

        return null;
    }

    /**
     * XHR validation of the cargo of step 2 (request: token, mission, metal, crystal, deuterium, and for trade missions resEx = wanted resource type 1..3,
     * exchange = wanted amount, markettype 0 = resources / 1 = ships). Answers JSON: `OK` or the error text for missing resources on the planet, deuterium
     * for the flight on top of the cargo, too little cargo space, or invalid trade setups (nothing or several resources loaded, wanting the loaded
     * resource). An unknown token gives {message, error: true}.
     */
    #[GameRoute('game', 'game/fleet/step2/check-resources', 'fleetStep2', 'checkResources')]
    public function checkResources(): Response
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $targetMission = $this->input()->int('mission', Mission::Transport->value);
        $TransportMetal = max(0, round($this->input()->float('metal')));
        $TransportCrystal = max(0, round($this->input()->float('crystal')));
        $TransportDeuterium = max(0, round($this->input()->float('deuterium')));
        $WantedResourceType = $this->input()->int('resEx');
        $WantedResourceAmount = max(0, round($this->input()->float('exchange')));
        $markettype = $this->input()->int('markettype'); //0 = resources, 1 = ships

        if ($PLANET['metal'] < $TransportMetal) {
            return $this->jsonResponse($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_METAL]));
        }
        if ($PLANET['crystal'] < $TransportCrystal) {
            return $this->jsonResponse($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_CRYSTAL]));
        }

        $token = $this->input()->string('token');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token]) || !isset($fleet[$token]['fleet']) || !isset($fleet[$token]['distance'])
            || !isset($fleet[$token]['fleetSpeed']) || !isset($fleet[$token]['fleetRoom'])) {
            return $this->jsonResponse(['message' => $LNG['fl_error'], 'error' => true]);
        }
        $formData = $fleet[$token];
        $fleetArray = $formData['fleet'];
        $distance = $formData['distance'];
        $fleetSpeed = $formData['fleetSpeed'];
        $fleetStorage = $formData['fleetRoom'];

        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        if ($PLANET['deuterium'] < $TransportDeuterium + $consumption) {
            return $this->jsonResponse($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_DEUT]));
        }

        if ($fleetStorage < $TransportMetal + $TransportCrystal + $TransportDeuterium + $consumption || $fleetStorage < $WantedResourceAmount) {
            return $this->jsonResponse($LNG['fl_not_enough_space']);
        }

        if ($targetMission == Mission::Trade->value) {
            $anz = 0;
            $anz += $TransportMetal > 0 ? 1 : 0;
            $anz += $TransportCrystal > 0 ? 1 : 0;
            $anz += $TransportDeuterium > 0 ? 1 : 0;

            if ($anz == 0 && $markettype == 0) {
                return $this->jsonResponse($LNG['fl_trade_no_resource_loaded']);
            } elseif ($anz > 1) {
                return $this->jsonResponse($LNG['fl_trade_to_many_resources_loaded']);
            }

            if ($TransportMetal > 0 && $WantedResourceType == 1) {
                return $this->jsonResponse($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_METAL], $LNG['tech'][RESOURCE_METAL]));
            } elseif ($TransportCrystal > 0 && $WantedResourceType == 2) {
                return $this->jsonResponse($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_CRYSTAL], $LNG['tech'][RESOURCE_CRYSTAL]));
            } elseif ($TransportDeuterium > 0 && $WantedResourceType == 3) {
                return $this->jsonResponse($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_DEUT], $LNG['tech'][RESOURCE_DEUT]));
            }
        }

        return $this->jsonResponse('OK');
    }
}
