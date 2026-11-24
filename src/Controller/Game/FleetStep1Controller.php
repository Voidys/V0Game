<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
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
use App\Routing\GameRoute;
use App\Fleet\FleetTemplateStore;
use App\Fleet\ShipPreset;
use App\Game\Core\FleetFunctions;
use App\Game\Engagement\MapContent\NpcTargets;
use App\Game\Core\Session;
use App\Game\Core\Universe;

/** Fleet dispatch, step 1: target coordinates, speed and shortcuts (modes: show, saveShortcuts, checkTarget). */
#[\App\Controller\RequireModule('MODULE_FLEET_TABLE')]
class FleetStep1Controller extends AbstractGameController
{
    use FleetTargetProtection;

    /** Prepared fleets (tokens) kept per session; each open fleet page uses one. */
    private const MAX_PREPARED_FLEETS = 20;

    /** Shortcuts (saved coordinates) per player. */
    private const MAX_SHORTCUTS = 50;

    /**
     * Fleet dispatch step 1. Request: ship<id> = counts of the ships to send (solar satellites ignored; none selected goes back to the fleet page),
     * ships (compact preset `202:50,210:2`, used when no ship<id> is given), galaxy/system/planet/type (preset target, default the player's next other planet, else the current planet), target_mission, met_storage/krist_storage/deut_storage (preset cargo).
     * Stores the chosen fleet in the game session under a new random token (used by steps 2 and 3), refuses ships the planet does not have, and renders the
     * target form with speed choices, coordinate shortcuts, own planets, usable alliance combat groups, the pirate base and the universe geometry for
     * the flight time preview.
     */
    #[GameRoute('game', 'game/fleet/step1', 'fleetStep1', 'show', ['GET', 'POST'])]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        [$targetGalaxy, $targetSystem, $targetPlanet, $targetType] = $this->presetTarget();

        $mission = $this->input()->int('target_mission', -1);

        [$Fleet, $FleetRoom] = $this->selectedShips();

        if (empty($Fleet)) {
            // code 1: no ship chosen (the fleet page says so inline)
            return $this->redirectResponse($this->fleetFunctions()->fleetPageUrl(1));
        }

        $FleetData = [
            'fleetroom'         => floatToString($FleetRoom),
            'gamespeed'         => $this->fleetFunctions()->getGameSpeedFactor(),
            'fleetspeedfactor'  => 1,
            'planet'            => [
                'galaxy' => $PLANET['galaxy'],
                'system' => $PLANET['system'],
                'planet' => $PLANET['planet'],
                'planet_type' => $PLANET['planet_type'],
            ],
            'maxspeed'          => $this->fleetFunctions()->getFleetMaxSpeed($Fleet, $USER),
            'ships'             => $this->fleetFunctions()->getFleetShipInfo($Fleet, $USER),
            'fleetMinDuration'  => MIN_FLEET_TIME,
        ];

