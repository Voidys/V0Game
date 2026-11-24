<?php

namespace App\Game\Bots;

use App\Game\Enum\Mission;
use App\Game\Core\Config;
use App\Game\Core\StoredData;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;

/**
 * Holds the game state of one bot (user + planets, locked inside the caller's
 * transaction) and executes actions with exactly the rules a human player has
 * (same price/requirement/queue/field checks as the building, research and
 * shipyard pages).
 */
class BotActions
{
    use GameUtilities;

    /** @var array<string,mixed> */
    public array $user = [];
    /** @var array<int,array<string,mixed>> planets (planet_type 1) keyed by id */
    public array $planets = [];
    public Config $config;
    public ResourceUpdate $eco;
    public int $universe;
    /** Fleet slots kept free for expeditions (profile `expedition_slots`), applied by freeFleetSlots() for every other flight. */
    public int $reservedSlots = 0;

    public function __construct(
        int $universe,
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotPersona $persona,
    ) {
        $this->universe = $universe;
        $this->config = $configs->get($universe);
        $this->eco = $this->resourceUpdate();
    }

    /**
     * Locks and loads user + planets (user first, then planets: same order as
     * the game pages) and brings resources/queues up to date. Must run inside
     * a transaction.
     */
    public function load(int $userId): bool
    {
        $db = $this->db;

        $user = $db->selectSingle(
            'SELECT u.*, us.*, s.total_points
            FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
            LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1
            WHERE u.id = :id FOR UPDATE;',
            [':id' => $userId],
        );
        if (empty($user)) {
            return false;
        }

        $planets = $db->select(
            'SELECT * FROM %%PLANETS%% WHERE id_owner = :id AND destruyed = 0 AND planet_type = 1 ORDER BY id FOR UPDATE;',
            [':id' => $userId],
        );

        $this->planets = [];
        foreach ($planets as $planet) {
            [$user, $planet] = $this->eco->CalcResource($user, $planet, true);
            $planet[$this->elements()->column(RESEARCH_LABORATORY) . '_inter'] = $this->eco->getNetworkLevel($user, $planet);
            $this->planets[(int) $planet['id']] = $planet;
        }
        $this->user = $user;

        return true;
    }

    /**
     * "Name [g:s:p]" label of a planet for log lines.
     *
     * @param array<string, mixed> $planet
     */
    public function planetLabel(array $planet): string
    {
        return $planet['name'] . ' [' . $planet['galaxy'] . ':' . $planet['system'] . ':' . $planet['planet'] . ']';
    }

    /**
     * Element id => column name map (`$resource` from the game data).
     *
     * @return array<mixed>
     */
    public function res(): array
    {
        return $this->elements()->columnMap();
    }

    /**
     * True when no building is under construction on the planet.
     *
     * @param array<string, mixed> $planet
     */
    public function isBuildingQueueEmpty(array $planet): bool
    {
        return empty($planet['b_building_id']) && empty($planet['b_building']);
    }

    /** True when the user has no research running or queued. */
    public function isResearchQueueEmpty(): bool
    {
        return empty($this->user['b_tech_queue']) && empty($this->user['b_tech']);
    }

