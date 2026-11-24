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
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;
use App\Game\Core\FormulaEvaluator;
use App\Game\Model\Planet;
use App\Game\Model\User;

/** Buildings: build queue of the current planet, start/cancel/demolish buildings and the building list. */
#[\App\Controller\RequireModule('MODULE_BUILDING')]
class BuildingsController extends AbstractGameController
{
    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_LEVEL = 1;
    private const QUEUE_ELEMENT_TIME = 2;
    private const QUEUE_BUILD_END_TIME = 3;
    private const QUEUE_MODE = 4;
    private const QUEUE_COST = 5;

    /** @var string|null language key of the reason why the last insert/destroy command was refused */
    private ?string $rejection = null;

    /**
     * Cancels the building currently under construction (queue position 1), refunds its cost, and starts the next queued entry.
     * Returns false when nothing is being built.
     */
    private function cancelBuildingFromQueue(): bool
    {
        $USER = $this->state->USER;
        $CurrentQueue  = !empty($this->state->PLANET['b_building_id']) ? StoredData::decode($this->state->PLANET['b_building_id']) : [];
        if (empty($CurrentQueue)) {
            $this->state->PLANET['b_building_id']    = '';
            $this->state->PLANET['b_building']       = 0;
            return false;
        }

        if (isset($CurrentQueue[0][self::QUEUE_COST])) {
            $costResources = $CurrentQueue[0][self::QUEUE_COST];
        } else {
            $Element                = $CurrentQueue[0][self::QUEUE_ELEMENT_ID];
            $BuildLevel             = $CurrentQueue[0][self::QUEUE_LEVEL];
            $BuildMode              = $CurrentQueue[0][self::QUEUE_MODE];

            $costResources = $this->buildFunctions()->getElementPrice(
                $USER,
                $this->state->PLANET,
                $Element,
                $BuildMode == 'destroy',
                $BuildLevel,
            );
        }

        $this->ecoObj->addResources($this->state->PLANET['id'], $costResources, $this->state->PLANET);

        array_shift($CurrentQueue);
        if (count($CurrentQueue) == 0) {
            $this->state->PLANET['b_building']       = 0;
            $this->state->PLANET['b_building_id']    = '';
        } else {
            $this->state->PLANET['b_building']       = 0;
            $this->state->PLANET['b_building_id']    = '';
            foreach ($CurrentQueue as $ListIDArray) {
                $this->addBuildingToQueue($ListIDArray[self::QUEUE_ELEMENT_ID], 'build' == $ListIDArray[self::QUEUE_MODE]);
            }
        }
        return true;
    }

    /**
     * Removes the waiting queue entry at 1-based position $QueueID (the running entry, position 1, cannot be removed here) and re-adds the
     * entries behind it so costs and times are recomputed. Returns false for stale positions.
     */
    private function removeBuildingFromQueue(int $QueueID): bool
    {
        if ($QueueID <= 1 || empty($this->state->PLANET['b_building_id'])) {
            return false;
        }

        $CurrentQueue  = StoredData::decode($this->state->PLANET['b_building_id']);
        $ActualCount   = count($CurrentQueue);
        if ($ActualCount <= 1) {
            // Stale request: never cancel the running building by accident
            return false;
        }

        if ($QueueID - $ActualCount >= 1) {
            // Avoid race conditions
            return false;
        }

        $NewQueueArray = [];
        for ($i = $QueueID, $j = 0, $max = sizeof($CurrentQueue); $i <= $max; $i++, $j++) {
            if ($i != $QueueID) {
                $NewQueueArray[$j] = $CurrentQueue[$i - 1];
            }
            unset($CurrentQueue[$i - 1]);
        }

        if (!empty($CurrentQueue)) {
            $this->state->PLANET['b_building_id'] = StoredData::encode($CurrentQueue);
        } else {
            $this->state->PLANET['b_building_id'] = "";
        }

        foreach ($NewQueueArray as $ListIDArray) {
            $this->addBuildingToQueue($ListIDArray[self::QUEUE_ELEMENT_ID], 'build' == $ListIDArray[self::QUEUE_MODE]);
        }

        return true;
    }

