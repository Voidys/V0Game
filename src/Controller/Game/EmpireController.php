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

use App\Game\Enum\PlanetType;
use App\Game\Enum\Mission;
use App\Game\Core\Player\PlanetQueries;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Statbuilder;
use App\Game\Model\Planet;
use App\Game\Model\User;

/** Empire overview: resources, buildings, research and fleet of all planets (or moons) plus score charts. */
#[\App\Controller\RequireModule('MODULE_IMPERIUM')]
class EmpireController extends AbstractGameController
{
    /**
     * Empire overview: resources, buildings, research, ships and defense of all planets (or all moons) of the player in one table. Locks
     * the user row and recalculates every planet first so the numbers are current. Request: planet type switch (planets/moons).
     */
    #[GameRoute('game', 'game/empire', 'empire')]
    public function show(): Response
    {
        $PLANETS = $this->recalculatePlanets();

        $planetList = [];
        $config = $this->configs->get($this->state->USER['universe']);

        [$researchid, $researchlvl, $planetid] = $this->researchQueueHead();
        $researchQueue = [
            'researchq_id'      => $researchid,
            'researchq_lvl'     => $researchlvl,
            'researchq_planet'  => $planetid,
        ];

        $sumBuildings = [];
        foreach ($this->elements()->ids('build') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $sumBuildings[1][$elementID]['sum'] = 0;
            $sumBuildings[1][$elementID]['cnt'] = 0;
            $sumBuildings[3][$elementID]['sum'] = 0;
            $sumBuildings[3][$elementID]['cnt'] = 0;
        }

        $statbuidler = new Statbuilder($this->db, $this->configs, $this->state);

        $ownFleets = $this->db->select(
            "SELECT `ships`, `resource_metal`, `resource_crystal`, `resource_deuterium`, `start_id`, `end_id`, `mission`, `hasCanceled` FROM %%FLEETS%%
                WHERE `owner` = :userID;",
            [':userID' => $this->state->USER['id']],
        );

        // read-only overview: the player snapshot is shared, every planet gets its own typed snapshot
        $userModel = $this->models()->user($this->state->USER);
        foreach ($PLANETS as $Planet) {
            $planetModel = $this->models()->planet($Planet);
            $this->addPlanetBasics($planetList, $Planet, $planetModel, $statbuidler, $config);
            $this->addBuildingColumns($planetList, $sumBuildings, $Planet, $userModel, $planetModel);
            $this->addShipColumns($planetList, $Planet, $userModel, $planetModel);
            $this->addFlyingFleets($planetList, $Planet, $ownFleets);

            if (!empty($Planet['b_hangar_id'])) {
                foreach (StoredData::decode($Planet['b_hangar_id']) as $que) {
                    $planetList['currentShipyard'][$que[0]][$Planet['id']] += $que[1];
                }
            }

            $this->addTechColumns($planetList, $Planet, $userModel, $planetModel);
        }

        $avgBuildings = $this->averageBuildings($sumBuildings);

        $currentPlanetCount = $this->planetPositions()->currentPlanetCount($this->state->USER);
        $maxPlanetCount = $this->planetPositions()->maxPlanetCount($this->state->USER);
        $enablePlanetStyles = $this->state->USER['enable_planet_styles'];
        $chartPoints = $this->playerCharts()->getChartPoints($this->state->USER['id'], $this->state->USER, TIME_1_WEEK * 2);

        $this->addScript('empire.js');

        return $this->render('game/page.empire.default.html.twig', $researchQueue + [
            'colspan'                       => count($PLANETS) + 2,
            'chartPoints'                   => $chartPoints,
            'empireList'                    => $planetList,
            'avgBuildings'                  => $avgBuildings,
            'currentPlanetCount'            => $currentPlanetCount,
            'maxPlanetCount'                => $maxPlanetCount,
            'enablePlanetStyles'            => $enablePlanetStyles,
            'enableShowProdPerHour'         => $this->state->requestCookies['empire_showProdPerHour'] ?? true,
            'enableShowStorageFilled'       => $this->state->requestCookies['empire_showStorageFilled'] ?? true,
            'enableHiglightBuildable'       => $this->state->requestCookies['empire_highlightBuildable'] ?? false,
            'enableHiglightNonBuildable'    => $this->state->requestCookies['empire_highlightNonBuildable'] ?? false,
        ]);
    }

