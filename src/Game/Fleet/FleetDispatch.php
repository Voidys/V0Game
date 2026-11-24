<?php

namespace App\Game\Fleet;

use App\Game\Enum\Mission;
use App\Game\Engagement\MapContent\NpcTargets;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Security\FleetDailyCaps;

/**
 * Fleet dispatch, step 3: validates the prepared fleet (form token of step 2) against the target and the player's
 * limits, takes cargo and fuel from the start planet and sends the fleet (FleetFunctions::sendFleet); a trade mission also
 * records its market offer. Runs inside one database transaction that locks the user and the start planet.
 *
 * Every refusal is thrown as FleetDispatchRefused (message and back buttons); the controller shows it as message page.
 * Collaborators: FleetFunctions (rules, slots, send), ProtectionRules (noob protection), SessionManager (form token).
 */
class FleetDispatch
{
    use GameUtilities;
    use FleetDispatchMissionRules;

    private mixed $targetMission;
    private float|int $TransportMetal;
    private float|int $TransportCrystal;
    private float|int $TransportDeuterium;
    private mixed $WantedResourceType;
    private float|int $WantedResourceAmount;
    private mixed $markettype;
    private mixed $visibility;
    private mixed $maxFlightTime;
    private mixed $stayTime;
    private mixed $token;
    private mixed $distance;
    private mixed $targetGalaxy;
    private mixed $targetSystem;
    private mixed $targetPlanet;
    private mixed $targetType;
    private mixed $fleetGroup;
    private mixed $fleetArray;
    private mixed $fleetStorage;
    private mixed $fleetSpeed;
    private mixed $ownPlanet;
    private mixed $ACSTime = 0;
    private mixed $targetPlanetData = [];
    private mixed $targetPlayerData = [];
    private mixed $availableMissions = [];
    private bool $containsOnlyProbes = true;
    private mixed $consumption = 0;
    private mixed $duration = 0;
    private mixed $fleetMaxSpeed = 0;
    private mixed $fleetResource = [];
    private mixed $fleetStartTime = 0;
    private mixed $fleetEndTime = 0;
    private mixed $fleetStayTime = 0;
    private mixed $StayDuration = 0;
    private mixed $config;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly ?FleetDailyCaps $dailyCaps = null,
    ) {}

    /**
     * Dispatches the fleet of the posted form.
     *
     * @return array<string, mixed> template variables of the result page (target mission, times, consumption, fleet list ...)
     * @throws FleetDispatchRefused when the dispatch is refused (nothing is sent)
     */
    public function dispatch(): array
    {
        $USER = $this->state->USER;

        if (IsVacationMode($USER)) {
            throw FleetDispatchRefused::backToFleetPage(0);
        }

        $db = $this->db;
        // the form token is consumed from the session (no database), so this must not be repeated with the transaction
        $this->loadForm();

        // a repeated attempt (deadlock) starts from the state the first one started with
        $planet = $this->state->PLANET;
        $storage = $this->fleetStorage;
        $mission = $this->targetMission;
        $group = $this->fleetGroup;

        $db->transactional(function () use ($db, $USER, $planet, $storage, $mission, $group): void {
            $this->state->PLANET = $planet;
            $this->fleetStorage = $storage;
            $this->targetMission = $mission;
            $this->fleetGroup = $group;

            // Serialise parallel dispatches of the same player, so the slot and expedition limits cannot be raced
            // from several planets/sessions (the user row is locked before the planet row, always in this order).
            $db->selectSingle('SELECT `id` FROM %%USERS%% WHERE `id` = :userId FOR UPDATE;', [':userId' => $USER['id']]);
            // all checks below (ships, fuel, cargo) run on the locked row, not on the copy read at the start of the request
            $locked = $this->lockPlanet($this->state->PLANET['id']);
            if (!empty($locked)) {
                $this->state->PLANET = array_merge($this->state->PLANET, $locked);
            }

            $this->validateRequest();
            $this->resolveAcs();
            $this->loadTargetPlanet();
            $this->loadTargetPlayer();
            $this->checkMission();
            $this->planFlight();
            $this->launch();
        });

        return $this->resultVariables();
    }

    /**
     * Refuses the dispatch with a message and a single "back" button to the given page ($url is a route name).
     *
     * @throws FleetDispatchRefused always
     */
    private function refuse(string $message, string $url): never
    {
        $LNG = $this->state->LNG;
        throw new FleetDispatchRefused($message, [['label' => $LNG['sys_back'], 'url' => $url]]);
    }

    /**
     * Locks the start planet row for the transaction.
     *
     * @return array<string, mixed>|false the locked row
     */
    private function lockPlanet(mixed $planetId): array|false
    {
        return $this->db->selectSingle("SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;", [
            ':planetId' => $planetId,
        ]);
    }

    /** Reads the request and the prepared fleet form (token from step 2) from the session; refuses when the token is unknown (back to step 1) or older than 10 minutes. */
    private function loadForm(): void
    {
        $this->targetMission = $this->input()->typed('mission', Mission::Transport->value);
        $this->TransportMetal = max(0, round($this->input()->float('metal')));
        $this->TransportCrystal = max(0, round($this->input()->float('crystal')));
        $this->TransportDeuterium = max(0, round($this->input()->float('deuterium')));
        $this->WantedResourceType = $this->input()->int('resEx');
        $this->WantedResourceAmount = max(0, round($this->input()->float('exchange')));
        $this->markettype = $this->input()->int('markettype');
        $this->visibility = $this->input()->int('visibility');
        $this->maxFlightTime = $this->input()->int('maxFlightTime');
        $this->stayTime = $this->input()->int('staytime');
        $this->token = $this->input()->string('token');

        $this->config = $this->configs->get();

        $session = $this->sessions()->load();
        $fleet = $session->fleet;

        if (!isset($fleet[$this->token])) {
            throw FleetDispatchRefused::backToFleetPage(1);
        }

        if ($fleet[$this->token]['time'] < $this->state->runtime->timestamp - 600) {
            unset($fleet[$this->token]);
            $session->fleet = $fleet;
            $session->save();
            throw FleetDispatchRefused::backToFleetPage(0);
        }

        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        $formData = $fleet[$this->token];
        unset($fleet[$this->token]);
        $session->fleet = $fleet;
        $session->save();

        $this->distance = $formData['distance'];
        $this->targetGalaxy = $formData['targetGalaxy'];
        $this->targetSystem = $formData['targetSystem'];
        $this->targetPlanet = $formData['targetPlanet'];
        $this->targetType = $formData['targetType'];
        $this->fleetGroup = $formData['fleetGroup'];
        $this->fleetArray = $formData['fleet'];
        $this->fleetStorage = $formData['fleetRoom'];
        $this->fleetSpeed = $formData['fleetSpeed'];
        $this->ownPlanet = $formData['ownPlanet'];
    }

    /** Validates own planet, target coordinates, trade parameters, the resources to carry and the free fleet slots. */
    private function validateRequest(): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        if ($this->ownPlanet != $PLANET['id']) {
            $this->refuse($LNG['fl_own_planet_error'], 'game_fleet_step1_show');
        }

        if ($this->targetMission != Mission::Acs->value) {
            $this->fleetGroup = 0;
        }

        if (
            $PLANET['galaxy'] == $this->targetGalaxy && $PLANET['system'] == $this->targetSystem
            && $PLANET['planet'] == $this->targetPlanet && $PLANET['planet_type'] == $this->targetType
        ) {
            $this->refuse($LNG['fl_error_same_planet'], 'game_fleet_step1_show');
        }

        if (
            $this->targetGalaxy < 1 || $this->targetGalaxy > $this->config->max_galaxy
            || $this->targetSystem < 1 || $this->targetSystem > $this->config->max_system
            || $this->targetPlanet < 1 || $this->targetPlanet > ($this->config->max_planets + 3)
            || ($this->targetType !== 1 && $this->targetType !== 2 && $this->targetType !== 3)
        ) {
            $this->refuse($LNG['fl_invalid_target'], 'game_fleet_step1_show');
        }

        if ($this->targetMission == Mission::Trade->value) {
            // Whitelist the trade parameters (the AJAX pre-check in step 2 can be skipped with a crafted request)
            $this->WantedResourceType = (int) $this->WantedResourceType;
            $this->markettype = (int) $this->markettype;
            if (!in_array($this->WantedResourceType, [1, 2, 3], true) || !in_array($this->markettype, [0, 1], true)) {
                $this->refuse($LNG['fl_invalid_mission'], 'game_fleet_step2_show');
            }
        }

        // Transport and market type 0 have to contain resources
        if (
            ($this->targetMission == Mission::Transport->value || ($this->targetMission == Mission::Trade->value && $this->markettype == 0))
            && $this->TransportMetal + $this->TransportCrystal + $this->TransportDeuterium < 1
        ) {
            $this->refuse($LNG['fl_no_noresource'], 'game_fleet_step2_show');
        }
        // A trade offer carries exactly one resource (the offer stores only that one; the UI pre-check enforces it too)
        if (
            $this->targetMission == Mission::Trade->value
            && (($this->TransportMetal > 0) + ($this->TransportCrystal > 0) + ($this->TransportDeuterium > 0)) > 1
        ) {
            $this->refuse($LNG['fl_trade_to_many_resources_loaded'], 'game_fleet_step2_show');
        }
        // Market typ 1 cannot contain resources
        if (
            $this->targetMission == Mission::Trade->value && $this->markettype == 1
            && $this->TransportMetal + $this->TransportCrystal + $this->TransportDeuterium != 0
        ) {
            $this->refuse($LNG['fl_resources'], 'game_fleet_step2_show');
        }

        if ($this->targetMission == Mission::Trade->value && $this->WantedResourceAmount < 1) {
            $this->refuse($LNG['fl_no_noresource_exchange'], 'game_fleet_step2_show');
        }

        if ($this->targetMission == Mission::Trade->value && $this->WantedResourceAmount > pow(10, 50)) {
            $this->refuse($LNG['fl_invalid_mission'], 'game_fleet_step2_show');
        }

        $ActualFleets = $this->fleetFunctions()->getCurrentFleets($USER['id']);

        if ($this->fleetFunctions()->getMaxFleetSlots($USER) <= $ActualFleets) {
            $this->refuse($LNG['fl_no_slots'], 'game_fleet_table_show');
        }
    }

    /** Resolves the ACS group the fleet wants to join (arrival time of the group); a group that is no longer joinable refuses the dispatch. */
    private function resolveAcs(): void
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $this->ACSTime = 0;

        if (!empty($this->fleetGroup)) {
            $sql = "SELECT `ankunft`
                FROM %%USERS_TO_ACS%%
                INNER JOIN %%AKS%% ON `id` = `acsID`
                WHERE `acsID` = :acsID
                AND `userID` = :userID
                AND :maxFleets > (SELECT COUNT(*) FROM %%FLEETS%% WHERE `group` = :acsID);";
            $this->ACSTime = $db->selectSingle($sql, [
                ':acsID'        => $this->fleetGroup,
                ':userID'       => $USER['id'],
                ':maxFleets'    => $this->config->max_fleets_per_acs,
            ], 'ankunft');

            if (!empty($this->ACSTime)) {
                $sql = "SELECT `ankunft`
                    FROM %%USERS_TO_ACS%%
                    INNER JOIN %%AKS%% ON `id` = `acsID`
                    WHERE `acsID` = :acsID
                      AND `userID` = :userID
                      AND ( :maxParticipants > (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :acsID)
                       OR 1 = (SELECT COUNT(DISTINCT `owner`) FROM %%FLEETS%% WHERE `group` = :acsID AND `owner` = :userID) );";
                $this->ACSTime = $db->selectSingle($sql, [
                    ':acsID'        => $this->fleetGroup,
                    ':userID' => $USER['id'],
                    ':maxParticipants' => $this->config->max_participants_per_acs,
                ], 'ankunft');
            }

            if (empty($this->ACSTime)) {
                $this->refuse($this->state->LNG['fl_invalid_mission'], 'game_fleet_step2_show');
            }
        }
    }

    /** Loads the target planet (or pirate base) and checks that it exists, colonisation and expedition limits, the ships on the planet and that an ACS fleet flies to the ACS target. */
    private function loadTargetPlanet(): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $elements = $this->elements();
        $LNG = $this->state->LNG;
        $db = $this->db;
        $this->targetPlanetData = $this->fetchTargetRow();

        if ($this->targetMission == Mission::Colonisation->value) {
            if (!empty($this->targetPlanetData)) {
                $this->refuse($LNG['fl_colonize_slot_taken'], 'game_fleet_step1_show');
            }

            if ($this->targetType != 1) {
                $this->refuse($LNG['fl_only_planets_colonizable'], 'game_fleet_step1_show');
            }
        }

        if ($this->targetMission == Mission::Colonisation->value || $this->targetMission == Mission::Expedition->value || $this->targetMission == Mission::Trade->value) {
            $this->targetPlanetData = ['id' => 0, 'id_owner' => 0, 'planettype' => 1];
        } else {
            if (!empty($this->targetPlanetData["destruyed"]) && $this->targetMission != Mission::Recycling->value) {
                $this->refuse($LNG['fl_no_target'], 'game_fleet_step1_show');
            }

            if (empty($this->targetPlanetData)) {
                $this->refuse($LNG['fl_no_target'], 'game_fleet_step1_show');
            }
        }
        $this->containsOnlyProbes = true;
        foreach ($this->fleetArray as $Ship => $Count) {
            if ($Count > ($PLANET[$elements->find((int) $Ship)?->column] ?? null)) {
                $this->refuse($LNG['fl_not_all_ship_avalible'], 'game_fleet_table_show');
            }
            if ($Ship != SHIP_PROBE) {
                $this->containsOnlyProbes = false;
            }
        }

        $this->checkExpeditionSlot();

        // An ACS fleet must fly to the planet the ACS was created for
        if ($this->fleetGroup != 0) {
            $acsTarget = $db->selectSingle('SELECT `target` FROM %%AKS%% WHERE `id` = :acsID;', [
                ':acsID' => $this->fleetGroup,
            ], 'target');
            if ((int) $acsTarget !== (int) ($this->targetPlanetData['acs_target'] ?? $this->targetPlanetData['id'] ?? 0)) {
                $this->refuse($LNG['fl_invalid_mission'], 'game_fleet_step2_show');
            }
        }
    }

    /**
     * Reads the target row: the user's pirate base of the target system when the target position is the pirate base
     * slot, otherwise the planet or moon at the target coordinates (a debris target counts as the planet).
     *
     * @return array<string, mixed>|false false when there is nothing at the target
     */
    private function fetchTargetRow(): array|false
    {
        $USER = $this->state->USER;
        $db = $this->db;
        if ($this->targetPlanet == $this->config->max_planets + 3) {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, 0 as `tf_active`, 0 as `destruyed`, 0 as `ally_deposit`
                FROM %%PIRATE_BASES%% 
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND `system` = :targetSystem AND `id_owner` = :userId;";
            return $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $this->targetGalaxy,
                ':targetSystem' => $this->targetSystem,
                ':userId' => $USER['id'],
            ]);
        } else {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, `tf_active`, `destruyed`, `ally_deposit`
                FROM %%PLANETS%%
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy
                  AND `system` = :targetSystem AND `planet` = :targetPlanet AND `planet_type` = :targetType;";
            $row = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $this->targetGalaxy,
                ':targetSystem' => $this->targetSystem,
                ':targetPlanet' => $this->targetPlanet,
                ':targetType' => ($this->targetType == 2 ? 1 : $this->targetType),
            ]);
            if (empty($row) && $this->targetPlanet <= $this->config->max_planets) {
                // derelict field or pirate mothership (map content features) on an otherwise empty slot
                $row = (new NpcTargets($db, $this->configs, $this->state))
                    ->atSlot($this->universe()->current(), (int) $this->targetGalaxy, (int) $this->targetSystem, (int) $this->targetPlanet) ?? false;
            }

            return $row;
        }
    }

    /**
     * Expedition missions need a free expedition slot and more than probes in the fleet.
     */
    private function checkExpeditionSlot(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if ($this->targetMission == Mission::Expedition->value) {
            $activeExpedition = $this->fleetFunctions()->getCurrentFleets($USER['id'], Mission::Expedition->value, true);
            $maxExpedition = $this->fleetFunctions()->getExpeditionLimit($USER);

            if ($activeExpedition >= $maxExpedition) {
                $this->refuse($LNG['fl_no_expedition_slot'], 'game_fleet_table_show');
            }
            if ($this->containsOnlyProbes) {
                $this->refuse($LNG['fl_no_probe_expos'], 'game_fleet_table_show');
            }
        }
    }

    /** Loads the owner of the target (a neutral dummy for colonisation, expedition and trade). */
    private function loadTargetPlayer(): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;
        $usedPlanet = isset($this->targetPlanetData['id_owner']);
        $myPlanet = $usedPlanet && $this->targetPlanetData['id_owner'] == $USER['id'];
        $this->targetPlayerData = [];

        if ($this->targetMission == Mission::Colonisation->value || $this->targetMission == Mission::Expedition->value || $this->targetMission == Mission::Trade->value) {
            $this->targetPlayerData = [
                'id'                    => 0,
                'onlinetime'            => $this->state->runtime->timestamp,
                'ally_id'               => 0,
                'urlaubs_modus'         => 0,
                'authattack'            => 0,
                'total_points'          => 0,
                'rebuild_protection'    => 0,
            ];
        } elseif (!empty($this->targetPlanetData['npc_kind'])) {
            // derelict field / pirate mothership: no owner, nothing to protect (not a player)
            $this->targetPlayerData = [
                'id'                    => 0,
                'onlinetime'            => $this->state->runtime->timestamp,
                'ally_id'               => 0,
                'urlaubs_modus'         => 0,
                'authattack'            => 0,
                'total_points'          => 0,
                'rebuild_protection'    => 0,
            ];
        } elseif ($myPlanet) {
            $this->targetPlayerData = $USER;
        } elseif (!empty($this->targetPlanetData['id_owner'])) {
            $sql = "SELECT user.*, stat.`total_points`
                FROM %%USERS%% as user
                LEFT JOIN %%STATPOINTS%% as stat ON stat.`id_owner` = user.`id` AND stat.`stat_type` = '1'
                WHERE user.`id` = :ownerID;";

            $this->targetPlayerData = $db->selectSingle($sql, [':ownerID'  => $this->targetPlanetData['id_owner']]);
        }

        if (empty($this->targetPlayerData)) {
            $this->refuse($LNG['fl_empty_target'], 'game_fleet_step1_show');
        }
    }




    /** Computes duration, consumption, stay time and cargo, checks fuel, space and tech levels, deducts the cargo and fuel from the planet (in memory) and fixes the ACS-synchronised times. */
    private function planFlight(): void
    {
        $USER = $this->state->USER;
        $elements = $this->elements();
        $LNG = $this->state->LNG;
        $userModel = $this->models()->user($USER);
        $this->fleetMaxSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($this->fleetArray, $userModel);
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $this->duration = $this->fleetFunctions()->missionDurationFor($this->fleetSpeed, $this->fleetMaxSpeed, $this->distance, $SpeedFactor, $userModel);
        $this->consumption = $this->fleetFunctions()->fleetConsumptionFor($this->fleetArray, $this->duration, $this->distance, $userModel, $SpeedFactor, startPlanetId: (int) $this->state->PLANET['id']);

        if ($this->state->PLANET[$elements->column(903)] < $this->consumption) {
            $this->refuse($LNG['fl_not_enough_deuterium'], 'game_fleet_table_show');
        }

        $this->StayDuration = 0;

        if ($this->targetMission == Mission::Hold->value || $this->targetMission == Mission::Expedition->value || $this->targetMission == Mission::Trade->value) {
            if (!isset($this->availableMissions['StayBlock'][$this->stayTime])) {
                $this->refuse($LNG['fl_hold_time_not_exists'], 'game_fleet_table_show');
            }

            $this->StayDuration = round($this->availableMissions['StayBlock'][$this->stayTime] * 3600, 0);
        }

        $this->fleetStorage -= $this->consumption;

        $this->fleetResource = [
            901 => min($this->TransportMetal, floor($this->state->PLANET[$elements->column(901)])),
            902 => min($this->TransportCrystal, floor($this->state->PLANET[$elements->column(902)])),
            903 => min($this->TransportDeuterium, floor($this->state->PLANET[$elements->column(903)] - $this->consumption)),
        ];

        $this->checkPushGuard();

        $StorageNeeded = max(array_sum($this->fleetResource), $this->WantedResourceAmount);

        if ($StorageNeeded > $this->fleetStorage) {
            $this->refuse($LNG['fl_not_enough_space'], 'game_fleet_table_show');
        }


        if ($this->targetMission == Mission::Transfer->value) {
            $attack = $USER[$elements->column(109)] * 10;
            $shield = $USER[$elements->column(110)] * 10;
            $defensive = $USER[$elements->column(111)] * 10;

            $attack_targ = $this->targetPlayerData[$elements->column(109)] * 10;
            $shield_targ = $this->targetPlayerData[$elements->column(110)] * 10;
            $defensive_targ = $this->targetPlayerData[$elements->column(111)] * 10;

            if ($attack < $attack_targ || $defensive < $defensive_targ || $shield < $shield_targ) {
                $this->refuse($LNG['fl_stronger_techs'], 'game_fleet_table_show');
            }
        }

        $this->state->PLANET[$elements->column(901)] -= $this->fleetResource[901];
        $this->state->PLANET[$elements->column(902)] -= $this->fleetResource[902];
        $this->state->PLANET[$elements->column(903)] -= $this->fleetResource[903] + $this->consumption;

        $this->fleetStartTime = floor($this->duration) + $this->state->runtime->timestamp;
        $timeDifference = round(max(0, $this->fleetStartTime - $this->ACSTime));

        if ($this->fleetGroup != 0) {
            if ($timeDifference != 0) {
                // A joiner may delay the whole ACS by at most 30% of its remaining time
                if ($timeDifference > max(0, $this->ACSTime - $this->state->runtime->timestamp) * 0.3) {
                    $this->refuse($LNG['fl_invalid_mission'], 'game_fleet_table_show');
                }
                $this->fleetFunctions()->setACSTime($timeDifference, $this->fleetGroup);
            } else {
                $this->fleetStartTime = $this->ACSTime;
            }
        }

        $this->fleetStayTime = floor($this->fleetStartTime + $this->StayDuration);
        $this->fleetEndTime = floor($this->fleetStayTime + $this->duration);
    }

    /** Sends the fleet and records a market offer for the trade mission (inside the dispatch transaction). */
    private function launch(): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        // last check: a refused launch must not use up the daily quota (audit F-14)
        $capped = $this->dailyCaps?->consume((int) $USER['id'], $this->targetMission);
        if ($capped !== null) {
            $this->refuse($this->state->LNG->format($capped['key'], $capped['limit'], $capped['hours'], $capped['minutes']), 'game_fleet_table_show');
        }
        $id = $this->fleetFunctions()->sendFleet(
            $this->fleetArray,
            $this->targetMission,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $this->targetPlanetData['id_owner'],
            $this->targetPlanetData['id'],
            $this->targetGalaxy,
            $this->targetSystem,
            $this->targetPlanet,
            $this->targetType,
            $this->fleetResource,
            $this->fleetStartTime,
            $this->fleetStayTime,
            $this->fleetEndTime,
            $this->fleetGroup,
            0, // missileTarget
            0, // noReturn
            $this->consumption,
        );

        if (in_array($this->targetMission, \App\Game\Engagement\Returning\ReturningPlayers::AGGRESSIVE, true)) {
            // sending an aggressive mission ends the returning-player protection of the sender
            $this->returningPlayers()->endProtection((int) $USER['universe'], (int) $USER['id']);
        }
        $this->recordTradeOffer($id);
    }

    /** Records the market offer (table trades) of a trade mission fleet: what it carries and what it wants in return. */
    private function recordTradeOffer(mixed $id): void
    {
        $db = $this->db;
        if ($this->targetMission == Mission::Trade->value) {
            if ($this->fleetResource[901] > 0) {
                $resourceType = 1;
                $resourceAmount = $this->fleetResource[901];
            } elseif ($this->fleetResource[902] > 0) {
                $resourceType = 2;
                $resourceAmount = $this->fleetResource[902];
            } elseif ($this->fleetResource[903] > 0) {
                $resourceType = 3;
                $resourceAmount = $this->fleetResource[903];
            } else {
                $resourceType = 0;
                $resourceAmount = 0;
            }

            $sql = 'INSERT INTO %%TRADES%% SET
				`transaction_type`      = :transaction,
                `seller_fleet_id`       = :sellerFleet,
				`filter_visibility`     = :visibility,
				`filter_flighttime`     = :flightTime,
				`ex_resource_type`      = :exResType,
				`ex_resource_amount`    = :exResAmount,
                `resource_type`         = :resType,
                `resource_amount`       = :resAmount,
                `marketplace_galaxy`    = :galaxy,
                `marketplace_system`    = :system;';

            $db->insert($sql, [
                ':transaction'          => $this->markettype,
                ':sellerFleet'          => $id,
                ':exResType'            => $this->WantedResourceType,
                ':exResAmount'          => $this->WantedResourceAmount,
                ':flightTime'           => $this->maxFlightTime * 3600,
                ':visibility'           => $this->visibility,
                ':resType'              => $resourceType,
                ':resAmount'            => $resourceAmount,
                ':galaxy'               => $this->targetGalaxy,
                ':system'               => $this->targetSystem,
            ]);
        }
    }

    /**
     * Template variables of the "fleet sent" page.
     *
     * @return array<string, mixed>
     */
    private function resultVariables(): array
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        foreach ($this->fleetArray as $Ship => $Count) {
            $fleetList[$LNG['tech'][$Ship]] = $Count;
        }

        $LNG->includeData(['FLEET']);

        $keySender = 'type_planet_short_' . $PLANET['planet_type'];
        $startTypeLetter = ' (' . $LNG[$keySender] . ')';
        $this->targetType = $this->targetMission == Mission::Recycling->value ? 2 : $this->targetType;
        $keyTarget = 'type_planet_short_' . $this->targetType;
        $targetTypeLetter = ' (' . $LNG[$keyTarget] . ')';
        return [
            'goto'              => 'game_fleet_table_show',
            'gotoinsec'         => 3,
            'targetMission'     => $this->targetMission,
            'distance'          => $this->distance,
            'consumption'       => $this->consumption,
            'from'              => $PLANET['galaxy'] . ":" . $PLANET['system'] . ":" . $PLANET['planet'] . $startTypeLetter,
            'destination'       => $this->targetGalaxy . ":" . $this->targetSystem . ":" . $this->targetPlanet . $targetTypeLetter,
            'fleetStartTime'    => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->fleetStartTime, $USER['timezone']),
            'fleetEndTime'      => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->fleetEndTime, $USER['timezone']),
            'MaxFleetSpeed'     => $this->fleetMaxSpeed,
            'FleetList'         => $this->fleetArray,
        ];
    }
}