    /**
     * Adds an upgrade ($AddMode) or a demolition (false) of building $Element to the planet's build queue after checking: allowed on this
     * planet type, module enabled, requirements met, not blocked by running research (research lab) or shipyard work (shipyard, nanite factory), and
     * that no missiles are stored or queued when demolishing the silo. Deducts resources and starts the entry when the queue was empty.
     */
    private function addBuildingToQueue(int $Element, bool $AddMode = true): void
    {
        if (!$this->canQueueBuilding($Element, $AddMode)) {
            return;
        }

        $CurrentQueue       = !empty($this->state->PLANET['b_building_id']) ? StoredData::decode($this->state->PLANET['b_building_id']) : [];
        if (!is_array($CurrentQueue)) {
            $CurrentQueue = [];
        }
        $ActualCount = count($CurrentQueue);

        $config = $this->configs->get();

        if ($config->max_elements_build != 0 && $ActualCount >= $config->max_elements_build) {
            $this->rejection = 'bd_buildlist_full';

            return;
        }
        if (!$this->buildFunctions()->canQueueBuildingOnFields($this->state->PLANET, $CurrentQueue, $AddMode)) {
            $this->rejection = 'bd_no_more_fields';

            return;
        }

        if ($this->state->PLANET['watch_id'] == $Element) {
            $this->state->PLANET['watch_id'] = 0;
            ;
        }

        $BuildMode          = $AddMode ? 'build' : 'destroy';
        $BuildLevel         = $this->state->PLANET[$this->elements()->column($Element)] + (int) $AddMode;

        if ($ActualCount == 0) {
            $this->startBuilding($Element, $AddMode, $BuildLevel, $BuildMode);
        } else {
            $this->appendToBuildQueue($CurrentQueue, $Element, $AddMode, $BuildLevel, $BuildMode);
        }
    }

    /**
     * Static rules of addBuildingToQueue(): the element is allowed on this planet type, enabled, its requirements are
     * met, it is not blocked by running research (research lab) or shipyard work (shipyard, nanite factory), the silo is not
     * demolished while missiles are stored, terraformer and moon base cannot be demolished, and a demolition needs a level to remove.
     */
    private function canQueueBuilding(int $Element, bool $AddMode): bool
    {
        $USER = $this->state->USER;

        $allowed = !(
            !in_array($Element, $this->elements()->allowedOn($this->state->PLANET['planet_type']))
            || !$this->buildFunctions()->isEnabled($Element)
            || !$this->buildFunctions()->isTechnologieAccessible($USER, $this->state->PLANET, $Element)
            || (!$AddMode && (($Element == SILO && ($this->state->PLANET[$this->elements()->column(INTERCEPTOR_MISSILE)] + $this->state->PLANET[$this->elements()->column(INTERPLANETARY_MISSILE)] + $this->queuedMissiles()) > 0) || $Element == TERRAFORMER || $Element == MOONBASE))
            || (!$AddMode && $this->state->PLANET[$this->elements()->column($Element)] == 0)
        );
        if (!$allowed) {
            return false;
        }

        // same "busy" rules as the page (templates/game/page.buildings.default.html.twig)
        if (
            (($Element == RESEARCH_LABORATORY || $Element == university) && $USER["b_tech_planet"] != 0)
            || (($Element == NANITE_FACTORY || $Element == SHIPYARD) && !empty($this->state->PLANET['b_hangar_id']))
            || ($Element == REPAIR_DOCK && $this->repairDockBusy())
        ) {
            $this->rejection = 'bd_working';

            return false;
        }

        return true;
    }

    /**
     * Whether the planet's wreck field has a repair order running (the repair dock cannot be changed meanwhile).
     */
    private function repairDockBusy(): bool
    {
        $wreckfield = $this->db->selectSingle('SELECT `repair_order` FROM %%PLANET_WRECKFIELD%% WHERE planetID = :planetID;', [':planetID' => $this->state->PLANET['id']]);

        return !empty($wreckfield['repair_order']);
    }

