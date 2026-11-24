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

namespace App\Game\Core;

use App\Game\Enum\PlanetType;

/**
 * Brings a planet (and its owner) up to date: finishes shipyard, building and research queues in order of their end
 * times, integrates the resource production up to the target time and optionally saves the result.
 *
 * Orchestrator over: ResourceProduction (production maths, eco hash), WreckfieldMaintenance (72 h wreckfield rules),
 * HangarQueueProcessor / BuildingQueueProcessor / ResearchQueueProcessor (the queue state machines, working on the
 * shared QueueState), ResourcePersistence (DB writes). Keeps the working state and decides in which order things
 * happen: shipyard, repair job, wreckfield, then building and research completions by end time, then production.
 *
 * Processing happens only while the planet's last_update lies before the target time (a planet whose last_update is
 * in the future is skipped entirely, queues included) and never for vacation mode, closed universes or destroyed
 * planets. The research queue is processed on any planet of the user (it is paid from / charged to b_tech_planet).
 */
class ResourceUpdate
{
    use \App\Game\Core\GameUtilities;

    /**
     * reference of the config object
     * @var Config
     */
    private $config = null;

    private $HASH = null;

    private $Build = true;
    private $Tech = true;

    /** working user/planet rows, built deltas and target time, shared with the queue processors */
    private QueueState $st;