        // post/redirect/get: the posted selection becomes a GET url, so reload and browser back work and nothing is resubmitted
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST') {
            return $this->redirectToRoute('game_fleet_step1_show', $this->selectionQuery($Fleet, [
                'galaxy' => $targetGalaxy, 'system' => $targetSystem, 'planet' => $targetPlanet, 'type' => $targetType,
                'target_mission' => $mission, 'direct' => $this->input()->int('direct'),
            ]));
        }
        // validate before anything is stored: a refused fleet must not occupy a token
        if ($unavailable = $this->checkShipsAvailable($Fleet)) {
            return $unavailable;
        }
        $token = $this->storeFleet($Fleet, $FleetRoom);

        // target and mission already chosen (galaxy / report / message link): straight on to the mission step, which validates everything again
        if ($mission > 0 && $this->input()->int('direct') === 1) {
            return $this->redirectToRoute('game_fleet_step2_show', [
                'token' => $token, 'galaxy' => $targetGalaxy, 'system' => $targetSystem, 'planet' => $targetPlanet, 'type' => $targetType,
                'target_mission' => $mission, 'speed' => 10, 'group' => 0,
            ]);
        }

        $shortcutList = $this->GetUserShotcut();
        $colonyList = $this->GetColonyList();
        $ACSList = $this->GetAvalibleACS();

        $this->addScript('flotten.js');

        $pirateBase = $this->pirateBase((int) $USER['id']);

        return $this->render('game/page.fleetStep1.default.html.twig', [
            'token'         => $token,
            'mission'       => $mission,
            'FleetList'     => $Fleet,
            'shortcutList'  => $shortcutList,
            'colonyList'    => $colonyList,
            'pirateBase'    => $pirateBase,
            'ACSList'       => $ACSList,
            'galaxy'        => $targetGalaxy,
            'system'        => $targetSystem,
            'planet'        => $targetPlanet,
            'type'          => $targetType,
            'honorboost'    => $this->honorPoints()->calculate_honorpoints_boost($USER['id']) * $this->relicFuelFactor(),
            'speedSelect'   => FleetFunctions::$allowedSpeed,
            'typeSelect'    => [1 => $LNG['type_planet_1'], 2 => $LNG['type_planet_2'], 3 => $LNG['type_planet_3']],
            'fleetdata'     => $FleetData,
            'config'        => $this->universeGeometry(),
            'templates'     => (new FleetTemplateStore($this->db))->all((int) $USER['id']),
            'maxTemplates'  => FleetTemplateStore::MAX_PER_USER,
            'currentShips'  => ShipPreset::format($Fleet),
        ]);
    }

    /**
     * GET parameters of the step-1 url for a posted selection: the target fields, the preset cargo and ship<id> = count per chosen ship.
     *
     * @param array<int, int|string> $fleet ship id => count
     * @param array<string, int> $target galaxy, system, planet, type, target_mission, direct
     * @return array<string, int|string>
     */
    private function selectionQuery(array $fleet, array $target): array
    {
        $query = $target + [
            'met_storage' => $this->input()->string('met_storage'),
            'krist_storage' => $this->input()->string('krist_storage'),
            'deut_storage' => $this->input()->string('deut_storage'),
        ];
        foreach ($fleet as $shipId => $count) {
            $query['ship' . $shipId] = $count;
        }

        return $query;
    }

    /** Galaxy and system of the player's pirate base (module MODULE_PIRATE_BASES), null without the module or a base. */
    private function pirateBase(int $userId): ?array
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
            return null;
        }
        $base = $this->db->selectSingle('SELECT `galaxy`, `system` FROM %%PIRATE_BASES%% WHERE `id_owner` = :owner', [
            ':owner' => $userId,
        ]);

        return is_array($base) ? $base : null;
    }

    /**
     * The ships chosen on the fleet page (request ship<id> = counts; negative values count as 0, solar satellites are skipped)
     * and the total cargo room of that fleet.
     *
     * @return array{0: array<int, int|float>, 1: int|float} ship id => count, cargo capacity
     */
    private function selectedShips(): array
    {
        $e = $this->elements();
        $Fleet = [];
        $FleetRoom = 0;
        foreach ($e->ids('fleet') as $ShipID) {
            $amount = max(0, round($this->input()->float('ship' . $ShipID)));

            if ($amount < 1 || $ShipID == 212) {
                continue;
            }

            $Fleet[$ShipID] = $amount;
            $FleetRoom += $e->capacity($ShipID) * $amount;
        }

        // no ship<id> chosen: the preset link parameter ships=202:50,210:2 (capped at what the planet holds)
        if ($Fleet === [] && ($preset = ShipPreset::parse($this->input()->rawString('ships'), ShipPreset::flyable($e->ids('fleet')))) !== null) {
            foreach ($preset as $ShipID => $count) {
                $amount = min($count, (int) $this->state->PLANET[$e->column($ShipID)]);
                if ($amount >= 1) {
                    $Fleet[$ShipID] = $amount;
                    $FleetRoom += $e->capacity($ShipID) * $amount;
                }
            }
        }

        return [$Fleet, $FleetRoom];
    }

    /**
     * Stores the fleet under a fresh random token in the session (used by steps 2 and 3, with the cargo the planet page
     * pre-filled) and returns the token.
     *
     * @param array<int, int|float> $Fleet ship id => count
     */
    private function storeFleet(array $Fleet, int|float $FleetRoom): string
    {
        $token = getRandomString();
        $session = $this->sessions()->load();
        $fleet = $this->freshTokens($session->fleet);
        $fleet[$token] = [
            'ownPlanet' => $this->state->PLANET['id'],
            'time'      => $this->state->runtime->timestamp,
            'fleet'     => $Fleet,
            'fleetRoom' => $FleetRoom,
            'predefinedResources' => [
                'met' =>  $this->input()->string('met_storage'),
                'krist' =>  $this->input()->string('krist_storage'),
                'deut' =>  $this->input()->string('deut_storage'),
            ],
        ];
        $session->fleet = $fleet;
        $session->save();

        return $token;
    }

    /**
     * Universe geometry the form needs for the flight time preview (size and distance factors).
     *
     * @return array<string, mixed>
     */
    /**
     * The prepared fleets still worth keeping: not older than the 10 minutes step 3 accepts, at most MAX_PREPARED_FLEETS
     * (the newest), so the session cannot grow without bound.
     *
     * @param mixed $fleet session value `fleet` (token => form data)
     * @return array<string, array<string, mixed>>
     */
    private function freshTokens(mixed $fleet): array
    {
        $fleet = is_array($fleet) ? $fleet : [];
        $oldest = $this->state->runtime->timestamp - 600;
        $fleet = array_filter($fleet, static fn(mixed $entry): bool => is_array($entry) && ($entry['time'] ?? 0) >= $oldest);

        return array_slice($fleet, -(self::MAX_PREPARED_FLEETS - 1), null, true);
    }

    /**
     * Galaxy geometry and distance settings of the current universe for the fleet page script (distance, duration and
     * fuel preview in the browser).
     *
     * @return array<string, mixed>
     */
    private function universeGeometry(): array
    {
        $config = $this->configs->get();

        return [
            'uni_type'                  => $config->uni_type,
            'galaxy_type'               => $config->galaxy_type,
            'max_galaxy'                => $config->max_galaxy,
            'max_system'                => $config->max_system,
            'max_planets'               => $config->max_planets,
            'galaxy_distance_factor'    => $config->galaxy_distance_factor,
            'system_distance_factor'    => $config->system_distance_factor,
            'system_distance'           => $config->system_distance,
            'planet_distance_factor'    => $config->planet_distance_factor,
            'planet_distance'           => $config->planet_distance,
        ];
    }

    /**
     * The message page when the planet no longer holds all selected ships, null when it does.
     *
     * @param array<int, int|float> $Fleet ship id => count
     */
    private function checkShipsAvailable(array $Fleet): ?Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        //check if all ships are available
        foreach ($Fleet as $Ship => $Count) {
            if ($Count > $this->state->PLANET[$e->column($Ship)]) {
                return $this->messageResponse($LNG['fl_not_all_ship_avalible'], [[
                    'label' => $LNG['sys_back'],
                    'url'   => $this->router->generate('game_fleet_table_show'),
                ]]);
            }
        }

        return null;
    }

    /**
     * Saves the coordinate shortcuts (request shortcut[<id or new...>] with name, galaxy, system, planet, type). Shortcuts of the player missing from the
     * request are deleted, `new...` entries inserted and changed ones updated (name cut to 32 characters); incomplete entries are skipped. Answers JSON with the saved message.
     */
    #[GameRoute('game', 'game/fleet/step1/shortcuts', 'fleetStep1', 'saveShortcuts', ['POST'])]
    public function saveShortcuts(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $ShortcutData = $this->state->requestInput['shortcut'] ?? [];
        if (!is_array($ShortcutData)) {
            $ShortcutData = [];
        }
        $ShortcutUser = $this->GetUserShotcut();

        foreach ($ShortcutUser as $ID => $shortcut) {
            if (!isset($ShortcutData[$ID])) {
                $sql = "DELETE FROM %%SHORTCUTS%% WHERE `shortcutID` = :shortcutID AND `ownerID` = :userID;";
                $db->delete($sql, [
                    ':userID' => $USER['id'],
                    ':shortcutID' => $ID,
                ]);
            }
        }

        if (!isset($this->state->requestInput['shortcut'])) {
            return $this->jsonResponse($LNG['fl_shortcut_saved']);
        }

        $kept = count(array_intersect_key($ShortcutUser, $ShortcutData));
        foreach ($ShortcutData as $ID => $planetData) {
            $planetData = $this->cleanShortcut($planetData);
            if ($planetData === null) {
                continue;
            }
            if (strpos((string) $ID, 'new') !== false) {
                if ($kept >= self::MAX_SHORTCUTS) {
                    continue;
                }
                ++$kept;
                $sql = "INSERT INTO %%SHORTCUTS%% SET 
                    `ownerID`   = :userID,
                    `name`      = :name,
                    `galaxy`    = :galaxy,
                    `system`    = :system,
                    `planet`    = :planet,
                    `type`      = :type;";
                $db->insert($sql, [
                    ':userID' => $USER['id'],
                    ':name' => $planetData['name'],
                    ':galaxy' => $planetData['galaxy'],
                    ':system' => $planetData['system'],
                    ':planet' => $planetData['planet'],
                    ':type' => $planetData['type'],
                ]);
            } elseif (isset($ShortcutUser[$ID])) {
                $planetData['ownerID'] = $USER['id'];
                $planetData['shortcutID'] = $ID;
                if ($planetData != $ShortcutUser[$ID]) {
                    $sql = "UPDATE %%SHORTCUTS%% SET 
                        `name`      = :name,
                        `galaxy`    = :galaxy,
                        `system`    = :system,
                        `planet`    = :planet,
                        `type`      = :type 
                        WHERE `shortcutID` = :shortcutID AND `ownerID` = :userID;";
                    $db->update($sql, [
                        ':userID' => $USER['id'],
                        ':name' => $planetData['name'],
                        ':galaxy' => $planetData['galaxy'],
                        ':system' => $planetData['system'],
                        ':planet' => $planetData['planet'],
                        ':type' => $planetData['type'],
                        ':shortcutID' => $ID,
                    ]);
                }
            }
        }

        return $this->jsonResponse($LNG['fl_shortcut_saved']);
    }

    /**
     * A posted shortcut with a name of at most 32 characters and coordinates inside the universe (galaxy, system, slot
     * 1 .. max_planets + 3, type planet/debris/moon), or null when it is incomplete or outside.
     *
     * @param mixed $planetData one entry of `shortcut[...]`
     * @return array{name: string, galaxy: int, system: int, planet: int, type: int}|null
     */
    private function cleanShortcut(mixed $planetData): ?array
    {
        if (!is_array($planetData) || !is_scalar($planetData['name'] ?? null) || !is_numeric($planetData['galaxy'] ?? null) || !is_numeric($planetData['system'] ?? null) || !is_numeric($planetData['planet'] ?? null)) {
            return null;
        }
        $config = $this->configs->get();
        $name = mb_substr(trim((string) $planetData['name']), 0, 32);
        $galaxy = (int) $planetData['galaxy'];
        $system = (int) $planetData['system'];
        $planet = (int) $planetData['planet'];
        $type = (int) ($planetData['type'] ?? 1);
        if (
            $name === '' || $galaxy < 1 || $galaxy > $config->max_galaxy || $system < 1 || $system > $config->max_system
            || $planet < 1 || $planet > $config->max_planets + 3 || $type < 1 || $type > 3
        ) {
            return null;
        }

        return ['name' => $name, 'galaxy' => $galaxy, 'system' => $system, 'planet' => $planet, 'type' => $type];
    }

    /**
     * The target coordinates of the request; without a preset the player's next other planet (the current one is no
     * valid target), else the current planet.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} galaxy, system, planet, type
     */
    private function presetTarget(): array
    {
        $PLANET = $this->state->PLANET;
        $default = $this->input()->int('galaxy', 0) === 0 ? ($this->GetColonyList()[0] ?? null) : null;

        return [
            $this->input()->int('galaxy', (int) ($default['galaxy'] ?? $PLANET['galaxy'])),
            $this->input()->int('system', (int) ($default['system'] ?? $PLANET['system'])),
            $this->input()->int('planet', (int) ($default['planet'] ?? $PLANET['planet'])),
            $this->input()->int('type', $this->input()->int('planettype', (int) ($default['type'] ?? $PLANET['planet_type']))),
        ];
    }

    /**
     * The player's other planets/moons (not the current one) as quick-select targets with name, coordinates and type.
     */
    private function GetColonyList()
    {
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $ColonyList = [];

        foreach ($USER['PLANETS'] ?? [] as $CurPlanetID => $CurPlanet) {
            if ($PLANET['id'] == $CurPlanet['id']) {
                continue;
            }

            $ColonyList[] = [
                'name'      => $CurPlanet['name'],
                'galaxy'    => $CurPlanet['galaxy'],
                'system'    => $CurPlanet['system'],
                'planet'    => $CurPlanet['planet'],
                'type'      => $CurPlanet['planet_type'],
            ];
        }

        return $ColonyList;
    }

    /**
     * Saved fleet target shortcuts of the player (empty when the shortcuts module is off).
     *
     * @return array<int, array<string, mixed>> shortcut rows keyed by shortcutID
     */
    private function GetUserShotcut()
    {
        $USER = $this->state->USER;

        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SHORTCUTS)) {
            return [];
        }

        $db = $this->db;

        $sql = "SELECT * FROM %%SHORTCUTS%% WHERE `ownerID` = :userID;";
        $ShortcutResult = $db->select($sql, [':userID'   => $USER['id']]);

        $ShortcutList = [];

        foreach ($ShortcutResult as $ShortcutRow) {
            $ShortcutList[$ShortcutRow['shortcutID']] = $ShortcutRow;
        }

        return $ShortcutList;
    }

    /**
     * Alliance combat groups (AKS) the player is invited to (USERS_TO_ACS) that still have room: fewer than `max_fleets_per_acs` fleets and fewer than
     * `max_participants_per_acs` participants (or the player already takes part).
     */
    private function GetAvalibleACS()
    {
        $USER = $this->state->USER;

        $db = $this->db;

        $ACSList = [];

        // a group aimed at the pirate mothership (map content) stores its pirate base id plus NpcTargets::ACS_OFFSET as target
        $sql = "SELECT acs.`id`, acs.`public_id`, acs.`name`, COALESCE(planet.`galaxy`, npc.`galaxy`) AS `galaxy`, COALESCE(planet.`system`, npc.`system`) AS `system`,
                COALESCE(planet.`planet`, npc.`planet`) AS `planet`, COALESCE(planet.`planet_type`, npc.`planet_type`) AS `planet_type`
            FROM %%USERS_TO_ACS%%
            INNER JOIN %%AKS%% acs ON `acsID` = acs.`id`
            LEFT JOIN %%PLANETS%% planet ON planet.`id` = acs.`target`
            LEFT JOIN %%PIRATE_BASES%% npc ON acs.`target` >= " . NpcTargets::ACS_OFFSET . " AND npc.`id` + " . NpcTargets::ACS_OFFSET . " = acs.`target` AND npc.`kind` > 0
            WHERE `userID` = :userID AND (planet.`id` IS NOT NULL OR npc.`id` IS NOT NULL) AND :maxFleets > (SELECT COUNT(*) FROM %%FLEETS%% WHERE `group` = acsID);";
        $ACSResult = $db->select($sql, [
            ':userID' => $USER['id'],
            ':maxFleets' => $this->configs->get()->max_fleets_per_acs,
        ]);

        if (!empty($ACSResult)) {
            foreach ($ACSResult as $ACSRow) {
                $sql = "SELECT `acsID`
                    FROM %%USERS_TO_ACS%%
                    WHERE `userID` = :userID AND `acsID` = :aksID
                      AND ( :maxParticipants > (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :aksID)
                       OR 1 = (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :aksID AND `owner` = :userID) );";
                $ACSResult2 = $db->select($sql, [
                    ':userID' => $USER['id'],
                    ':aksID' => $ACSRow['id'],
                    ':maxParticipants' => $this->configs->get()->max_participants_per_acs,
                ]);

                if (!empty($ACSResult2)) {
                    $ACSList[] = $ACSRow;
                }
            }
        }

        return $ACSList;
    }

    /**
     * XHR check of the entered target (request: galaxy, system, planet, planet_type, kolo = colony ship in the fleet, optional mission: a hostile mission against a noob protected or too strong player is refused with the text of the final check). Answers JSON: `OK` or the error text
     * for: same planet, no own pirate base (position max_planets + 3) or empty pirate debris, expedition without expedition tech or free slot (position
     * max_planets + 1), otherwise a missing moon/planet (a planet is fine with a colony ship), vacation mode, admin attack protection (`adm_attack`),
     * destroyed planet and an empty debris field.
     */
    #[GameRoute('game', 'game/fleet/step1/check-target', 'fleetStep1', 'checkTarget')]
    public function checkTarget(): Response
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $targetGalaxy = $this->input()->int('galaxy');
        $targetSystem = $this->input()->int('system');
        $targetPlanet = $this->input()->int('planet');
        $targetPlanetType = $this->input()->int('planet_type', 1);
        $koloShipInFleet = $this->input()->int('kolo');

        if (
            $targetGalaxy == $PLANET['galaxy'] && $targetSystem == $PLANET['system']
            && $targetPlanet == $PLANET['planet'] && $targetPlanetType == $PLANET['planet_type']
        ) {
            return $this->jsonResponse($LNG['fl_error_same_planet']);
        }

        if ($targetPlanet == $this->configs->get()->max_planets + 3) {
            $error = $this->checkPirateBaseTarget($targetGalaxy, $targetSystem, $targetPlanetType);
        }
        // If target is not expedition
        elseif ($targetPlanet != $this->configs->get()->max_planets + 1) {
            $error = $this->checkPlanetTarget($targetGalaxy, $targetSystem, $targetPlanet, $targetPlanetType, $koloShipInFleet);
        } else {
            $error = $this->checkExpeditionTarget();
        }

        if ($error === null && in_array($this->input()->int('mission'), self::PROTECTED_MISSIONS, true)) {
            $error = $this->protectionNotice($this->targetOwnerId($targetGalaxy, $targetSystem, $targetPlanet), $this->input()->int('mission')) ?: null;
        }

        return $this->jsonResponse($error ?? 'OK');
    }

    /** Owner id of the planet at the position (0 when nothing is there, e.g. an expedition position). */
    private function targetOwnerId(int $galaxy, int $system, int $planet): int
    {
        $sql = 'SELECT `id_owner` FROM %%PLANETS%% WHERE `universe` = :universe AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet AND `planet_type` = 1;';

        return (int) $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(), ':galaxy' => $galaxy, ':system' => $system, ':planet' => $planet,
        ], 'id_owner');
    }

    /**
     * Target check for the player's own pirate base in the system: it must exist, and a debris target needs debris.
     * Answers the error as JSON and ends the request on failure.
     */
    private function checkPirateBaseTarget(mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanetType): ?string
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT * FROM %%PIRATE_BASES%% 
            WHERE `galaxy` = :galaxy AND `system` = :system AND `id_owner` = :userId";
        $pirateBase = $db->selectSingle($sql, [
            ':galaxy'   => $targetGalaxy,
            ':system'   => $targetSystem,
            ':userId'   => $this->state->USER['id'],
        ]);

        if (empty($pirateBase)) {
            return $LNG['fl_error_not_avalible'];
        }

        if ($targetPlanetType == 2 && ($pirateBase['der_metal'] + $pirateBase['der_crystal']) == 0) {
            return $LNG['fl_error_empty_derbis'];
        }

        return null;
    }

    /**
     * Target check for a normal position: the moon (or planet, which is fine for a colony ship) must exist, the owner must
     * not be in vacation mode (not for debris), admin attack protection (`adm_attack`), no destroyed planet and debris
     * targets need debris. Returns the error text, null when the target is fine.
     */
    private function checkPlanetTarget(mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetPlanetType, mixed $koloShipInFleet): ?string
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $sql = "SELECT u.`id`, u.`urlaubs_modus`, u.`user_lastip`, u.`authattack`,
        	p.`destruyed`, p.`der_metal`, p.`der_crystal`, p.`tf_active`, p.`destruyed`
            FROM %%USERS%% as u, %%PLANETS%% as p 
            WHERE p.`universe` = :universe
              AND p.`galaxy` = :targetGalaxy
              AND p.`system` = :targetSystem
              AND p.`planet` = :targetPlanet
              AND p.`planet_type` = :targetType
              AND u.`id` = p.`id_owner`;";

        $planetData = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':targetGalaxy' => $targetGalaxy,
            ':targetSystem' => $targetSystem,
            ':targetPlanet' => $targetPlanet,
            ':targetType' => (($targetPlanetType == 2) ? 1 : $targetPlanetType),
        ]);

        if (empty($planetData) && $targetPlanet >= 1 && $targetPlanet <= $this->configs->get()->max_planets) {
            $npc = (new NpcTargets($this->db, $this->configs, $this->state))->atSlot($this->universe()->current(), (int) $targetGalaxy, (int) $targetSystem, (int) $targetPlanet);
            if ($npc !== null) {
                return $this->checkNpcTarget($npc, (int) $targetPlanetType);
            }
        }

        if ($targetPlanetType == 3 && empty($planetData)) {
            return $LNG['fl_error_no_moon'];
        }

        if ($targetPlanetType == 1 && empty($planetData) && !$koloShipInFleet) {
            return $LNG['fl_error_not_avalible'];
        }

        if ($targetPlanetType != 2 && !empty($planetData['urlaubs_modus'])) {
            return $LNG['fl_in_vacation_player'];
        }

        if (!empty($planetData)) {
            if (
                $planetData['id'] != $USER['id'] && $this->configs->get()->adm_attack == 1
                && $planetData['authattack'] > $USER['authlevel']
            ) {
                return $LNG['fl_admin_attack'];
            }
        }

        if (!empty($planetData)) {
            if ($planetData['destruyed'] != 0 && $targetPlanetType != 2) {
                return $LNG['fl_error_not_avalible'];
            }
        }

        if ($targetPlanetType == 2 && (empty($planetData) || ($planetData['der_metal'] + $planetData['der_crystal']) == 0
            && $planetData['tf_active'] == 0)) {
            return $LNG['fl_error_empty_derbis'];
        }

        return null;
    }

    /**
     * Target check for a derelict field (debris target, needs salvage) or the pirate mothership (planet target) of the map content
     * features: any other target type is not available.
     *
     * @param array<string, mixed> $npc row from NpcTargets
     */
    private function checkNpcTarget(array $npc, int $targetPlanetType): ?string
    {
        $LNG = $this->state->LNG;
        if ($npc['npc_kind'] === NpcTargets::KIND_DERELICT) {
            if ($targetPlanetType != 2) {
                return $LNG['fl_error_not_avalible'];
            }

            return ($npc['der_metal'] + $npc['der_crystal']) > 0 ? null : $LNG['fl_error_empty_derbis'];
        }

        return $targetPlanetType == 1 ? null : $LNG['fl_error_not_avalible'];
    }

    /**
     * Target check for the expedition position: the player needs expedition tech and a free expedition slot.
     * Returns the error text, null when the target is fine.
     */
    private function checkExpeditionTarget(): ?string
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        if ($USER[$this->elements()->column(124)] == 0) {
            return $LNG['fl_expedition_tech_required'];
        }

        $activeExpedition = $this->fleetFunctions()->getCurrentFleets($USER['id'], 15, true);

        if ($activeExpedition >= $this->fleetFunctions()->getExpeditionLimit($USER)) {
            return $LNG['fl_no_expedition_slot'];
        }

        return null;
    }

    /**
     * Relic fuel bonus of the start planet (1.0 without relics): the page's fuel formula multiplies by it together with
     * the honor boost, so the fuel shown matches the fuel charged (FleetMath::fleetConsumptionFor).
     */
    private function relicFuelFactor(): float
    {
        return $this->relicBonus()->reductionFactor((int) $this->state->USER['id'], (int) $this->state->USER['universe'], (int) $this->state->PLANET['id'], \App\Game\Engagement\Relics\RelicCatalog::FUEL);
    }
}