    /**
     * Missiles waiting in the planet's hangar queue; they count like stored ones when the silo is to be demolished.
     */
    private function queuedMissiles(): int|float
    {
        $queue = !empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [];
        $queued = 0;
        foreach ((array) $queue as $entry) {
            if (isset($entry[0], $entry[1]) && in_array((int) $entry[0], $this->elements()->ids('missile'))) {
                $queued += $entry[1];
            }
        }

        return $queued;
    }

    /**
     * Starts the first queue entry on an empty queue: refuses above the maximum level or when the cost is not affordable,
     * otherwise deducts the resources and sets the entry with its end time on the planet (saved by the caller).
     */
    private function startBuilding(int $Element, bool $AddMode, int $BuildLevel, string $BuildMode): void
    {
        $USER = $this->state->USER;

        if ($this->elements()->get($Element)->maxLevel < $BuildLevel) {
            $this->rejection = 'bd_maxlevel';

            return;
        }

        $costResources      = $this->buildFunctions()->getElementPrice($USER, $this->state->PLANET, $Element, !$AddMode, $BuildLevel);

        if (!$this->buildFunctions()->isElementBuyable($USER, $this->state->PLANET, $Element, $costResources)) {
            $this->rejection = 'bd_remaining';

            return;
        }

        $this->ecoObj->removeResources($this->state->PLANET['id'], $costResources, $this->state->PLANET);

        $elementTime                = $this->buildFunctions()->getBuildingTime($USER, $this->state->PLANET, $Element, $costResources, !$AddMode);
        $BuildEndTime               = $this->state->runtime->timestamp + $elementTime;
        $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkBuildTime((int) $USER['id'], $elementTime));

