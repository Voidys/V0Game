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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;

/** Research: research queue of the player and the technology list. */
#[\App\Controller\RequireModule('MODULE_RESEARCH')]
class ResearchController extends AbstractGameController
{
    /** @var string|null language key of the reason why the last insert command was refused */
    private ?string $rejection = null;

    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_LEVEL = 1;
    private const QUEUE_ELEMENT_TIME = 2;
    private const QUEUE_BUILD_END_TIME = 3;
    private const QUEUE_PLANET_ID = 4;
    private const QUEUE_COST = 5;

    /**
     * True when no planet of the player is currently upgrading the research lab (or university): research is blocked while the lab is
     * under construction.
     */
    private function checkLabSettingsInQueue(): bool
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $sql    = "SELECT * FROM %%PLANETS%% WHERE id_owner = :owner AND destruyed = 0 FOR UPDATE;";
        $planets    = $db->select($sql, [
            ':owner'    => $USER['id'],
        ]);

        foreach ($planets as $planet) {
            if (empty($planet['b_building']) || empty($planet['b_building_id'])) {
                continue;
            }

            $CurrentQueue       = StoredData::decode($planet['b_building_id']);
            foreach ($CurrentQueue as $ListIDArray) {
                if ($ListIDArray[self::QUEUE_ELEMENT_ID] == university || $ListIDArray[self::QUEUE_ELEMENT_ID] == RESEARCH_LABORATORY) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Cancels the running research (queue position 1), refunds its cost and starts the next queued entry. False when there is none.
     */
    private function cancelResearchFromQueue(): bool
    {

        $CurrentQueue  = !empty($this->state->USER['b_tech_queue']) ? StoredData::decode($this->state->USER['b_tech_queue']) : [];
        if (empty($CurrentQueue) || empty($this->state->USER['b_tech'])) {
            $this->state->USER['b_tech_queue']   = '';
            $this->state->USER['b_tech_planet']  = 0;
            $this->state->USER['b_tech_id']      = 0;
            $this->state->USER['b_tech']         = 0;

            return false;
        }

        $db = $this->db;

        $elementId = $this->state->USER['b_tech_id'];
        if (isset($CurrentQueue[0][self::QUEUE_COST])) {
            $costResources = $CurrentQueue[0][self::QUEUE_COST];
        } else {
            $costResources = $this->buildFunctions()->getElementPrice(
                $this->state->USER,
                $this->state->PLANET,
                $elementId,
                false,
                $this->state->USER[$this->elements()->column($elementId)] + 1,
            );
        }

        if (empty($costResources)) {
            // nothing to refund (e.g. paid on an abandoned planet)
        } elseif ($this->state->PLANET['id'] == $this->state->USER['b_tech_planet']) {
            $this->ecoObj->addResources($this->state->USER['b_tech_planet'], $costResources, $this->state->PLANET);
        } else {
            $this->ecoObj->addResources($this->state->USER['b_tech_planet'], $costResources);
        }

        $this->state->USER['b_tech_id']          = 0;
        $this->state->USER['b_tech']             = 0;
        $this->state->USER['b_tech_planet']      = 0;

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $this->state->USER['b_tech_queue']   = '';
            $this->state->USER['b_tech_planet']  = 0;
            $this->state->USER['b_tech_id']      = 0;
            $this->state->USER['b_tech']         = 0;
        } else {
            $this->restartQueueAfterCancel($CurrentQueue, $elementId);
        }

        return true;
    }

    /**
     * After the running research was cancelled: recomputes the end times of the remaining entries (entries of the cancelled
     * element are dropped, each entry's time uses the lab network of its own planet) and starts the new first entry
     * (SetNextQueueTechOnTop; its cost is saved with the current planet when the entry runs there). An empty result clears the queue.
     *
     * @param array<int, array<int, mixed>> $CurrentQueue the entries behind the cancelled one (not empty)
     * @param int $elementId id of the cancelled research
     */
    private function restartQueueAfterCancel(array $CurrentQueue, int $elementId): void
    {
        $db = $this->db;
        $BuildEndTime       = $this->state->runtime->timestamp;
        $NewCurrentQueue    = [];
        $saveRessources = false; // save resources if one of the new techs is on the current planet
        foreach ($CurrentQueue as $ListIDArray) {
            if ($elementId == $ListIDArray[self::QUEUE_ELEMENT_ID] || empty($ListIDArray[self::QUEUE_ELEMENT_ID])) {
                continue;
            }

            if ($ListIDArray[self::QUEUE_PLANET_ID] != $this->state->PLANET['id']) {
                $CPLANET = $db->fetchRow(
                    $db->qb()
                        ->select($this->elements()->column(university), $this->elements()->column(RESEARCH_LABORATORY), 'id')
                        ->from($db->table('PLANETS'))
                        ->where('id = :id')
                        ->setParameter('id', $ListIDArray[self::QUEUE_PLANET_ID]),
                );
            } else {
                $CPLANET = $this->state->PLANET;
                $saveRessources = true;
            }

            $CPLANET[$this->elements()->column(RESEARCH_LABORATORY) . '_inter']    = $this->ecoObj->getNetworkLevel($this->state->USER, $CPLANET);
            $BuildEndTime += $this->buildFunctions()->getBuildingTime(
                $this->state->USER,
                $CPLANET,
                $ListIDArray[self::QUEUE_ELEMENT_ID],
                null,
                false,
                $ListIDArray[self::QUEUE_LEVEL],
            );
            $ListIDArray[self::QUEUE_BUILD_END_TIME] = $BuildEndTime;
            $NewCurrentQueue[] = $ListIDArray;
        }

        if (!empty($NewCurrentQueue)) {
            $this->state->USER['b_tech']             = $this->state->runtime->timestamp;
            $this->state->USER['b_tech_queue']       = StoredData::encode($NewCurrentQueue);
            $this->ecoObj->setData($this->state->USER, $this->state->PLANET);
            $this->ecoObj->SetNextQueueTechOnTop($saveRessources);
            [$this->state->USER, $this->state->PLANET] = $this->ecoObj->getData();
        } else {
            $this->state->USER['b_tech']         = 0;
            $this->state->USER['b_tech_queue']   = '';
        }
    }

    /**
     * Removes the waiting entry at 1-based position $QueueID (not the running one) and re-adds the entries behind it. False for stale positions.
     */
    private function removeResearchFromQueue(int $QueueID): bool
    {
        $PLANET = $this->state->PLANET;
        $CurrentQueue  = !empty($this->state->USER['b_tech_queue']) ? StoredData::decode($this->state->USER['b_tech_queue']) : [];
        if ($QueueID <= 1 || empty($CurrentQueue)) {
            return false;
        }

        $ActualCount   = count($CurrentQueue);
        if ($ActualCount <= 1 || !isset($CurrentQueue[$QueueID - 2])) {
            // Stale request: never cancel the running research by accident
            return false;
        }

        if (!isset($CurrentQueue[$QueueID - 1])) {
            return false;
        }

        // Later levels of the removed element depend on it and are dropped too
        $elementId      = $CurrentQueue[$QueueID - 1][self::QUEUE_ELEMENT_ID];
        $BuildEndTime   = $CurrentQueue[$QueueID - 2][self::QUEUE_BUILD_END_TIME];
        unset($CurrentQueue[$QueueID - 1]);
        $NewCurrentQueue = [];
        foreach ($CurrentQueue as $ID => $ListIDArray) {
            if ($ID < $QueueID - 1) {
                $NewCurrentQueue[] = $ListIDArray;
            } else {
                if ($elementId == $ListIDArray[self::QUEUE_ELEMENT_ID]) {
                    continue;
                }

                if ($ListIDArray[self::QUEUE_PLANET_ID] != $PLANET['id']) {
                    $db = $this->db;

                    $CPLANET = $db->fetchRow(
                        $db->qb()
                            ->select($this->elements()->column(university), $this->elements()->column(RESEARCH_LABORATORY), 'id')
                            ->from($db->table('PLANETS'))
                            ->where('id = :id')
                            ->setParameter('id', $ListIDArray[self::QUEUE_PLANET_ID]),
                    );
                } else {
                    $CPLANET = $PLANET;
                }

                $CPLANET[$this->elements()->column(RESEARCH_LABORATORY) . '_inter'] = $this->ecoObj->getNetworkLevel($this->state->USER, $CPLANET);

                $BuildEndTime += $this->buildFunctions()->getBuildingTime($this->state->USER, $CPLANET, $ListIDArray[self::QUEUE_ELEMENT_ID], null, false, $ListIDArray[1]);
                $ListIDArray[self::QUEUE_BUILD_END_TIME] = $BuildEndTime;
                $NewCurrentQueue[] = $ListIDArray;
            }
        }

        if (!empty($NewCurrentQueue)) {
            $this->state->USER['b_tech_queue'] = StoredData::encode($NewCurrentQueue);
        } else {
            $this->state->USER['b_tech_queue'] = "";
        }

        return true;
    }

    /**
     * Adds a research to the player's research queue (research is never torn down). Refused when the element is
     * not allowed here, disabled, its requirements are missing, the lab or university is being upgraded, the queue limit `max_elements_tech` is reached or the maximum
     * level is hit; checks affordability for the first entry, deducts the cost and starts it when the queue was empty.
     */
    private function addResearchToQueue(int $elementId): bool
    {

        if (
            !in_array($elementId, $this->elements()->ids('tech'))
            || !$this->buildFunctions()->isEnabled($elementId)
            || !$this->buildFunctions()->isTechnologieAccessible($this->state->USER, $this->state->PLANET, $elementId)
        ) {
            return false;
        }

        if (!$this->checkLabSettingsInQueue()) {
            $this->rejection = 'bd_building_lab';

            return false;
        }

        $CurrentQueue       = !empty($this->state->USER['b_tech_queue']) ? StoredData::decode($this->state->USER['b_tech_queue']) : [];

        if (!empty($CurrentQueue)) {
            $ActualCount    = count($CurrentQueue);
        } else {
            $CurrentQueue   = [];
            $ActualCount    = 0;
        }

        if ($this->configs->get()->max_elements_tech != 0 && $this->configs->get()->max_elements_tech <= $ActualCount) {
            $this->rejection = 'bd_researclist_full';

            return false;
        }

        if ($this->state->PLANET['watch_id'] == $elementId) {
            $this->state->PLANET['watch_id'] = 0;
            $db = $this->db;
            $db->update(
                'UPDATE %%PLANETS%% SET watch_id = :watch_id WHERE id = :planetId;',
                [
                    ':watch_id' => $this->state->PLANET['watch_id'],
                    ':planetId' => $this->state->PLANET['id'],
                ],
            );
        }

        $BuildLevel                 = $this->state->USER[$this->elements()->column($elementId)] + 1;
        if ($ActualCount == 0) {
            return $this->startResearch($elementId, $BuildLevel);
        }

        return $this->appendResearch($CurrentQueue, $elementId, $BuildLevel);
    }

    /**
     * Starts the first entry of an empty research queue: refused above the maximum level or when not affordable, otherwise
     * deducts the cost and sets the running research (queue entry, end time, id and planet) on the user.
     */
    private function startResearch(int $elementId, int $BuildLevel): bool
    {
        if ($this->elements()->get($elementId)->maxLevel < $BuildLevel) {
            $this->rejection = 'bd_maxlevel';

            return false;
        }

        $costResources      = $this->buildFunctions()->getElementPrice($this->state->USER, $this->state->PLANET, $elementId, false, $BuildLevel);

        if (!$this->buildFunctions()->isElementBuyable($this->state->USER, $this->state->PLANET, $elementId, $costResources)) {
            $this->rejection = 'bd_remaining';

            return false;
        }

        $this->ecoObj->removeResources($this->state->PLANET['id'], $costResources, $this->state->PLANET);

        $elementTime                = $this->buildFunctions()->getBuildingTime($this->state->USER, $this->state->PLANET, $elementId, $costResources);
        $BuildEndTime               = $this->state->runtime->timestamp + $elementTime;

        $this->state->USER['b_tech_queue'] = StoredData::encode([[$elementId, $BuildLevel, $elementTime, $BuildEndTime, $this->state->PLANET['id'], $costResources]]);
        $this->state->USER['b_tech'] = $BuildEndTime;
        $this->state->USER['b_tech_id'] = $elementId;
        $this->state->USER['b_tech_planet'] = $this->state->PLANET['id'];

        return true;
    }

    /**
     * Appends a research to a non-empty queue: the level is the player's level plus the entries already queued for the
     * element (no cost now, it is taken when the entry starts); refused above the maximum level. The entry ends after the last queued one.
     *
     * @param array<int, array<int, mixed>> $CurrentQueue the decoded queue (not empty)
     */
    private function appendResearch(array $CurrentQueue, int $elementId, int $BuildLevel): bool
    {
        $ActualCount = count($CurrentQueue);

        $addLevel = 0;
        foreach ($CurrentQueue as $QueueSubArray) {
            if ($QueueSubArray[self::QUEUE_ELEMENT_ID] != $elementId) {
                continue;
            }

            $addLevel++;
        }

        $BuildLevel                 += $addLevel;

        if ($this->elements()->get($elementId)->maxLevel < $BuildLevel) {
            $this->rejection = 'bd_maxlevel';

            return false;
        }

        $elementTime = $this->buildFunctions()->getBuildingTime($this->state->USER, $this->state->PLANET, $elementId, null, false, $BuildLevel);

        $BuildEndTime = $CurrentQueue[$ActualCount - 1][self::QUEUE_BUILD_END_TIME] + $elementTime;
        $CurrentQueue[] = [$elementId, $BuildLevel, $elementTime, $BuildEndTime, $this->state->PLANET['id']];
        $this->state->USER['b_tech_queue'] = StoredData::encode($CurrentQueue);

        return true;
    }

    /**
     * Data for the research queue display: name, level, remaining time and end time per entry.
     *
     * @return array<mixed>
     */
    private function getQueueData(): array
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;
        $scriptData     = [];
        $quickinfo      = [];

        if ($USER['b_tech'] == 0) {
            return ['queue' => $scriptData, 'quickinfo' => $quickinfo];
        }

        $CurrentQueue   = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];