    /**
     * @param bool $Build false: do not process the building queue in CalcResource().
     * @param bool $Tech false: do not process the research queue in CalcResource().
     */
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        $Build = true,
        $Tech = true,
    ) {
        $this->Build = $Build;
        $this->Tech = $Tech;

        $this->st = new QueueState();
        $this->st->builded[RESOURCE_METAL] = 0.0;
        $this->st->builded[RESOURCE_CRYSTAL] = 0.0;
        $this->st->builded[RESOURCE_DEUT] = 0.0;
        $this->st->updateResource = fn($time, $hash) => $this->UpdateResource($time, $hash);
    }

    private function production(): ResourceProduction
    {
        return $this->gameUtility(ResourceProduction::class);
    }

    private function persistence(): ResourcePersistence
    {
        return $this->gameUtility(ResourcePersistence::class);
    }

    private function wreckfields(): WreckfieldMaintenance
    {
        return $this->gameUtility(WreckfieldMaintenance::class);
    }


    public function setData($USER, $PLANET)
    {
        $this->st->user = $USER;
        $this->st->planet = $PLANET;
    }

    public function getData()
    {
        return [$this->st->user, $this->st->planet];
    }

    /**
     * Current [user, planet] state arrays (same as getData()); read after CalcResource() to get the updated rows.
     */
    public function ReturnVars()
    {
        return [$this->st->user, $this->st->planet];
    }

    /**
     * Eco hash of the current planet/user state (see ResourceProduction::createHash()). Needs the config set by
     * CalcResource().
     */
    public function CreateHash()
    {
        return $this->production()->hashFor(
            $this->models()->user($this->st->user),
            $this->models()->planet($this->st->planet),
            $this->config,
        );
    }

    /**
     * Updates the planet and user up to $TIME: finishes the shipyard queue and repair jobs, then processes the building
     * and research queues in end-time order (each finished element may start the next one), then produces resources.
     * Does nothing for users in vacation mode, closed/registration-only universes and destroyed planets.
     *
     * @param array<string, mixed> $USER user row
     * @param array<string, mixed> $PLANET planet row
     * @param bool $SAVE write the result to the DB (ResourcePersistence::savePlanet)
     * @param int|null $TIME target time, default: request timestamp
     * @param bool $HASH true: rebuild production only when the eco hash changed; false: always rebuild
     * @return array{0: array, 1: array} [$USER, $PLANET] after the update
     */
    public function CalcResource($USER, $PLANET, $SAVE = false, $TIME = null, $HASH = true)
    {
        $this->st->user = $USER;
        $this->st->planet = $PLANET;
        $this->st->time = is_null($TIME) ? $this->state->runtime->timestamp : $TIME;
        $this->config = $this->configs->get($this->st->user['universe']);

        if (!$this->isUpdatable($this->st->user, $this->st->planet)) {
            return $this->ReturnVars();
        }

        $ProductionTime = ($this->st->time - $this->st->planet['last_update']);

        if ($this->Build && $ProductionTime > 0) {
            // satellites (units with production) count from their finish time: those steps run inside the loop below
            $checkpoints = $this->gameUtility(HangarQueueProcessor::class)->productionCheckpoints($this->st);
            $hangarSince = $this->st->planet['last_update'];
            $stepped = $checkpoints !== [];
            if (!$stepped) {
                $this->gameUtility(HangarQueueProcessor::class)->process($this->st);
            }
            $this->st->planet = $this->wreckfields()->applyFinishedRepair($this->st->planet, $this->st->time, $this->st->builded, $this->st->repaired);
            $this->wreckfields()->expire($this->st->planet['id'], $this->st->time); // Option: Delete not in Umode

            while ($checkpoints !== []
                    || ($this->Tech && $this->st->user['b_tech'] != 0 && $this->st->user['b_tech'] < $this->st->time)
                    || ($this->st->planet['b_building'] != 0 && $this->st->planet['b_building'] < $this->st->time)) {
                if ($checkpoints !== [] && $checkpoints[0] <= $this->nextQueueEventTime()) {
                    $this->advanceHangarTo(array_shift($checkpoints), $hangarSince);
                } elseif ($this->st->planet['b_building'] != 0 && (!$this->Tech || $this->st->user['b_tech'] == 0 || $this->st->planet['b_building'] <= $this->st->user['b_tech'])) {
                    if ($this->gameUtility(BuildingQueueProcessor::class)->finishFirst($this->st)) {
                        $this->SetNextQueueElementOnTop();
                    }
                } elseif ($this->Tech && $this->st->user['b_tech'] != null) {
                    if ($this->gameUtility(ResearchQueueProcessor::class)->finishCurrent($this->st)) {
                        $this->SetNextQueueTechOnTop();
                    }
                }
            }

            $this->UpdateResource($this->st->time, $HASH);
            if ($stepped) {
                // the units finishing in the last step count from now: the stored production and energy follow them
                $this->gameUtility(HangarQueueProcessor::class)->process($this->st, $hangarSince);
                $this->st->planet['eco_hash'] = $this->CreateHash();
                $this->ReBuildCache();
            }

            if ($SAVE === true) {
                $this->SavePlanetToDB($this->st->user, $this->st->planet);
                $this->checkPlanetAchievements();
            }
        }

        return $this->ReturnVars();
    }

    /**
     * Achievement checks after a saved update: stock based ones (in memory) and the energy deficit tracking, which writes
     * planets.low_energy_since only when the deficit starts or ends.
     */
    private function checkPlanetAchievements(): void
    {
        $this->achievementGuard()->run(function (): void {
            $planet = $this->st->planet;
            $userId = (int) $this->st->user['id'];
            $achievements = $this->planetRessAchievements();
            $achievements->checkPlanetStockAchievements($planet, $userId);

            if (isset($planet['low_energy_since'])) {
                $since = $achievements->trackLowEnergy($planet, $userId, (int) $this->st->time);
                if ($since !== (int) $planet['low_energy_since']) {
                    $this->st->planet['low_energy_since'] = $since;
                    $this->persistence()->saveLowEnergySince((int) $planet['id'], $since);
                }
            }
        });
    }

    /**
     * Earliest finish time of a due building or research (the same "end before the target time" test as the queue loop in
     * CalcResource()), PHP_INT_MAX when none is due.
     */
    private function nextQueueEventTime(): int|float
    {
        $next = PHP_INT_MAX;
        if ($this->Tech && $this->st->user['b_tech'] != 0 && $this->st->user['b_tech'] < $this->st->time) {
            $next = $this->st->user['b_tech'];
        }
        if ($this->st->planet['b_building'] != 0 && $this->st->planet['b_building'] < $this->st->time) {
            $next = min($next, $this->st->planet['b_building']);
        }

        return $next;
    }

    /**
     * Brings the resources up to $time with the unit counts valid until then, then finishes the shipyard units that fit up to
     * $time, so units with production (solar satellites) only count from their finish time. $since is the time the elapsed
     * build time was counted up to; it moves to $time.
     */
    private function advanceHangarTo(int $time, int &$since): void
    {
        $target = $this->st->time;
        $this->st->time = $time;
        $this->UpdateResource($time, true);
        $this->gameUtility(HangarQueueProcessor::class)->process($this->st, $since);
        $since = $time;
        $this->st->time = $target;
    }

    /**
     * True when CalcResource($USER, $PLANET, true, $TIME) would process something, and therefore write: the planet is
     * processed at all (not vacation mode, closed or registration-only universe, destroyed planet) and its last update
     * lies before the target time. Callers that only want the update for a locked read-modify-write can skip the
     * transaction and the row locks when this is false: nothing is queued, produced or saved then.
     *
     * @param array<string, mixed> $USER user row
     * @param array<string, mixed> $PLANET planet row
     * @param int|null $TIME target time, default: request timestamp
     */
    public function wouldUpdate($USER, $PLANET, $TIME = null): bool
    {
        $time = is_null($TIME) ? $this->state->runtime->timestamp : $TIME;
        $this->config = $this->configs->get($USER['universe']);

        return $this->isUpdatable($USER, $PLANET) && $time - $PLANET['last_update'] > 0;
    }

    /**
     * Vacation mode, closed or registration-only universes and destroyed planets are never updated.
     *
     * @param array<string, mixed> $USER
     * @param array<string, mixed> $PLANET
     */
    private function isUpdatable(array $USER, array $PLANET): bool
    {
        return !($USER['urlaubs_modus'] == 1
            || $this->config->uni_status == STATUS_CLOSED
            || $this->config->uni_status == STATUS_REG_ONLY
            || $PLANET['destruyed'] != 0);
    }

    /**
     * Brings the stock of the working planet to $TIME: rebuilds the production values (always when $HASH is false,
     * otherwise only when the eco hash changed) and integrates the production since last_update.
     */
    private function UpdateResource($TIME, $HASH = false)
    {
        $ProductionTime = ($TIME - $this->st->planet['last_update']);

        if ($ProductionTime > 0) {
            $this->st->planet['last_update'] = $TIME;
            if ($HASH === false) {
                $this->ReBuildCache();
            } else {
                $this->HASH = $this->CreateHash();

                if ($this->st->planet['eco_hash'] !== $this->HASH) {
                    $this->st->planet['eco_hash'] = $this->HASH;
                    $this->ReBuildCache();
                }
            }
            if ($this->st->planet['planet_type'] != PlanetType::Moon->value) { // moons produce nothing
                $produced = $this->production()->produceFor($this->models()->planet($this->st->planet), $ProductionTime, $this->config);
                $elements = $this->elements();
                foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
                    $this->st->builded[$resourceId] += $produced->delta($resourceId);
                    $this->st->planet[$elements->column($resourceId)] += $produced->delta($resourceId);
                }
            }
        }
    }

    public function getNetworkLevel($USER, $PLANET)
    {
        return $this->production()->networkLevelFor($this->models()->user($USER), $this->models()->planet($PLANET));
    }

    /**
     * Recomputes storage, energy and production per hour of the working planet (ResourceProduction::rebuild()).
     */
    public function ReBuildCache()
    {
        $this->st->planet = array_replace($this->st->planet, $this->production()->rebuildFor(
            $this->models()->user($this->st->user),
            $this->models()->planet($this->st->planet),
            $this->config,
        ));
    }

    /**
     * Starts the first element of the building queue (BuildingQueueProcessor::startNext()).
     */
    public function SetNextQueueElementOnTop()
    {
        return $this->gameUtility(BuildingQueueProcessor::class)->startNext($this->st);
    }

    /**
     * Starts the first research of the queue (ResearchQueueProcessor::startNext()).
     *
     * @param bool $save write the result to the DB afterwards
     */
    public function SetNextQueueTechOnTop($save = false)
    {
        if (!$this->gameUtility(ResearchQueueProcessor::class)->startNext($this->st)) {
            return false;
        }

        if ($save) {
            $this->SavePlanetToDB($this->st->user, $this->st->planet);
        }

        return true;
    }

    /**
     * Saves the working planet and user via ResourcePersistence and resets the built deltas.
     */
    private function SavePlanetToDB($USER, $PLANET)
    {
        $result = $this->persistence()->savePlanet($USER, $PLANET, $this->st->builded, $this->st->repaired, $this->st->time);

        $this->st->builded = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];
        $this->st->repaired = [];

        return $result;
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<mixed> $resources
     * @see ResourcePersistence::removeResources()
     */
    public function removeResources(int $planetId, array $resources, ?array &$planet = null)
    {
        $this->persistence()->removeResources($planetId, $resources, $planet);
    }

    /**
     * @param array<string, mixed> $planet
     * @param array<mixed> $resources
     * @see ResourcePersistence::addResources()
     */
    public function addResources(int $planetId, array $resources, ?array &$planet = null, float $factor = 1)
    {
        $this->persistence()->addResources($planetId, $resources, $planet, $factor);
    }

    /**
     * @param array<string, mixed> $PLANET
     * @see ResourcePersistence::saveResources()
     */
    public function saveResources(array $PLANET)
    {
        $this->persistence()->saveResources($PLANET);
    }

    /**
     * @param array<string, mixed> $USER
     * @see ResourcePersistence::saveTechQueue()
     */
    public function saveTechQueue(array $USER)
    {
        $this->persistence()->saveTechQueue($USER);
    }

    /**
     * @param array<string, mixed> $PLANET
     * @see ResourcePersistence::saveBuilingQueue()
     */
    public function saveBuilingQueue(array $PLANET)
    {
        $this->persistence()->saveBuilingQueue($PLANET);
    }

    /**
     * @param array<string, mixed> $PLANET
     * @see ResourcePersistence::saveShipyardQueue()
     */
    public function saveShipyardQueue(array $PLANET)
    {
        $this->persistence()->saveShipyardQueue($PLANET);
    }
}