        $this->state->PLANET['b_building_id']    = StoredData::encode([[$Element, $BuildLevel, $elementTime, $BuildEndTime, $BuildMode, $costResources]]);
        $this->state->PLANET['b_building']       = $BuildEndTime;
    }

    /**
     * Appends an entry to a non-empty queue: the level is the planet level plus the net effect of the entries already
     * queued for the element (no cost is charged now, it is taken when the entry starts); refuses a demolition below
     * level 0 or a level above the maximum. The entry ends after the last queued one.
     *
     * @param array<int, array<int, mixed>> $CurrentQueue the decoded queue (never empty)
     */
    private function appendToBuildQueue(array $CurrentQueue, int $Element, bool $AddMode, int $BuildLevel, string $BuildMode): void
    {
        $USER = $this->state->USER;
        $ActualCount = count($CurrentQueue);

        $addLevel = 0;
        foreach ($CurrentQueue as $QueueSubArray) {
            if ($QueueSubArray[self::QUEUE_ELEMENT_ID] != $Element) {
                continue;
            }

            if ($QueueSubArray[self::QUEUE_MODE] == 'build') {
                $addLevel++;
            } else {
                $addLevel--;
            }
        }

        $BuildLevel                 += $addLevel;

        if (!$AddMode && $BuildLevel == 0) {
            return;
        }

        if ($this->elements()->get($Element)->maxLevel < $BuildLevel) {
            $this->rejection = 'bd_maxlevel';

            return;
        }

        $elementTime = $this->buildFunctions()->getBuildingTime($USER, $this->state->PLANET, $Element, null, !$AddMode, $BuildLevel, $ActualCount);
        $BuildEndTime = $CurrentQueue[$ActualCount - 1][self::QUEUE_BUILD_END_TIME] + $elementTime;
        $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkBuildTime((int) $USER['id'], $elementTime));
        $CurrentQueue[] = [$Element, $BuildLevel, $elementTime, $BuildEndTime, $BuildMode];
        $this->state->PLANET['b_building_id'] = StoredData::encode($CurrentQueue);
    }

    /**
     * Data for the queue display: per entry name, level, remaining time and end time, plus the quick info of the first entry.
     *
     * @return array<mixed>
     */
    private function getQueueData(): array
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $scriptData = [];
        $quickinfo = [];

        if (empty($PLANET['b_building']) || empty($PLANET['b_building_id'])) {
            return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
        }

        $buildQueue     = StoredData::decode($PLANET['b_building_id']);

        foreach ($buildQueue as $BuildArray) {
            if ($BuildArray[self::QUEUE_BUILD_END_TIME] < $this->state->runtime->timestamp && !$USER['urlaubs_modus']) {
                continue;
            }

            // the level the building has once this entry is done (a demolition stores the level it takes down)
            $quickinfo[$BuildArray[self::QUEUE_ELEMENT_ID]]  = $BuildArray[self::QUEUE_LEVEL] - ($BuildArray[self::QUEUE_MODE] == 'destroy' ? 1 : 0);

            $scriptData[] = [
                'element'   => $BuildArray[self::QUEUE_ELEMENT_ID],
                'level'     => $BuildArray[self::QUEUE_LEVEL],
                'time'      => $BuildArray[self::QUEUE_ELEMENT_TIME],
                'resttime'  => $USER['urlaubs_modus'] ? ($BuildArray[self::QUEUE_BUILD_END_TIME] - $USER['urlaubs_start']) : ($BuildArray[self::QUEUE_BUILD_END_TIME] - $this->state->runtime->timestamp),
                'destroy'   => ($BuildArray[self::QUEUE_MODE] == 'destroy'),
                'endtime'   => $this->viewHelpers()->formatDate('U', $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'display'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
            ];
        }

        return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
    }

    /**
     * Stores the element watched by the cargo calculator on the planet (request: watch = element id, 0 clears) and redirects back to the
     * page; null when the request has no `watch`.
     */
    private function watchRedirect(): ?Response
    {
        if ($this->input()->string('watch') === '') {
            return null;
        }
        $this->db->update(
            'UPDATE %%PLANETS%% SET watch_id = :watch_id WHERE id = :planetId;',
            [
                ':watch_id' => max(0, $this->input()->int('watch')),
                ':planetId' => $this->state->PLANET['id'],
            ],
        );

        return $this->redirectToRoute('game_buildings_show');
    }

    /**
     * Queue command (POST, request: cmd = cancel, remove, insert or destroy with building = element id and listid = queue position): runs it and
     * redirects back to the page. Without a command, or in vacation mode, the page is shown as it is.
     */
    #[GameRoute('game', 'game/buildings', 'buildings', 'command', ['POST'])]
    public function command(): Response
    {
        if ($watched = $this->watchRedirect()) {
            return $watched;
        }
        $TheCommand = $this->input()->string('cmd');
        if (!empty($TheCommand) && $this->state->USER['urlaubs_modus'] == 0) {
            return $this->runQueueCommand($TheCommand);
        }

        return $this->show();
    }

    /**
     * Buildings page. Request: watch (element id, 0 clears) stores the element watched by the cargo calculator on the planet and redirects
     * back. Otherwise renders the list of buildings with costs, times, requirements, production changes and the build queue.
     */
    #[GameRoute('game', 'game/buildings', 'buildings')]
    public function show(): Response
    {
        if ($watched = $this->watchRedirect()) {
            return $watched;
        }

        $LNG = $this->state->LNG;
        $catchupFactor  = $this->protectionRules()->catchUpFactorFor($this->player());

        $config             = $this->configs->get();

        $queueData          = $this->getQueueData();
        $Queue              = $queueData['queue'];
        $QueueCount         = count($Queue);

        $CanBuildElement = isVacationMode($this->state->USER)
            || $config->max_elements_build == 0
            || $QueueCount < $config->max_elements_build;
        $CurrentQueue = !empty($this->state->PLANET['b_building_id']) ? StoredData::decode($this->state->PLANET['b_building_id']) : [];
        if (!is_array($CurrentQueue)) {
            $CurrentQueue = [];
        }
        // the page only reads from here on: one typed snapshot of the player and the planet serves the whole listing
        $userModel = $this->models()->user($this->state->USER);
        $planetModel = $this->models()->planet($this->state->PLANET);
        $RoomIsOk = $this->buildFunctions()->canQueueOnFieldsFor($planetModel, $CurrentQueue);

        $BuildInfoList = $this->buildingList($userModel, $planetModel, $queueData, $QueueCount, $catchupFactor, $config);

        $sql = "SELECT `repair_order` FROM %%PLANET_WRECKFIELD%% WHERE planetID = :planetID;";
        $wreckfield = $this->db->selectSingle($sql, [':planetID' => $this->state->PLANET['id']]);

        if ($QueueCount != 0) {
            $this->addScript('buildlist.js');
        }

        return $this->render('game/page.buildings.default.html.twig', [
            'umode'             => $this->state->USER['urlaubs_modus'],
            'BuildInfoList'     => $BuildInfoList,
            'CanBuildElement'   => $CanBuildElement,
            'RoomIsOk'          => $RoomIsOk,
            'Queue'             => $Queue,
            // fixed slot count of the queue list (free slots shown), at least the current length
            'queueSlots'        => max((int) $config->max_elements_build, count($Queue)),
            'isBusy'            => [
                'shipyard' => !empty($this->state->PLANET['b_hangar_id']),
                'research' => $this->state->USER['b_tech_planet'] != 0,
                'repairdock' => !empty($wreckfield['repair_order']),
            ],
            'userLang'          => $this->state->USER['lang'],
            'isCatchUp'         => $config->catch_up,
            'catchUp'           => $LNG->format('sys_catchup', round((1 - $catchupFactor) * 100, 2)),
            'watch'             => $this->state->PLANET['watch_id'],
            'planet_field_current' => $this->state->PLANET['field_current'],
            'planet_field_max'     => $this->planetQueries()->maxFieldsFor($planetModel),
        ]);
    }

    /**
     * Executes a queue command (cancel, remove, insert, destroy with the `building` / `listid` request values) in a
     * transaction on the locked planet row, saves the queue unless the command is unknown, and redirects back to
     * the page.
     */
    private function runQueueCommand(string $TheCommand): Response
    {
        $Element        = $this->input()->int('building');
        $ListID         = $this->input()->int('listid');
        $db = $this->db;
        $db->startTransaction();
        // user row first (like the research code), so a research started a moment ago is seen by the laboratory rule
        $locked = $db->selectSingle('SELECT b_tech_planet FROM %%USERS%% WHERE id = :userId FOR UPDATE;', [':userId' => $this->state->USER['id']]);
        if (!empty($locked)) {
            $this->state->USER['b_tech_planet'] = $locked['b_tech_planet'];
        }
        $this->state->PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $this->state->PLANET['id']]);
        $validCommand = true;
        switch ($TheCommand) {
            case 'cancel':
                $this->cancelBuildingFromQueue();
                break;
            case 'remove':
                $this->removeBuildingFromQueue($ListID);
                break;
            case 'insert':
                $this->addBuildingToQueue($Element, true);
                break;
            case 'destroy':
                $this->addBuildingToQueue($Element, false);
                break;
            default:
                error_log($TheCommand);
                $validCommand = false;
                break;
        }

        if ($validCommand) {
            $this->ecoObj->saveBuilingQueue($this->state->PLANET);
        }
        $db->commit();

        // a refused insert/destroy says why instead of silently reloading the page
        if (($TheCommand == 'insert' || $TheCommand == 'destroy') && $this->rejection !== null) {
            return $this->messageResponse($this->state->LNG[$this->rejection], [['label' => $this->state->LNG['bd_continue'], 'url' => $this->router->generate('game_buildings_show')]]);
        }

        return $this->redirectToRoute('game_buildings_show');
    }

    /**
     * Template rows of all buildings of this planet type: level, costs, build/demolish times, energy change, wait time
     * until the cost is affordable, requirements still missing (only when the player shows all buildable elements) and the
     * display flags (fade, buyable, destroyable, filter class, catch-up factor).
     *
     * @param array{queue: array, quickinfo: array<int, int>} $queueData from getQueueData()
     * @param array<mixed> $queueData
     * @return array<int, array<string, mixed>> building id => row
     */
    private function buildingList(User $userModel, Planet $planetModel, array $queueData, int $QueueCount, float $catchupFactor, object $config): array
    {

        $BuildInfoList      = [];
        $Elements           = $this->elements()->allowedOn($this->state->PLANET['planet_type']);
        $production = [
            RESOURCE_METAL => $this->state->PLANET['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500),
            RESOURCE_CRYSTAL => $this->state->PLANET['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500),
            RESOURCE_DEUT => $this->state->PLANET['deuterium_perhour']  + $config->deuterium_basic_income * ($config->resource_multiplier / 2500),
        ];

        foreach ($Elements as $Element) {
            if (!$this->buildFunctions()->isEnabled($Element)) {
                continue;
            }
            $requirementsList = [];
            // every element of the planet type is listed; missing requirements lock it (template: needs + greyed-out button)
            if (!$this->buildFunctions()->isAccessibleFor($userModel, $planetModel, $Element)) {
                if ($this->elements()->get($Element)->requirements !== []) {
                    foreach ($this->elements()->get($Element)->requirements as $requireID => $RedCount) {
                        $requirementsList[$requireID] = [
                            'count' => $RedCount,
                            'own'   => isset($this->state->PLANET[$this->elements()->column($requireID)]) ? $this->state->PLANET[$this->elements()->column($requireID)] : $this->state->USER[$this->elements()->column($requireID)],
                        ];
                    }
                }
            }

            $BuildInfoList[$Element] = $this->buildingRow($Element, $requirementsList, $userModel, $planetModel, $queueData, $QueueCount, $catchupFactor, $production, $config);
        }

        return $BuildInfoList;
    }

    /**
     * Energy change of building the next level of production building $Element ("consumes" when negative), formatted
     * for the template that fills bd_need_engine / bd_more_engine with the amount.
     *
     * @return array{consumes: bool, amount: string}
     */
    private function energyChange(int $Element, int $levelToBuild, object $config): array
    {
        /* Data for eval */
        $BuildEnergy        = $this->state->USER[$this->elements()->column(ENERGY_TECH)];
        $BuildLevelFactor   = 10;
        $BuildTemp          = $this->state->PLANET['temp_max'];

        // baseline = the level that will be reached once the queued entries are done
        $BuildLevel = $levelToBuild;
        $Need       = FormulaEvaluator::evaluate($this->elements()->productionFormula($Element, RESOURCE_ENERGY), compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));

        $BuildLevel = $levelToBuild + 1;
        $Prod       = FormulaEvaluator::evaluate($this->elements()->productionFormula($Element, RESOURCE_ENERGY), compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));

        $requireEnergy  = $Prod - $Need;
        $requireEnergy  = round($requireEnergy * $config->energy_multiplier);

        return [
            'consumes' => $requireEnergy < 0,
            'amount'   => $this->viewHelpers()->prettyNumber(abs($requireEnergy)),
        ];
    }

    /**
     * Seconds until the production covers the missing resources of $costOverflow (the slowest resource counts); 0 on
     * moons, which have no production, and when a missing resource is not produced or the price exceeds the storage capacity.
     *
     * @param array<int, int|float> $costOverflow resources still missing
     * @param array<int, int|float> $costResources the price per resource
     * @param array<int, int|float> $production hourly production per resource
     */
    private function timeToAfford(array $costOverflow, array $production, array $costResources): int|float
    {
        $timetobuild = 0;
        if ($this->state->PLANET['planet_type'] != PlanetType::Moon->value) {
            foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
                if (array_key_exists($resourceId, $costOverflow) && $costOverflow[$resourceId] != 0) {
                    // never reachable: nothing is produced, or the price is above the storage capacity
                    if ($production[$resourceId] <= 0 || ($costResources[$resourceId] ?? 0) > $this->state->PLANET[$this->elements()->column($resourceId) . '_max']) {
                        return 0;
                    }
                    $timetobuild = max($timetobuild, $costOverflow[$resourceId] / $production[$resourceId]);
                }
            }
            $timetobuild = floor($timetobuild * 3600);
        }

        return $timetobuild;
    }

    /**
     * One building row of the list (see buildingList()). When the building is in the queue, the level after the queued
     * work is the base for the next costs.
     *
     * @param array<int, array{count: mixed, own: mixed}> $requirementsList missing requirements (empty when accessible)
     * @param array{queue: array, quickinfo: array<int, int>} $queueData
     * @param array<int, int|float> $production hourly production incl. basic income per resource
     * @param array<mixed> $queueData
     * @return array<string, mixed>
     */
    private function buildingRow(int $Element, array $requirementsList, User $userModel, Planet $planetModel, array $queueData, int $QueueCount, float $catchupFactor, array $production, object $config): array
    {

        $infoEnergy = null;

        if (isset($queueData['quickinfo'][$Element])) {
            $levelToBuild   = $queueData['quickinfo'][$Element];
            $levelToDestroy = $levelToBuild;
        } else {
            $levelToBuild   = $this->state->PLANET[$this->elements()->column($Element)];
            $levelToDestroy = $levelToBuild;
        }

        if (in_array($Element, $this->elements()->ids('prod'))) {
            // the template formats bd_need_engine / bd_more_engine with this amount
            $infoEnergy = $this->energyChange($Element, $levelToBuild, $config);
        }

        $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $Element, false, $levelToBuild + 1);
        $costOverflow = $this->buildFunctions()->restPriceFor($userModel, $planetModel, $Element, $costResources);
        $timetobuild = $this->timeToAfford($costOverflow, $production, $costResources);

        $elementTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Element, $costResources, false, null, $QueueCount);
        $destroyResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $Element, true, $levelToDestroy);
        $destroyTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Element, $destroyResources, true, null, $QueueCount);
        $destroyOverflow = $this->buildFunctions()->restPriceFor($userModel, $planetModel, $Element, $destroyResources);
        $buyable = $QueueCount != 0 || $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $Element, $costResources);
        $destroyable = ($Element != SILO || ($this->state->PLANET[$this->elements()->column(INTERCEPTOR_MISSILE)] + $this->state->PLANET[$this->elements()->column(INTERPLANETARY_MISSILE)]) == 0) && $Element != TERRAFORMER && $Element != MOONBASE;

        $filterClass = 'other';
        if (in_array($Element, $this->elements()->ids('prod'))) {
            $filterClass = 'prod';
        } elseif (in_array($Element, $this->elements()->ids('storage'))) {
            $filterClass = 'storage';
        }

        $fade = ($this->state->USER['missing_requirements_opacity'] && !empty($requirementsList))
            || ($this->state->USER['missing_resources_opacity'] && !$buyable)
            || $this->elements()->get($Element)->maxLevel == $levelToBuild;

        $factor = $this->protectionRules()->getCatchUpFactorForElement($catchupFactor, $Element, $levelToBuild + 1);

        return [
            'level'             => $this->state->PLANET[$this->elements()->column($Element)],
            'maxLevel'          => $this->elements()->get($Element)->maxLevel,
            'infoEnergy'        => $infoEnergy,
            'costResources'     => $costResources,
            'costOverflow'      => $costOverflow,
            'elementTime'       => $elementTime,
            'destroyResources'  => $destroyResources,
            'destroyTime'       => $destroyTime,
            'destroyOverflow'   => $destroyOverflow,
            'buyable'           => $buyable,
            'destroyable'       => $destroyable,
            'levelToBuild'      => $levelToBuild,
            'levelToDestroy'    => $levelToDestroy,
            'timetobuild'       => $timetobuild,
            'requirements'      => $requirementsList,
            'filterClass'       => $filterClass,
            'fade'              => $fade,
            'factor'            => $catchupFactor != $factor ? round((1 - $factor) * 100, 2) : null,
        ];
    }
}