        foreach ($CurrentQueue as $BuildArray) {
            if ($BuildArray[self::QUEUE_BUILD_END_TIME] < $this->state->runtime->timestamp && !$USER['urlaubs_modus']) {
                continue;
            }

            $PlanetName = '';

            $quickinfo[$BuildArray[self::QUEUE_ELEMENT_ID]]  = $BuildArray[self::QUEUE_LEVEL];

            if ($BuildArray[self::QUEUE_PLANET_ID] != $PLANET['id']) {
                $PlanetName     = $USER['PLANETS'][$BuildArray[self::QUEUE_PLANET_ID]]['name'];
            }

            $scriptData[] = [
                'element'   => $BuildArray[self::QUEUE_ELEMENT_ID],
                'level'     => $BuildArray[self::QUEUE_LEVEL],
                'time'      => $BuildArray[self::QUEUE_ELEMENT_TIME],
                'resttime'  => $USER['urlaubs_modus'] ? ($BuildArray[self::QUEUE_BUILD_END_TIME] - $USER['urlaubs_start']) : ($BuildArray[self::QUEUE_BUILD_END_TIME] - $this->state->runtime->timestamp),
                'destroy'   => false,
                'endtime'   => $this->viewHelpers()->formatDate('U', $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'display'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BuildArray[self::QUEUE_BUILD_END_TIME], $USER['timezone']),
                'planet'    => $PlanetName,
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
            [':watch_id' => max(0, $this->input()->int('watch')),
                ':planetId' => $this->state->PLANET['id'],
            ],
        );

        return $this->redirectToRoute('game_research_show');
    }