    /** true while a lab or university is being built on any planet (research blocked). */
    public function labInConstruction(): bool
    {
        foreach ($this->planets as $planet) {
            if (empty($planet['b_building_id'])) {
                continue;
            }
            $queue = StoredData::decode($planet['b_building_id']);
            if (!is_array($queue)) {
                continue;
            }
            foreach ($queue as $item) {
                if ($item[0] == university || $item[0] == RESEARCH_LABORATORY) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true while shipyard or nanite factory is being built on the planet.
     *
     * @param array<string, mixed> $planet
     */
    public function shipyardInConstruction(array $planet): bool
    {
        if (empty($planet['b_building_id'])) {
            return false;
        }
        $queue = StoredData::decode($planet['b_building_id']);
        if (!is_array($queue)) {
            return false;
        }
        foreach ($queue as $item) {
            if ($item[0] == SHIPYARD || $item[0] == NANITE_FACTORY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Number of entries in the planet's shipyard queue.
     *
     * @param array<string, mixed> $planet
     */
    public function hangarCount(array $planet): int
    {
        $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];

        return is_array($queue) ? count($queue) : 0;
    }

    /**
     * Can a building be started on this planet right now (queue empty, fields)?
     *
     * @param array<string, mixed> $planet
     */
    public function canStartBuilding(array $planet): bool
    {
        return $this->isBuildingQueueEmpty($planet)
            && $this->buildFunctions()->canQueueBuildingOnFields($planet, [], true);
    }

    /**
     * Can the element be bought as a building right now, ignoring resources? Returns level to build or null.
     *
     * @param array<string, mixed> $planet
     */
    public function buildableLevel(array $planet, int $element): ?int
    {
        $e = $this->elements();

        if (
            !in_array($element, $e->allowedOn((int) $planet['planet_type']))
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
            || ($element == RESEARCH_LABORATORY && $this->user['b_tech_planet'] != 0)
            || (($element == NANITE_FACTORY || $element == SHIPYARD) && !empty($planet['b_hangar_id']))
        ) {
            return null;
        }

        $level = (int) $planet[$e->column($element)] + 1;
        if (($e->maxLevel($element) ?? 0) < $level) {
            return null;
        }

        return $level;
    }

    /**
     * Starts the next level of a building like the buildings page: needs an empty building queue, free fields, an allowed
     * and accessible element within its max level, and enough resources. Deducts the price, writes the queue with end
     * time and saves it. Returns a log text, or null when any rule rejects it (nothing changed).
     */
    public function build(int $planetId, int $element): ?string
    {
        $e = $this->elements();
        $planet = &$this->planets[$planetId];

        if (!$this->canStartBuilding($planet)) {
            return null;
        }
        $level = $this->buildableLevel($planet, $element);
        if ($level === null) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $level);
        if (!$this->buildFunctions()->isElementBuyable($this->user, $planet, $element, $cost)) {
            return null;
        }

        $this->eco->removeResources($planet['id'], $cost, $planet);

        $time = $this->buildFunctions()->getBuildingTime($this->user, $planet, $element, $cost, false);
        $end = $this->state->runtime->timestamp + $time;
        if ($planet['watch_id'] == $element) {
            $planet['watch_id'] = 0;
        }
        $planet['b_building_id'] = StoredData::encode([[$element, $level, $time, $end, 'build', $cost]]);
        $planet['b_building'] = $end;
        $this->eco->saveBuilingQueue($planet);

        return $e->column($element) . ' ' . $level . ' on ' . $this->planetLabel($planet);
    }

    /**
     * Next research level of `$element` if it may be researched from this planet (listed, enabled, requirements met, below max), else null. Resources are not checked.
     *
     * @param array<string, mixed> $planet
     */
    public function researchLevel(array $planet, int $element): ?int
    {
        $e = $this->elements();

        if (
            !in_array($element, $e->ids('tech'))
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
        ) {
            return null;
        }
        $level = (int) $this->user[$e->column($element)] + 1;
        if (($e->maxLevel($element) ?? 0) < $level) {
            return null;
        }

        return $level;
    }

    /**
     * Starts the next level of a technology like the research page: research queue empty, no lab or university under
     * construction (anywhere), requirements and resources met. Deducts the price on `$planetId`, stores the single-entry research
     * queue and clears the planet's "watch" marker for that element. Returns a log text, or null when rejected.
     */
    public function research(int $planetId, int $element): ?string
    {
        $e = $this->elements();
        $planet = &$this->planets[$planetId];

        if (!$this->isResearchQueueEmpty() || $this->labInConstruction()) {
            return null;
        }
        $level = $this->researchLevel($planet, $element);
        if ($level === null) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $level);
        if (!$this->buildFunctions()->isElementBuyable($this->user, $planet, $element, $cost)) {
            return null;
        }

        $this->eco->removeResources($planet['id'], $cost, $planet);

        $time = $this->buildFunctions()->getBuildingTime($this->user, $planet, $element, $cost);
        $end = $this->state->runtime->timestamp + $time;
        if ($planet['watch_id'] == $element) {
            $planet['watch_id'] = 0;
            $this->db->update(
                'UPDATE %%PLANETS%% SET watch_id = 0 WHERE id = :id;',
                [':id' => $planet['id']],
            );
        }

        $this->user['b_tech_queue'] = StoredData::encode([[$element, $level, $time, $end, $planet['id'], $cost]]);
        $this->user['b_tech'] = $end;
        $this->user['b_tech_id'] = $element;
        $this->user['b_tech_planet'] = $planet['id'];
        $this->eco->saveTechQueue($this->user);

        return $e->column($element) . ' ' . $level . ' on ' . $this->planetLabel($planet);
    }

    /**
     * Can ships/defense be queued on this planet at all (shipyard present, not blocked, queue not full)?
     *
     * @param array<string, mixed> $planet
     */
    public function canQueueUnits(array $planet): bool
    {
        $e = $this->elements();
        if ($planet[$e->column(SHIPYARD)] < 1 || $this->shipyardInConstruction($planet)) {
            return false;
        }
        $max = (int) $this->config->max_elements_ships;

        return $max == 0 || $this->hangarCount($planet) < $max;
    }

    /**
     * Whether the ship or defense `$element` may be built on the planet: enabled, requirements met, and for one-per-planet units (e.g. shields) not yet built or queued.
     *
     * @param array<string, mixed> $planet
     */
    public function unitsAllowed(array $planet, int $element): bool
    {
        $e = $this->elements();

        if (
            !in_array($element, array_merge($e->ids('fleet'), $e->ids('defense')))
            || !$this->buildFunctions()->isEnabled($element)
            || !$this->buildFunctions()->isTechnologieAccessible($this->user, $planet, $element)
        ) {
            return false;
        }
        if (in_array($element, $e->ids('one'))) {
            if ($planet[$e->column($element)] != 0) {
                return false;
            }
            $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];
            foreach ((array) $queue as $item) {
                if ($item[0] == $element) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Queues `$count` ships or defense units like the shipyard page. The count is capped by `max_fleet_per_build`, by what the planet
     * can pay and (one-per-planet units) to 1. Deducts the price, appends to the hangar queue, saves it and checks
     * shipyard achievements. Returns a log text, or null when nothing could be queued.
     */
    public function units(int $planetId, int $element, int $count): ?string
    {
        $e = $this->elements();
        $planet = &$this->planets[$planetId];

        if (!$this->canQueueUnits($planet) || !$this->unitsAllowed($planet, $element)) {
            return null;
        }

        $count = max(0, min($count, (int) $this->config->max_fleet_per_build));
        $count = min($count, (int) $this->buildFunctions()->getMaxConstructibleElements($this->user, $planet, $element));
        if (in_array($element, $e->ids('one'))) {
            $count = min($count, 1);
        }
        if ($count < 1) {
            return null;
        }

        $cost = $this->buildFunctions()->getElementPrice($this->user, $planet, $element, false, $count);

        $queue = !empty($planet['b_hangar_id']) ? StoredData::decode($planet['b_hangar_id']) : [];
        if (!is_array($queue)) {
            $queue = [];
        }
        $queue[] = [$element, $count];
        $planet['b_hangar_id'] = StoredData::encode($queue);

        $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkShipyardAchievements($this->user['id'], $count, $element));
        $this->eco->removeResources($planet['id'], $cost, $planet);
        $this->eco->saveShipyardQueue($planet);

        return $count . ' x ' . $e->column($element) . ' on ' . $this->planetLabel($planet);
    }

    // ------------------------------------------------------------------
    // fleet actions
    // ------------------------------------------------------------------

    /**
     * Free fleet slots: computer technology and bonuses minus the fleets already flying. Other flights than expeditions leave
     * `reservedSlots` free (never more than total - 2, so a bot with few slots can still act).
     */
    public function freeFleetSlots(bool $forExpedition = false): int
    {
        $total = $this->fleetFunctions()->getMaxFleetSlots($this->user);
        $free = $total - $this->fleetFunctions()->getCurrentFleets($this->user['id']);

        return $forExpedition ? $free : $free - min($this->reservedSlots, max(0, $total - 2));
    }

    /**
     * Sends a fleet in the same way FleetDispatch (fleet step 3) does for a mission without cargo, at full speed (speed setting 10 =
     * 100 %). Returns null without changes when the mission is not available for the target, the ships are not on
     * the planet, the stay time is not offered, or the planet lacks deuterium (id 903) for the trip. Otherwise sends it
     * through FleetFunctions::sendFleet() (DB write), keeps the in-memory planet in sync and returns the fleet id.
     *
     * @param array<int, int> $fleetArray ship id => count
     * @param int $stayHours expedition hold time in hours (0 = none)
     * @param array<string, mixed> $planet
     */
    public function dispatch(array &$planet, array $fleetArray, int $mission, int $galaxy, int $system, int $pos, int $stayHours = 0): ?int
    {
        $e = $this->elements();

        $targetPlanetData = ['id' => 0, 'id_owner' => 0];
        $misInfo = [
            'galaxy' => $galaxy,
            'system' => $system,
            'planet' => $pos,
            'planettype' => 1,
            'IsAKS' => 0,
            'Ship' => $fleetArray,
        ];
        $available = $this->fleetFunctions()->getFleetMissions($this->user, $misInfo, $targetPlanetData);
        if (!in_array($mission, $available['MissionSelector'])) {
            return null;
        }
        if ($stayHours > 0 && !isset($available['StayBlock'][$stayHours])) {
            return null;
        }

        foreach ($fleetArray as $ship => $count) {
            if ($count < 1 || $count > $planet[$e->column($ship)]) {
                return null;
            }
        }

        $distance = $this->fleetFunctions()->getTargetDistance(
            [$planet['galaxy'], $planet['system'], $planet['planet']],
            [$galaxy, $system, $pos],
        );
        $maxSpeed = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $this->user);
        $speedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $duration = $this->fleetFunctions()->getMissionDuration(10, $maxSpeed, $distance, $speedFactor, $this->user);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $duration, $distance, $this->user, $speedFactor);
        if ($planet[$e->column(903)] < $consumption) {
            return null;
        }

        $stay = $stayHours > 0 ? (int) round($available['StayBlock'][$stayHours] * 3600) : 0;
        $start = (int) (floor($duration) + $this->state->runtime->timestamp);
        $stayTime = (int) floor($start + $stay);
        $end = (int) floor($stayTime + $duration);

        $id = $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $mission,
            (int) $this->user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            (int) $planet['planet_type'],
            0,
            0,
            $galaxy,
            $system,
            $pos,
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $start,
            $stayTime,
            $end,
            0,
            0,
            0,
            (float) $consumption,
        );

        // keep the in-memory planet in sync with what sendFleet deducted
        foreach ($fleetArray as $ship => $count) {
            $planet[$e->column($ship)] -= $count;
        }
        $planet[$e->column(903)] -= $consumption;

        return $id;
    }

    /** Expedition module on in the universe, a free fleet slot and the expedition limit not reached. */
    public function canSendExpedition(): bool
    {
        return $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_EXPEDITION, $this->universe)
            && $this->freeFleetSlots(true) > 0
            && $this->fleetFunctions()->getCurrentFleets($this->user['id'], Mission::Expedition->value, true) < $this->fleetFunctions()->getExpeditionLimit($this->user);
    }