    /**
     * Locks the user row and all planet rows of the player (FOR UPDATE, in a transaction) and brings every planet up to
     * date with ResourceUpdate::CalcResource(). The USER state is refreshed from the locked row first because the
     * page-start copy is stale under concurrent requests and the recalculation writes the b_tech* fields back.
     *
     * @return list<array<string, mixed>> the recalculated planet rows in the player's sort order
     */
    private function recalculatePlanets(): array
    {
        $db = $this->db;
        $db->startTransaction();
        $u = $db->selectSingle("SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE u.id = :userID FOR UPDATE;", [':userID' => $this->state->USER['id']]);
        // Calc from the locked user row. The page-start copy is stale under
        // concurrent requests and SavePlanetToDB writes its b_tech* fields back.
        if (!empty($u)) {
            $this->state->USER = array_merge($this->state->USER, $u);
        }
        $order = $this->state->USER['planet_sort_order'] == 1 ? 'DESC' : 'ASC';

        // locks the planet rows for the recalculation below
        $planetQuery = $db->qb()
            ->select('*')
            ->from($db->table('PLANETS'))
            ->where('id_owner = :userID')
            ->andWhere("destruyed = '0'")
            ->setParameter('userID', $this->state->USER['id'])
            ->forUpdate();
        PlanetQueries::orderBySetting($planetQuery, $this->state->USER['planet_sort'], $order);
        $PlanetsRAW = $db->fetchAll($planetQuery);

        $PLANETS = [];

        $PlanetRess = new ResourceUpdate($this->db, $this->configs, $this->state);

        foreach ($PlanetsRAW as $CPLANET) {
            [$this->state->USER, $CPLANET] = $PlanetRess->CalcResource($this->state->USER, $CPLANET, true);

            $PLANETS[] = $CPLANET;
            unset($CPLANET);
        }

        $db->commit();

        return $PLANETS;
    }

    /**
     * The first entry of the player's research queue: tech id, target level and the planet it runs on (all -1 when idle).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function researchQueueHead(): array
    {
        if (!empty($this->state->USER['b_tech_queue'])) {
            $researchque = StoredData::decode($this->state->USER['b_tech_queue']);

            return [$researchque[0][0], $researchque[0][1], $researchque[0][4]];
        }

        return [-1, -1, -1];
    }

    /**
     * Whether $elementID can be built/researched on this planet right now and is affordable ($level = the level to build).
     */
    private function isBuildableNow(User $userModel, Planet $planetModel, int $elementID, int $level): bool
    {
        if (!$this->buildFunctions()->isAccessibleFor($userModel, $planetModel, $elementID)) {
            return false;
        }
        $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $elementID, false, $level);