    /** Whether the player has every research (account-level) requirement of $elementId; building requirements of the planet are ignored. */
    private function researchRequirementsMet(int $elementId): bool
    {
        foreach ($this->elements()->get($elementId)->requirements as $requireID => $level) {
            $column = $this->elements()->column($requireID);
            if (!isset($this->state->PLANET[$column]) && ($this->state->USER[$column] ?? 0) < $level) {
                return false;
            }
        }

        return true;
    }

    /** Whether the current planet has a research lab (the page offers commands only then). */
    private function hasLab(): bool
    {
        return $this->state->PLANET[$this->elements()->column(RESEARCH_LABORATORY)] != 0;
    }

    /**
     * Queue command (POST, with a research lab on the current planet; request: cmd = cancel, remove or insert with tech = element id and
     * listid = queue position): runs it and redirects back to the page. Without a command, or in vacation mode, the page is shown as it is.
     */
    #[GameRoute('game', 'game/research', 'research', 'command', ['POST'])]
    public function command(): Response
    {
        $TheCommand = $this->hasLab() ? $this->input()->string('cmd') : '';
        if ($watched = $this->watchRedirect()) {
            return $watched;
        }

        if (!empty($TheCommand) && $this->state->USER['urlaubs_modus'] == 0) {
            $this->state->PLANET[$this->elements()->column(RESEARCH_LABORATORY) . '_inter'] = $this->resourceUpdate()->getNetworkLevel($this->state->USER, $this->state->PLANET);

            return $this->runQueueCommand($TheCommand, $this->input()->int('tech'), $this->input()->int('listid'));
        }

        return $this->show();
    }