    /** Colony module on, a free fleet slot, below the maximum planet count and no colonization fleet already flying. */
    public function canColonize(): bool
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_COLONY, $this->universe) || $this->freeFleetSlots() < 1) {
            return false;
        }

        return $this->planetPositions()->currentPlanetCount($this->user) < $this->planetPositions()->maxPlanetCount($this->user)
            && $this->fleetFunctions()->getCurrentFleets($this->user['id'], Mission::Colonisation->value, true) == 0;
    }

    /**
     * Sends a colony ship from the first planet that has one to the best free position near it (see colonyTarget()). Returns a log
     * text, or null when no ship or no target was found.
     */
    public function colonize(): ?string
    {
        $e = $this->elements();
        if (!$this->canColonize()) {
            return null;
        }

        $origin = null;
        foreach ($this->planets as $id => $planet) {
            if ($planet[$e->column(SHIP_COLOSHIP)] >= 1) {
                $origin = $id;
                break;
            }
        }
        if ($origin === null) {
            return null;
        }
        $planet = &$this->planets[$origin];

        $target = $this->colonyTarget($planet);
        if ($target === null) {
            return null;
        }

        $id = $this->dispatch($planet, [SHIP_COLOSHIP => 1], Mission::Colonisation->value, $target[0], $target[1], $target[2]);
        if ($id === null) {
            return null;
        }

        return 'colony ship from ' . $this->planetLabel($planet) . ' to [' . implode(':', $target) . ']';
    }

    /** Planets of a colony system the way experienced players rate positions (size and temperature by position, 8 best, edges worst). */
    private const POSITION_VALUE = [8 => 100, 7 => 92, 9 => 92, 6 => 85, 10 => 85, 5 => 75, 11 => 75, 4 => 65, 12 => 60, 3 => 50, 13 => 45, 2 => 35, 14 => 30, 15 => 25, 1 => 20];

    /**
     * Picks the colony position: free, allowed for the user and not targeted yet, within 3 systems of the origin (10 when nothing is free
     * there). Rated by what a player knows about positions (POSITION_VALUE), minus 3 points per system of distance, plus a little noise;
     * the best of up to 60 sampled positions wins. Only information the galaxy view gives is used (free or not).
     *
     * @param array<string, mixed> $planet origin planet (the one with the colony ship)
     * @return array{0: int, 1: int, 2: int}|null galaxy, system, position
     */
    private function colonyTarget(array $planet): ?array
    {
        $maxSystem = (int) $this->config->max_system;
        $maxPlanets = (int) $this->config->max_planets;
        $best = null;
        $bestScore = -INF;
        for ($try = 0; $try < 60; $try++) {
            $spread = $try < 40 ? 3 : 10;
            $system = (int) $planet['system'] + random_int(-$spread, $spread);
            $pos = random_int(2, max(2, $maxPlanets - 1));
            if (
                $system < 1 || $system > $maxSystem
                || !$this->planetPositions()->checkPosition($this->universe, (int) $planet['galaxy'], $system, $pos)
                || !$this->planetPositions()->isPositionFree($this->universe, (int) $planet['galaxy'], $system, $pos)
                || !$this->planetPositions()->allowPlanetPosition($pos, $this->user)
                || $this->positionTargeted((int) $planet['galaxy'], $system, $pos)
            ) {
                continue;
            }
            $score = (self::POSITION_VALUE[$pos] ?? 20) - 3 * abs($system - (int) $planet['system']) + random_int(0, 8);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [(int) $planet['galaxy'], $system, $pos];
            }
        }

        return $best;
    }

    /** Whether a colonization fleet is already heading for these coordinates. */
    private function positionTargeted(int $galaxy, int $system, int $pos): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) as count FROM %%FLEETS%%
            WHERE universe = :universe AND mission = :mission AND end_galaxy = :g AND end_system = :s AND end_planet = :p;',
            [':universe' => $this->universe, ':mission' => Mission::Colonisation->value, ':g' => $galaxy, ':s' => $system, ':p' => $pos],
            'count',
        ) > 0;
    }

    /**
     * Colonies are created when the colony ship arrives (fleet handler), with the
     * language's default colony name. Give such planets a persona based name.
     *
     * @param array<mixed> $botRow
     * @param array<mixed> $persona
     * @return int number of planets renamed (the home planet is never renamed)
     */
    public function renameDefaultColonies(array $botRow, array $persona): int
    {
        $defaults = $this->defaultColonyNames();
        $renamed = 0;
        foreach ($this->planets as $id => $planet) {
            if ($id == $this->user['id_planet'] || !in_array($planet['name'], $defaults, true)) {
                continue;
            }
            $name = $this->persona->planetName($botRow, $persona, false);
            $this->persona->renamePlanet((int) $id, $name);
            $renamed++;
        }

        return $renamed;
    }

    /** @return string[] the default colony names (translation key `fcp_colony`) of all languages, read once from translations/*.yaml */
    private function defaultColonyNames(): array
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (glob($this->state->runtime->rootPath . 'translations/*.yaml') ?: [] as $file) {
                $content = file_get_contents($file);
                if ($content !== false && preg_match('/^fcp_colony:\s*(.+)$/m', $content, $m)) {
                    $names[] = trim($m[1], " \t\"'");
                }
            }
            $names = array_values(array_unique($names));
        }

        return $names;
    }
}
