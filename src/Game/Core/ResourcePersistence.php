<?php

namespace App\Game\Core;

use App\Game\Enum\PlanetType;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

/**
 * Writes planet and user economy state to the database: the full planet save after an update run (with built
 * elements, achievements and advanced stats) and the small targeted updates (resources, queues).
 *
 * Collaborators: Database, AdvancedStatsHandler, PlanetRessAchievement (via GameUtilities), Singleton (resource tables).
 * Stateless: ResourceUpdate passes the accumulated built/repaired deltas in.
 */
class ResourcePersistence
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Persists planet + user in one UPDATE (eco hash, last update, queues, production, storage, energy, built
     * building levels / ships / researched levels) and then records achievements and advanced stats for what was built.
     *
     * Rules: buildings (id < 100) and researches are written as absolute levels, ships/defense/resources (id >= 100)
     * as increments (resources 900-999 with 6 decimals); "one"-per-planet elements are set to 1. Repaired ships are
     * subtracted from the "built" stat and counted as "repaired" instead.
     * Side effects: DB write on planets+users, advanced stats rows, achievement checks (may grant achievements).
     *
     * @param array<string, mixed> $USER
     * @param array<string, mixed> $PLANET
     * @param array<int|string, float|int> $builded delta/level per element id collected during the update
     * @param array<int|string, float|int> $repaired part of $builded that came from a finished repair job
     * @param int $time timestamp used for the "first" stats
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [$USER, $PLANET] unchanged
     */
    public function savePlanet(array $USER, array $PLANET, array $builded, array $repaired, int|float|null $time): array
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $params = $this->baseParams($USER, $PLANET);
        $changes = $this->collectElementChanges($USER, $PLANET, $builded, $repaired, $time, $params);

        $this->db->update($this->buildUpdateSql($changes['columns']), $params);

        foreach ($changes['statsBuild'] as [$enum, $count]) {
            $statsHandler->increment($USER['id'], $enum, $count);
        }
        foreach ($changes['statsRepaired'] as [$enum, $count]) {
            $statsHandler->increment($USER['id'], $enum, $count);
        }
        foreach ($changes['statsFirst'] as [$enum, $firstTime]) {
            $statsHandler->setTimestamp($USER['id'], $enum, $firstTime);
        }

        return [$USER, $PLANET];
    }

    /**
     * Bound parameters of the fixed part of the planet save (queues, production, storage, energy, eco hash).
     * field_current is recounted from the building levels when the planet has any.
     *
     * @param array<string, mixed> $USER
     * @param array<string, mixed> $PLANET
     * @return array<string, mixed>
     */
    private function baseParams(array $USER, array $PLANET): array
    {
        $elements = $this->elements();

        $currentBuildings = 0;
        foreach ($elements->ids('build') as $Element) {
            if (isset($PLANET[$elements->column($Element)])) {
                $currentBuildings += $PLANET[$elements->column($Element)];
            }
        }

        return [
            ':userId'               => $USER['id'],
            ':planetId'             => $PLANET['id'],
            ':ecoHash'              => $PLANET['eco_hash'],
            ':lastUpdateTime'       => $PLANET['last_update'],
            ':b_building'           => $PLANET['b_building'],
            ':b_building_id'        => $PLANET['b_building_id'],
            ':field_current'        => $currentBuildings > 0 ? $currentBuildings : $PLANET['field_current'],
            ':b_hangar_id'          => $PLANET['b_hangar_id'],
            ':metal_perhour'        => $PLANET['metal_perhour'],
            ':crystal_perhour'      => $PLANET['crystal_perhour'],
            ':deuterium_perhour'    => $PLANET['deuterium_perhour'],
            ':metal_max'            => $PLANET['metal_max'],
            ':crystal_max'          => $PLANET['crystal_max'],
            ':deuterium_max'        => $PLANET['deuterium_max'],
            ':energy_used'          => $PLANET['energy_used'],
            ':energy'               => $PLANET['energy'],
            ':b_hangar'             => $PLANET['b_hangar'],
            ':b_tech'               => $USER['b_tech'],
            ':b_tech_id'            => $USER['b_tech_id'],
            ':b_tech_planet'        => $USER['b_tech_planet'],
            ':b_tech_queue'         => $USER['b_tech_queue'],
        ];
    }

    /**
     * Turns the collected built elements into extra SET columns + bound parameters (added to $params by reference)
     * and into the stats rows to write afterwards. Calls the planet/ship/research achievement checks on the way.
     *
     * @param array<string, mixed> $USER
     * @param array<string, mixed> $PLANET
     * @param array<int|string, float|int> $builded
     * @param array<int|string, float|int> $repaired
     * @param array<string, mixed> $params
     * @return array{columns: list<string>, statsBuild: list<array>, statsRepaired: list<array>, statsFirst: list<array>}
     */
    private function collectElementChanges(array $USER, array $PLANET, array $builded, array $repaired, int|float|null $time, array &$params): array
    {
        $elements = $this->elements();

        $buildQueries = [];
        $statsUpdateBuild = [];
        $statsUpdateRepaired = [];
        $statsUpdateFirst = [];

        if (!empty($builded)) {
            foreach ($builded as $Element => $Count) {
                $Element = (int) $Element;

                $column = $elements->find($Element)?->column;
                if (empty($column)) {
                    continue;
                }

                if ($elements->isOnePerPlanet($Element)) {
                    $buildQueries[] = ', p.' . $column . ' = :' . $column;
                    $params[':' . $column] = '1';
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $time];
                    $statsUpdateBuild[] = [AdvancedStatsEnum::getBuild($Element), '1'];
                } elseif (isset($PLANET[$column])) {
                    if ($Element < 100) { // Set building level directly
                        $buildQueries[] = ', p.' . $column . ' = :' . $column;
                        $params[':' . $column] = floatToString($Count);

                        if ($PLANET['planet_type'] == PlanetType::Moon->value && ($Element == 22 || $Element == 23 || $Element == 24) && $Count == 3) {
                            $temp_planet = $PLANET;
                            $temp_planet[$column] = $Count;
                            //  Check for achievement 28: Build all Moon storages to level 3
                            $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkPlanetRessAchievements28($temp_planet, $elements->columnMap(), $USER['id']));
                        }
                    } else {
                        $buildQueries[] = ', p.' . $column . ' = p.' . $column . ' + :' . $column;
                        if ($Element >= 900 && $Element < 1000) {
                            $params[':' . $column] = floatToString($Count, 6);
                        } else {
                            $params[':' . $column] = floatToString($Count);
                        }
                    }
                    if ($Element < 100) {
                        $this->checkPlanetBuildingAchievements($USER, $PLANET, $Element, $Count);
                    }
                    // Check for ship achievements
                    if ($Element >= 200 && $Element < 300) {
                        $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkPlanetRessAchievementsShips($Element, $USER['id']));
                    }
                    // Update Builded stats for ships, def and rockets
                    if ($Element >= 200 && $Element < 900) {
                        $toAdd = $Count;
                        if (array_key_exists($Element, $repaired)) {
                            $toAdd -= $repaired[$Element];
                            $statsUpdateRepaired[] = [AdvancedStatsEnum::getRepaired($Element), floatToString($repaired[$Element])];
                        }
                        $statsUpdateBuild[] = [AdvancedStatsEnum::getBuild($Element), floatToString($toAdd)];
                    }
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $time];
                } elseif (isset($USER[$column])) { // Set research level directly
                    $buildQueries[] = ', u.' . $column . ' = :' . $column;
                    $params[':' . $column] = floatToString($Count);

                    // Check for research achievements
                    $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkPlanetRessAchievementsResearch($Element, $USER['id'], $Count, $USER));
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $time];
                }
            }
        }

        return [
            'columns' => $buildQueries,
            'statsBuild' => $statsUpdateBuild,
            'statsRepaired' => $statsUpdateRepaired,
            'statsFirst' => $statsUpdateFirst,
        ];
    }

    /**
     * Mining empire (64) and Colonial overlord (95) after a building level was set on a planet (not written yet).
     *
     * @param array<string, mixed> $USER
     * @param array<string, mixed> $PLANET
     */
    private function checkPlanetBuildingAchievements(array $USER, array $PLANET, int $element, float|int $level): void
    {
        if ($PLANET['planet_type'] != PlanetType::Planet->value) {
            return;
        }
        $PLANET[$this->elements()->column($element)] = $level;
        if ($element == metal_mine && $level >= 30) {
            $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkMiningEmpire($USER['id'], $PLANET['id']));
        }
        $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkColonialOverlord($USER['id'], $USER, $PLANET));
    }

    /**
     * The planet+user UPDATE; $columns are the extra ", p.x = :x" / ", u.x = :x" assignments of built elements.
     *
     * @param list<string> $columns
     */
    private function buildUpdateSql(array $columns): string
    {
        return 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
		p.eco_hash = :ecoHash,
		p.last_update = :lastUpdateTime,
		p.b_building = :b_building,
		p.b_building_id = :b_building_id,
		p.field_current = :field_current,
		p.b_hangar_id = :b_hangar_id,
		p.metal_perhour = :metal_perhour,
		p.crystal_perhour = :crystal_perhour,
		p.deuterium_perhour = :deuterium_perhour,
		p.metal_max = :metal_max,
		p.crystal_max = :crystal_max,
		p.deuterium_max = :deuterium_max,
		p.energy_used = :energy_used,
		p.energy = :energy,
		p.b_hangar = :b_hangar,
		u.b_tech = :b_tech,
		u.b_tech_id = :b_tech_id,
		u.b_tech_planet = :b_tech_planet,
		u.b_tech_queue = :b_tech_queue
		' . implode("\n", $columns) . '
		WHERE p.id = :planetId AND u.id = :userId;';
    }

    /**
     * Writes planets.low_energy_since (the start of a continuous energy deficit, 0 = none); only called when the value flips.
     */
    public function saveLowEnergySince(int $planetId, int $since): void
    {
        $this->db->update('UPDATE %%PLANETS%% SET low_energy_since = :since WHERE id = :planetId;', [
            ':since' => $since,
            ':planetId' => $planetId,
        ]);
    }

    /**
     * Debits resources from a planet in the DB (relative UPDATE `col = col - x`), skipping energy, and mirrors the change
     * in the passed planet array. Callers check affordability beforehand; the stock can go negative here.
     * @param int $planetId
     * @param array<int, int|float> $resources resource id (901-903, 921) => amount to remove.
     * @param array<string, mixed>|null $planet optional planet row, updated in place.
     */
    public function removeResources(int $planetId, array $resources, ?array &$planet = null)
    {
        $elements = $this->elements();

        $params = [':planetId' => $planetId];
        $planetQuery = [];
        foreach ($resources as $resourceId => $amount) {
            if ($resourceId == RESOURCE_ENERGY) {
                continue;
            }
            $planetQuery[] = $elements->column($resourceId) . " = " . $elements->column($resourceId) . " - :" . $elements->column($resourceId);
            $params[':' . $elements->column($resourceId)] = $amount;

            if (isset($planet, $planet[$elements->column($resourceId)])) {
                $planet[$elements->column($resourceId)] -= $amount;
            }
        }

        if (!empty($planetQuery)) {
            $sql = 'UPDATE %%PLANETS%% SET '
            . implode(', ', $planetQuery)
            . ' WHERE id = :planetId;';
            $this->db->update($sql, $params);
        }
    }

    /**
     * Credits resources to a planet in the DB (relative UPDATE `col = col + x*factor`), skipping energy, and mirrors
     * the change in the passed planet array.
     * @param array<int, int|float> $resources resource id => amount.
     * @param float $factor multiplier applied to every amount (e.g. a refund share such as 0.5).
     * @param array<string, mixed> $planet
     */
    public function addResources(int $planetId, array $resources, ?array &$planet = null, float $factor = 1)
    {
        $elements = $this->elements();

        $params = [':planetId' => $planetId];
        $planetQuery = [];
        foreach ($resources as $resourceId => $amount) {
            if ($resourceId == RESOURCE_ENERGY) {
                continue;
            }
            $planetQuery[] = $elements->column($resourceId) . " = " . $elements->column($resourceId) . " + :" . $elements->column($resourceId);
            $params[':' . $elements->column($resourceId)] = $amount * $factor;

            if (isset($planet, $planet[$elements->column($resourceId)])) {
                $planet[$elements->column($resourceId)] += $amount * $factor;
            }
        }

        $sql = 'UPDATE %%PLANETS%% SET '
        . implode(', ', $planetQuery)
        . ' WHERE id = :planetId;';
        $this->db->update($sql, $params);
    }

    /**
     * Writes only the metal, crystal and deuterium stocks of the planet row (absolute values, no queue or production data).
     *
     * @param array<string, mixed> $PLANET
     */
    public function saveResources(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':metal'                => $PLANET['metal'],
            ':crystal'              => $PLANET['crystal'],
            ':deuterium'            => $PLANET['deuterium'],
        ];
        $sql = 'UPDATE %%PLANETS%% SET
		metal = :metal,
		crystal = :crystal,
		deuterium = :deuterium
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }

    /**
     * Writes the user's research queue columns (b_tech end time, b_tech_id, b_tech_planet, b_tech_queue) to `users`.
     *
     * @param array<string, mixed> $USER
     */
    public function saveTechQueue(array $USER)
    {
        $params = [
            ':userId'        => $USER['id'],
            ':b_tech'        => $USER['b_tech'],
            ':b_tech_id'     => $USER['b_tech_id'],
            ':b_tech_planet' => $USER['b_tech_planet'],
            ':b_tech_queue'  => $USER['b_tech_queue'],
        ];
        $sql = 'UPDATE %%USERS%% SET
		b_tech = :b_tech,
		b_tech_id = :b_tech_id,
		b_tech_planet = :b_tech_planet,
		b_tech_queue = :b_tech_queue
		WHERE id = :userId;';

        $this->db->update($sql, $params);
    }

    /**
     * Writes the planet's building queue columns (b_building end time, b_building_id, watch_id) to `planets`
     * (method name keeps its historic typo).
     *
     * @param array<string, mixed> $PLANET
     */
    public function saveBuilingQueue(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':b_building'           => $PLANET['b_building'],
            ':b_building_id'        => $PLANET['b_building_id'],
            ':watch_id'             => $PLANET['watch_id'],
        ];
        $sql = 'UPDATE %%PLANETS%% SET
        b_building = :b_building,
		b_building_id = :b_building_id,
        watch_id = :watch_id
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }

    /**
     * Writes the planet's shipyard queue columns (b_hangar_id, b_hangar progress time) to `planets`.
     *
     * @param array<string, mixed> $PLANET
     */
    public function saveShipyardQueue(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':b_hangar_id'          => $PLANET['b_hangar_id'],
            ':b_hangar'             => $PLANET['b_hangar'],
        ];
        $sql = 'UPDATE %%PLANETS%% SET
        b_hangar_id = :b_hangar_id,
		b_hangar = :b_hangar
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }
}