    /**
     * Research page. Request: watch stores the element watched by the cargo calculator and redirects back. Otherwise renders the research
     * list with costs, durations, requirements and the queue.
     */
    #[GameRoute('game', 'game/research', 'research')]
    public function show(): Response
    {
        if ($watched = $this->watchRedirect()) {
            return $watched;
        }

        $LNG = $this->state->LNG;
        $hasLab         = $this->hasLab();
        $catchupFactor  = $this->protectionRules()->catchUpFactorFor($this->player());

        $this->state->PLANET[$this->elements()->column(RESEARCH_LABORATORY) . '_inter'] = $this->resourceUpdate()->getNetworkLevel($this->state->USER, $this->state->PLANET);

        $bContinue      = $this->checkLabSettingsInQueue();

        $queueData      = $this->getQueueData();
        $TechQueue      = $queueData['queue'];
        $QueueCount     = count($TechQueue);
        $config         = $this->configs->get();

        $ResearchList = $this->researchList($queueData, $QueueCount, $catchupFactor, $config);

        return $this->render('game/page.research.default.html.twig', [
            'umode'         => $this->state->USER['urlaubs_modus'],
            'ResearchList'  => $ResearchList,
            'IsLabinBuild'  => !$bContinue,
            'hasLab'        => $hasLab,
            'IsFullQueue'   => $this->configs->get()->max_elements_tech != 0
                && count($TechQueue) >= $this->configs->get()->max_elements_tech,
            'Queue'         => $TechQueue,
            'queueSlots'    => max((int) $this->configs->get()->max_elements_tech, count($TechQueue)),
            'isCatchUp'     => $config->catch_up,
            'catchUp'       => $LNG->format('sys_catchup', round((1 - $catchupFactor) * 100, 2)),
            'hide_useless'  => $this->state->USER['hide_useless'],
            'watch'         => $this->state->PLANET['watch_id'],
        ]);
    }