        return $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $elementID, $costResources);
    }

    /**
     * Whole hours until a storage of capacity $max holding $stock is full at $perHour; 0 when it already is, null
     * ("never") when the production is zero or negative.
     */
    private function hoursUntilFull(float|int $max, float|int $stock, float|int $perHour): ?float
    {
        if ($perHour <= 0) {
            return null;
        }

        return floor(max($max - $stock, 0) / $perHour);
    }

    /**
     * Adds the planet's identity, fields, temperature, resources, storage-full times, production and the running
     * building to the template columns.
     *
     * @param array<string, mixed> $planetList template matrix (column => planet id => value), extended in place
     * @param array<string, mixed> $Planet recalculated planet row
     */
    private function addPlanetBasics(array &$planetList, array $Planet, Planet $planetModel, Statbuilder $statbuidler, object $config): void
    {
        $planetList['points'][$Planet['id']] = $statbuidler->getBuildingScoreByPlanet($Planet)['points'];
        $planetList['image'][$Planet['id']]['id'] = $Planet['id'];
        $planetList['name'][$Planet['id']] = $Planet['name'];
        $planetList['image'][$Planet['id']]['picture'] = $Planet['image'];
        $planetList['image'][$Planet['id']]['planet_style'] = $Planet['planet_style'];

        $planetList['coords'][$Planet['id']]['galaxy'] = $Planet['galaxy'];
        $planetList['coords'][$Planet['id']]['system'] = $Planet['system'];
        $planetList['coords'][$Planet['id']]['planet'] = $Planet['planet'];

        $planetList['field'][$Planet['id']]['current'] = $Planet['field_current'];
        $planetList['field'][$Planet['id']]['max'] = $this->planetQueries()->maxFieldsFor($planetModel);

        $planetList['temp'][$Planet['id']]['min'] = $Planet['temp_min'];
        $planetList['temp'][$Planet['id']]['max'] = $Planet['temp_max'];

        $planetList['energy_used'][$Planet['id']] = $Planet['energy'] + $Planet['energy_used'];

        $planetList['resource'][RESOURCE_METAL][$Planet['id']] = $Planet['metal'];
        $planetList['resource'][RESOURCE_CRYSTAL][$Planet['id']] = $Planet['crystal'];
        $planetList['resource'][RESOURCE_DEUT][$Planet['id']] = $Planet['deuterium'];
        $planetList['resource'][RESOURCE_ENERGY][$Planet['id']] = $Planet['energy'] + $Planet['energy_used'];

        // basic income only exists on planets, moons have none
        if ($Planet['planet_type'] == PlanetType::Planet->value) {
            $basic[RESOURCE_METAL] = $config->metal_basic_income * ($config->resource_multiplier / 2500);
            $basic[RESOURCE_CRYSTAL] = $config->crystal_basic_income * ($config->resource_multiplier / 2500);
            $basic[RESOURCE_DEUT] = $config->deuterium_basic_income * ($config->resource_multiplier / 2500);
            $planetList['resourceFull'][RESOURCE_METAL][$Planet['id']] = $this->hoursUntilFull($Planet['metal_max'], $Planet['metal'], $Planet['metal_perhour'] + $basic[RESOURCE_METAL]);
            $planetList['resourceFull'][RESOURCE_CRYSTAL][$Planet['id']] = $this->hoursUntilFull($Planet['crystal_max'], $Planet['crystal'], $Planet['crystal_perhour'] + $basic[RESOURCE_CRYSTAL]);
            $planetList['resourceFull'][RESOURCE_DEUT][$Planet['id']] = $this->hoursUntilFull($Planet['deuterium_max'], $Planet['deuterium'], $basic[RESOURCE_DEUT] + $Planet['deuterium_perhour']);
        } else {
            $basic[RESOURCE_METAL] = 0;
            $basic[RESOURCE_CRYSTAL] = 0;
            $basic[RESOURCE_DEUT] = 0;
        }
        if (!empty($Planet['b_building_id'])) {
            $Queue = StoredData::decode($Planet['b_building_id']);
            $planetList['currentBuilding'][$Planet['id']] = $Queue[0][0];
            $planetList['currentBuildinglvl'][$Planet['id']] = $Queue[0][1];
        } else {
            $planetList['currentBuilding'][$Planet['id']] = -1;
            $planetList['currentBuildinglvl'][$Planet['id']] = -1;
        }

        $planetList['resourcePerHour'][RESOURCE_METAL][$Planet['id']] = $basic[RESOURCE_METAL] + $Planet['metal_perhour'];
        $planetList['resourcePerHour'][RESOURCE_CRYSTAL][$Planet['id']] = $basic[RESOURCE_CRYSTAL] + $Planet['crystal_perhour'];
        $planetList['resourcePerHour'][RESOURCE_DEUT][$Planet['id']] = $basic[RESOURCE_DEUT] + $Planet['deuterium_perhour'];

        $planetList['resourceProductionPercent'][RESOURCE_METAL][$Planet['id']] = $Planet['metal_mine_porcent'];
        $planetList['resourceProductionPercent'][RESOURCE_CRYSTAL][$Planet['id']] = $Planet['crystal_mine_porcent'];
        $planetList['resourceProductionPercent'][RESOURCE_DEUT][$Planet['id']] = $Planet['deuterium_sintetizer_porcent'];

        $planetList['planet_type'][$Planet['id']] = $Planet['planet_type'];
    }

    /**
     * Adds the building levels and "buildable now" flags of the planet and accumulates the level sums/counts per planet
     * type that averageBuildings() turns into the average column.
     *
     * @param array<string, mixed> $planetList template matrix, extended in place
     * @param array<int, array<int, array{sum: int|float, cnt: int}>> $sumBuildings planet type => building id => sum and count of built levels, extended in place
     * @param array<string, mixed> $Planet recalculated planet row
     */
    private function addBuildingColumns(array &$planetList, array &$sumBuildings, array $Planet, User $userModel, Planet $planetModel): void
    {
        foreach ($this->elements()->ids('build') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $column = $this->elements()->column($elementID);
            $planetType = (int) $Planet['planet_type'];
            $planetList['build'][$elementID][$Planet['id']] = $Planet[$column];

            if ($Planet[$column] > 0 && in_array($elementID, $this->elements()->allowedOn($planetType))) {
                $total = $sumBuildings[$planetType][$elementID];
                $sumBuildings[$planetType][$elementID] = [
                    'sum' => $total['sum'] + $Planet[$column],
                    'cnt' => $total['cnt'] + 1,
                ];
            }

            $planetList['buildable'][$elementID][$Planet['id']] = $this->isBuildableNow($userModel, $planetModel, $elementID, $Planet[$column] + 1);
        }
    }

    /**
     * Adds the ship, defense and missile counts of the planet with their "buildable now" flags (one unit) and the zeroed
     * counters for units in the shipyard queue and in flight that addFlyingFleets() and the hangar queue fill afterwards.
     *
     * @param array<string, mixed> $planetList template matrix, extended in place
     * @param array<string, mixed> $Planet recalculated planet row
     */
    private function addShipColumns(array &$planetList, array $Planet, User $userModel, Planet $planetModel): void
    {

        foreach ($this->elements()->ids('fleet') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $planetList['fleet'][$elementID][$Planet['id']] = $Planet[$this->elements()->column($elementID)];
            $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;
            $planetList['currentFlying'][$elementID][$Planet['id']] = 0;

            $planetList['buildable'][$elementID][$Planet['id']] = $this->isBuildableNow($userModel, $planetModel, $elementID, 1);
        }

        foreach ($this->elements()->ids('defense') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $planetList['defense'][$elementID][$Planet['id']] = $Planet[$this->elements()->column($elementID)];
            $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;

            $planetList['buildable'][$elementID][$Planet['id']] = $this->isBuildableNow($userModel, $planetModel, $elementID, 1);
        }

        foreach ($this->elements()->ids('missile') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            $planetList['missiles'][$elementID][$Planet['id']] = $Planet[$this->elements()->column($elementID)];
            $planetList['currentShipyard'][$elementID][$Planet['id']] = 0;
            $planetList['currentFlying'][$elementID][$Planet['id']] = 0;

            $planetList['buildable'][$elementID][$Planet['id']] = $this->isBuildableNow($userModel, $planetModel, $elementID, 1);
        }
    }

    /**
     * Adds the ships and resources of the player's own fleets that belong to this planet (flying ships per type, resources on the way).
     *
     * Special case 'MISSION_STATION': selected for the right 'start_id'/'end_id' depending on the canceled status.
     * Special case 'MISSION_TRANSPORT': ships return only to 'start_id', resources return to 'end_id' except when canceled.
     * Others: ships and resources return to 'start_id'.
     *
     * @param array<string, mixed> $planetList template matrix, extended in place
     * @param array<string, mixed> $Planet recalculated planet row
     * @param list<array<string, mixed>> $ownFleets all fleets of the user (loaded once)
     */
    private function addFlyingFleets(array &$planetList, array $Planet, array $ownFleets): void
    {
        // (all fleets of the user are loaded once, the conditions are applied per planet here)
        $fleets = array_filter($ownFleets, static fn(array $fleet): bool => (
            $fleet['start_id'] == $Planet['id'] && ($fleet['mission'] != Mission::Station->value || $fleet['hasCanceled'] == 1)
        ) || (
            $fleet['end_id'] == $Planet['id']
            && ($fleet['mission'] == Mission::Station->value || $fleet['mission'] == Mission::Transport->value)
            && $fleet['hasCanceled'] == 0
        ));

        $planetList['currentTransported'][RESOURCE_METAL][$Planet['id']] = 0;
        $planetList['currentTransported'][RESOURCE_CRYSTAL][$Planet['id']] = 0;
        $planetList['currentTransported'][RESOURCE_DEUT][$Planet['id']] = 0;

        foreach ($fleets as $fleet) {
            // Special case 'MISSION_TRANSPORT': ships return only to 'start_id'
            if ($fleet['mission'] != Mission::Transport->value || $fleet['start_id'] == $Planet['id']) {
                $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
                foreach ($fleetArray as $elementID => $amount) {
                    $planetList['currentFlying'][$elementID][$Planet['id']] += $amount;
                }
            }

            // Special case 'MISSION_TRANSPORT': ress return to 'end_id' except if canceled
            if ($fleet['mission'] != Mission::Transport->value
                || ($fleet['start_id'] == $Planet['id'] && $fleet['hasCanceled'])
                || ($fleet['end_id'] == $Planet['id'] && !$fleet['hasCanceled'])
            ) {
                $planetList['currentTransported'][RESOURCE_METAL][$Planet['id']] += $fleet['resource_metal'];
                $planetList['currentTransported'][RESOURCE_CRYSTAL][$Planet['id']] += $fleet['resource_crystal'];
                $planetList['currentTransported'][RESOURCE_DEUT][$Planet['id']] += $fleet['resource_deuterium'];
            }
        }
    }

    /**
     * Adds the research levels per planet: the player's level where the planet can research it, 0 where only the research
     * lab is missing a prerequisite but exists, -1 otherwise; plus the "buildable now" flag.
     *
     * @param array<string, mixed> $planetList template matrix, extended in place
     * @param array<string, mixed> $Planet recalculated planet row
     */
    private function addTechColumns(array &$planetList, array $Planet, User $userModel, Planet $planetModel): void
    {
        foreach ($this->elements()->ids('tech') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }

            $planetList['tech'][$elementID][$Planet['id']] = -1;
            $planetList['buildable'][$elementID][$Planet['id']] = false;
            if ($this->buildFunctions()->isAccessibleFor($userModel, $planetModel, $elementID)) {
                $planetList['tech'][$elementID][$Planet['id']] = $this->state->USER[$this->elements()->column($elementID)];
                $levelToBuild = $this->state->USER[$this->elements()->column($elementID)] + 1;
                $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $elementID, false, $levelToBuild);
                $planetList['buildable'][$elementID][$Planet['id']] = $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $elementID, $costResources);
            } elseif ($Planet[$this->elements()->column(RESEARCH_LABORATORY)] > 0) {
                $planetList['tech'][$elementID][$Planet['id']] = 0;
            }
        }
    }

    /**
     * Average built level per building over the planets/moons that can hold it (planets and moons together when both
     * can, otherwise the one that can; 0 when none has it).
     *
     * @param array<int, array<int, array{sum: int|float, cnt: int}>> $sumBuildings planet type => building id => sum and count
     * @return array<int, int|float> building id => average level
     */
    private function averageBuildings(array $sumBuildings): array
    {
        $avgBuildings = [];
        foreach ($this->elements()->ids('build') as $elementID) {
            if (!$this->buildFunctions()->isEnabled($elementID)) {
                continue;
            }
            if (in_array($elementID, $this->elements()->allowedOn(1)) && in_array($elementID, $this->elements()->allowedOn(3))
                    && ($sumBuildings[1][$elementID]['cnt'] + $sumBuildings[3][$elementID]['cnt']) > 0) {
                $avgBuildings[$elementID] = ($sumBuildings[1][$elementID]['sum'] + $sumBuildings[3][$elementID]['sum'])
                    / ($sumBuildings[1][$elementID]['cnt'] + $sumBuildings[3][$elementID]['cnt']);
            } elseif (in_array($elementID, $this->elements()->allowedOn(1)) && $sumBuildings[1][$elementID]['cnt'] > 0) {
                $avgBuildings[$elementID] = $sumBuildings[1][$elementID]['sum'] / $sumBuildings[1][$elementID]['cnt'];
            } elseif ($sumBuildings[3][$elementID]['cnt'] > 0) {
                $avgBuildings[$elementID] = $sumBuildings[3][$elementID]['sum'] / $sumBuildings[3][$elementID]['cnt'];
            } else {
                $avgBuildings[$elementID] = 0;
            }
        }

        return $avgBuildings;
    }
}