    /**
     * Executes a queue command (cancel, remove, insert; research cannot be torn down, so `destroy` is refused like any unknown
     * command: no cost, no queue entry) in a transaction with the user, the current planet and all
     * planets of the player locked, saves the research queue unless the command is unknown, and redirects back to the page.
     *
     * @param string $TheCommand request cmd
     * @param int $elementId request tech (element id)
     * @param int $ListID request listid (queue position)
     */
    private function runQueueCommand(string $TheCommand, int $elementId, int $ListID): Response
    {
        $db = $this->db;
        $db->startTransaction();
        $this->state->USER = $db->selectSingle("SELECT u.*, us.* FROM %%USERS%% u JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE id = :userID FOR UPDATE;", [':userID' => $this->state->USER['id']]);
        $this->state->PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $this->state->PLANET['id']]);
        $db->select("SELECT * FROM %%PLANETS%% WHERE id_owner = :userID FOR UPDATE;", [':userID' => $this->state->USER['id']]);
        $this->state->PLANET[$this->elements()->column(RESEARCH_LABORATORY) . '_inter'] = $this->resourceUpdate()->getNetworkLevel($this->state->USER, $this->state->PLANET);
        $validCommand = true;
        switch ($TheCommand) {
            case 'cancel':
                $this->cancelResearchFromQueue();
                break;
            case 'remove':
                $this->removeResearchFromQueue($ListID);
                break;
            case 'insert':
                $this->addResearchToQueue($elementId);
                break;
            default:
                error_log($TheCommand);
                $validCommand = false;
                break;
        }

        if ($validCommand) {
            $this->ecoObj->saveTechQueue($this->state->USER);
        }
        $db->commit();

        // a refused insert says why instead of silently reloading the page
        if ($TheCommand == 'insert' && $this->rejection !== null) {
            return $this->messageResponse($this->state->LNG[$this->rejection], [['label' => $this->state->LNG['bd_continue'], 'url' => $this->router->generate('game_research_show')]]);
        }

        return $this->redirectToRoute('game_research_show');
    }

    /**
     * Seconds until the planet's production (incl. basic income) covers the missing resources of $costOverflow; the slowest
     * resource counts; 0 when a missing resource is not produced or the price exceeds the storage capacity.
     *
     * @param array<int, int|float> $costOverflow resources still missing
     * @param array<int, int|float> $costResources the price per resource
     * @param array<int, int|float> $production hourly production per resource
     */
    private function timeToAfford(array $costOverflow, array $production, array $costResources): int|float
    {
        $timetobuild = 0;
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            if (array_key_exists($resourceId, $costOverflow) && $costOverflow[$resourceId] != 0) {
                // never reachable: nothing is produced, or the price is above the storage capacity
                if ($production[$resourceId] <= 0 || ($costResources[$resourceId] ?? 0) > $this->state->PLANET[$this->elements()->column($resourceId) . '_max']) {
                    return 0;
                }
                $timetobuild = max($timetobuild, $costOverflow[$resourceId] / $production[$resourceId]);
            }
        }

        return floor($timetobuild * 3600);
    }

    /**
     * Template rows of all researches: level, cost, duration, wait time until the cost is affordable, requirements still
     * missing (only when the player shows all buildable elements), and the display flags (fade, buyable, catch-up factor,
     * useful maximum level). The level after the queued work is the base for the next costs.
     *
     * @param array{queue: array, quickinfo: array<int, int>} $queueData from getQueueData()
     * @param array<mixed> $queueData
     * @return array<int, array<string, mixed>> tech id => row
     */
    private function researchList(array $queueData, int $QueueCount, float $catchupFactor, object $config): array
    {

        $ResearchList   = [];
        $production = [
            RESOURCE_METAL => $this->state->PLANET['metal_perhour'] + $config->metal_basic_income * ($config->resource_multiplier / 2500),
            RESOURCE_CRYSTAL => $this->state->PLANET['crystal_perhour'] + $config->crystal_basic_income * ($config->resource_multiplier / 2500),
            RESOURCE_DEUT => $this->state->PLANET['deuterium_perhour']  + $config->deuterium_basic_income * ($config->resource_multiplier / 2500),
        ];

        // the page only reads from here on: one typed snapshot (with the research network labs) serves the whole listing
        $userModel = $this->models()->user($this->state->USER);
        $planetModel = $this->models()->planet($this->state->PLANET);

        foreach ($this->elements()->ids('tech') as $elementId) {
            if (!$this->buildFunctions()->isEnabled($elementId)) {
                continue;
            }
            $requirementsList = [];
            $accessible = $this->buildFunctions()->isAccessibleFor($userModel, $planetModel, $elementId);
            // research is account-wide: a tech that only lacks buildings of this planet (lab level) gets the lock + "on this planet" reason
            $blockedHere = !$accessible && $this->researchRequirementsMet($elementId);
            // every enabled tech is listed; missing requirements lock it (template: needs + greyed-out button)
            if (!$accessible) {
                if ($this->elements()->get($elementId)->requirements !== []) {
                    foreach ($this->elements()->get($elementId)->requirements as $requireID => $RedCount) {
                        $requirementsList[$requireID] = [
                            'count' => $RedCount,
                            'own'   => isset($this->state->PLANET[$this->elements()->column($requireID)]) ? $this->state->PLANET[$this->elements()->column($requireID)] : $this->state->USER[$this->elements()->column($requireID)],
                        ];
                    }
                }
            }

            if (isset($queueData['quickinfo'][$elementId])) {
                $levelToBuild   = $queueData['quickinfo'][$elementId];
            } else {
                $levelToBuild   = $this->state->USER[$this->elements()->column($elementId)];
            }

            $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $elementId, false, $levelToBuild + 1);
            $costOverflow = $this->buildFunctions()->restPriceFor($userModel, $planetModel, $elementId, $costResources);
            $timetobuild = $this->timeToAfford($costOverflow, $production, $costResources);
            $elementTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $elementId, $costResources);
            $buyable = $QueueCount != 0 || $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $elementId, $costResources);

            $fade = ($this->state->USER['missing_requirements_opacity'] && !empty($requirementsList))
                || ($this->state->USER['missing_resources_opacity'] && !$buyable)
                || $this->elements()->get($elementId)->maxLevel == $levelToBuild;

            $max_useful_level = $this->elements()->get($elementId)->maxUsefulLevel;

            $factor = $this->protectionRules()->getCatchUpFactorForElement($catchupFactor, $elementId, $levelToBuild + 1);

            $ResearchList[$elementId]   = [
                'id'                => $elementId,
                'level'             => $this->state->USER[$this->elements()->column($elementId)],
                'maxLevel'          => $this->elements()->get($elementId)->maxLevel,
                'costResources'     => $costResources,
                'costOverflow'      => $costOverflow,
                'elementTime'       => $elementTime,
                'buyable'           => $buyable,
                'levelToBuild'      => $levelToBuild,
                'timetobuild'       => $timetobuild,
                'requirements'      => $requirementsList,
                'fade'              => $fade,
                'max_useful_level'  => $max_useful_level,
                'factor'            => $catchupFactor != $factor ? round((1 - $factor) * 100, 2) : null,
                'blockedHere'       => $blockedHere,
            ];
        }

        return $ResearchList;
    }
}
