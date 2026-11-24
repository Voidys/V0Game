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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\HangarOrderPlanner;
use App\Game\Core\StoredData;

/** Shipyard and defence (/game/shipyard, /game/defense): build queue, ship/defence lists, orders and hangar filters. */
class ShipyardController extends AbstractGameController
{
    private const QUEUE_ELEMENT_ID = 0;
    private const QUEUE_AMMOUNT = 1;

    /**
     * Cancels hangar queue entries (request: auftr[] queue positions; the running entry at position 0 can not be cancelled) and refunds their
     * cost. False when nothing was cancelled.
     */
    private function cancelAuftr(): bool
    {
        $USER = $this->state->USER;
        $ElementQueue = !empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [];

        $CancelArray = $this->input()->intList('auftr');

        foreach ($CancelArray as $Auftr) {
            if (!isset($ElementQueue[$Auftr])) {
                continue;
            }

            if ($Auftr == 0) {
                $this->state->PLANET['b_hangar'] = 0;
            }

            $Element = $ElementQueue[$Auftr][self::QUEUE_ELEMENT_ID];
            $Count = $ElementQueue[$Auftr][self::QUEUE_AMMOUNT];

            $costResources = $this->buildFunctions()->getElementPrice($USER, $this->state->PLANET, $Element, false, $Count);

            $this->ecoObj->addResources($this->state->PLANET['id'], $costResources, $this->state->PLANET, FACTOR_CANCEL_SHIPYARD);

            unset($ElementQueue[$Auftr]);
        }

        if (empty($ElementQueue)) {
            $this->state->PLANET['b_hangar_id'] = '';
        } else {
            $this->state->PLANET['b_hangar_id'] = StoredData::encode(array_values($ElementQueue));
        }

        $this->ecoObj->saveShipyardQueue($this->state->PLANET);

        return true;
    }

    /**
     * Queues ships/defense from fmenge[<element id>] = counts. Each count is limited by `max_fleet_per_build`, the affordable amount (resources
     * already used by earlier entries of the same order are not counted again), the queue length limit, silo capacity for missiles and "one only"
     * elements (shields). Deducts the cost, records shipyard achievements and starts the queue when it was empty.
     *
     * @param array<mixed> $fmenge
     * @param bool $upTo the counts are target totals (owned + queued + ordered), not amounts to add
     */
    private function buildAuftr(array $fmenge, bool $upTo = false): void
    {
        $USER = $this->state->USER;
        // Working copy: resources already committed to earlier entries of this order are not available again
        $availablePlanet = $this->state->PLANET;
        $maxBuildQueue = $this->configs->get()->max_elements_ships;

        $totalCostResources = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];
        foreach ($fmenge as $Element => $Count) {
            // the key is request input ("407.0" would pass the loose list check but find no column): use the integer id only
            $Element = is_numeric($Element) && (string) (int) $Element === (string) $Element ? (int) $Element : 0;
            if (
                empty($Count)
                || !in_array($Element, array_merge($this->elements()->ids('fleet'), $this->elements()->ids('defense'), $this->elements()->ids('missile')), true)
                || !$this->buildFunctions()->isEnabled($Element)
                || !$this->buildFunctions()->isTechnologieAccessible($USER, $this->state->PLANET, $Element)
            ) {
                continue;
            }

            $BuildArray = !empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [];
            if ($maxBuildQueue != 0 && count($BuildArray) >= $maxBuildQueue) {
                break;
            }

            $MaxElements = $this->buildFunctions()->getMaxConstructibleElements($USER, $availablePlanet, $Element);
            $Count = is_numeric($Count) ? round($Count) : 0;
            if ($upTo) {
                $Count = HangarOrderPlanner::upTo($Count, (float) $this->state->PLANET[$this->elements()->column($Element)], $this->queuedAmount($BuildArray, $Element));
            }
            $Count = max(min($Count, $this->configs->get()->max_fleet_per_build), 0);
            $Count = min($Count, $MaxElements);

            if (in_array($Element, $this->elements()->ids('missile'))) {
                // Owned and already queued missiles are both counted by getMaxConstructibleRockets()
                $MaxMissiles = $this->buildFunctions()->getMaxConstructibleRockets($this->state->PLANET);
                $Count = min($Count, $MaxMissiles[$Element]);
            } elseif (in_array($Element, $this->elements()->ids('one'))) {
                $InBuild = false;
                foreach ($BuildArray as $ElementArray) {
                    if ($ElementArray[self::QUEUE_ELEMENT_ID] == $Element) {
                        $InBuild = true;
                        break;
                    }
                }

                if ($InBuild || $this->state->PLANET[$this->elements()->column($Element)] != 0) {
                    continue;
                }

                if ($Count != 0) {
                    $Count = 1;
                }
            }

            if (empty($Count)) {
                continue;
            }

            $costResources = $this->buildFunctions()->getElementPrice($USER, $this->state->PLANET, $Element, false, $Count);

            foreach ($costResources as $resourceId => $amount) {
                $totalCostResources[$resourceId] += $amount;
                $availablePlanet[$this->elements()->column($resourceId)] -= $amount;
            }

            $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkShipyardAchievements($USER['id'], $Count, $Element));
            $BuildArray[] = [$Element, $Count];
            $this->state->PLANET['b_hangar_id'] = StoredData::encode($BuildArray);
        }
        $this->ecoObj->removeResources($this->state->PLANET['id'], $totalCostResources, $this->state->PLANET);
        $this->ecoObj->saveShipyardQueue($this->state->PLANET);
    }

    /**
     * Units of $Element in the hangar queue entries $queue.
     *
     * @param array<int, array<int, mixed>> $queue
     */
    private function queuedAmount(array $queue, int $Element): float
    {
        $queued = 0.0;
        foreach ($queue as $queueEntry) {
            if ($queueEntry[self::QUEUE_ELEMENT_ID] == $Element) {
                $queued += $queueEntry[self::QUEUE_AMMOUNT];
            }
        }

        return $queued;
    }

    /**
     * Ship list (`/game/shipyard`) or defence list (`/game/defense`): `kind` is the placeholder of the path. Needs a shipyard; shows buildable
     * elements, costs, times and the hangar queue.
     */
    #[GameRoute('game', 'game/{kind}', 'shipyard', requirements: ['kind' => 'shipyard|defense'])]
    public function show(): Response
    {
        return $this->hangar(false);
    }

    /**
     * Orders of the shipyard or defence form (POST, request: fmenge[<element id>] counts to build, or action = delete with auftr[] queue
     * positions to cancel); redirects back to the same list. Without an order, or while the shipyard or nanite factory is upgrading, or in
     * vacation mode, the list is shown as it is.
     */
    #[GameRoute('game', 'game/{kind}', 'shipyard', 'order', ['POST'], ['kind' => 'shipyard|defense'])]
    public function order(): Response
    {
        return $this->hangar(true);
    }

    /**
     * The shipyard or defence page of the requested kind (path placeholder `kind`), after the order of the request when $withOrder is set.
     * Without a shipyard on the planet the list is shown locked and orders are refused.
     */
    private function hangar(bool $withOrder): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        // without a shipyard the list stays visible (every element needs one, so all are locked), orders are refused
        $hasShipyard = $this->state->PLANET[$this->elements()->column(SHIPYARD)] != 0;
        if (!$hasShipyard && $withOrder) {
            return $this->messageResponse($LNG['bd_shipyard_required']);
        }

        $mode = $this->input()->string('kind') === 'defense' ? 'defense' : 'fleet';

        $NotBuilding = !$this->isShipyardUpgrading();

        $ElementQueue = !empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [];
        if (empty($ElementQueue)) {
            $Count = 0;
        } else {
            $Count = count($ElementQueue);
        }

        if ($withOrder && $USER['urlaubs_modus'] == 0 && $NotBuilding == true) {
            $answer = $this->runHangarCommand($this->input()->bigNumberMap('fmenge'), $this->input()->string('action'), $mode, $Count);
            if ($answer !== null) {
                return $answer;
            }
        }

        $elementInQueue = [];
        $buildList = $this->hangarQueueData($elementInQueue);

        if ($mode == 'defense') {
            $elementIDs = array_merge($this->elements()->ids('defense'), $this->elements()->ids('missile'));
        } else {
            $elementIDs = $this->elements()->ids('fleet');
        }

        $SolarEnergy = round((($this->state->PLANET['temp_max'] + 160) / 6) * $this->configs->get()->energy_multiplier, 1);
        $elementList = $this->elementList($elementIDs, $elementInQueue);

        [$civil, $military, $recycler] = $this->filterSelectors($elementList);

        $hangarFilters = $this->hangarFilters()->parse($USER['hangar_filters'] ?? null);
        $hangarShipIds = $this->hangarFilters()->shipIds($hangarFilters);
        $hangarShipOptions = $this->unpinnedShipOptions($hangarShipIds);

        return $this->render('game/page.shipyard.default.html.twig', [
            'umode'       => $USER['urlaubs_modus'],
            'elementList' => $elementList,
            'NotBuilding' => $NotBuilding,
            'hasShipyard' => $hasShipyard,
            'BuildList' => $buildList,
            'queueTimeLeft' => $buildList['queue_time'] ?? 0,
            'maxlength' => strlen($this->configs->get()->max_fleet_per_build),
            'mode' => $mode,
            'SolarEnergy' => $SolarEnergy,
            'civil' => $civil,
            'military' => $military,
            'recycler' => $recycler,
            'hangarFiltersEnabled' => !empty($USER['hangar_filters_enabled']),
            'hangarGroupCivil' => $this->hangarFilters()->hasGroup($hangarFilters, 'civil'),
            'hangarGroupMilitary' => $this->hangarFilters()->hasGroup($hangarFilters, 'military'),
            'hangarGroupRecycler' => $this->hangarFilters()->hasGroup($hangarFilters, 'recycler'),
            'hangarGroupAll' => $this->hangarFilters()->hasGroup($hangarFilters, 'all'),
            'hangarShipIds' => $hangarShipIds,
            'hangarShipOptions' => $hangarShipOptions,
            'hangarShipOrder' => array_map('intval', array_values($this->elements()->ids('fleet'))),
        ]);
    }

    /**
     * CSS selector lists ("#s202, #s203") of the civil, military and recycler ships in $elementList, used by the hangar filter buttons.
     *
     * @param array<int, array<string, mixed>> $elementList rows from elementList() with their 'type'
     * @return array{0: string, 1: string, 2: string} civil, military, recycler selectors
     */
    private function filterSelectors(array $elementList): array
    {
        $civil = "";
        $military = "";
        $recycler = "";
        foreach ($elementList as $Element => $ElementData) {
            if ($ElementData['type'] == 'civil') {
                $civil .= "#s" . $Element . ", ";
            } elseif ($ElementData['type'] == 'military') {
                $military .= "#s" . $Element . ", ";
            } elseif ($ElementData['type'] == 'recycler') {
                $recycler .= "#s" . $Element . ", ";
            }
        }

        return [rtrim($civil, ", "), rtrim($military, ", "), rtrim($recycler, ", ")];
    }

    /**
     * Ships that can still be pinned to the hangar filter (all ships except the already pinned ones) with their short name.
     *
     * @param array<int, int> $hangarShipIds ids pinned already
     * @return array<int, string> ship id => name
     */
    private function unpinnedShipOptions(array $hangarShipIds): array
    {
        $LNG = $this->state->LNG;
        $hangarShipOptions = [];
        foreach ($this->elements()->ids('fleet') as $shipId) {
            $shipId = (int) $shipId;
            if (in_array($shipId, $hangarShipIds, true)) {
                continue;
            }
            $hangarShipOptions[$shipId] = $LNG['shortNames'][$shipId] ?? $LNG['tech'][$shipId] ?? ('#' . $shipId);
        }

        return $hangarShipOptions;
    }

    /**
     * Whether the shipyard or the nanite factory is in the planet's building queue (the shipyard is blocked then).
     */
    private function isShipyardUpgrading(): bool
    {
        if (empty($this->state->PLANET['b_building_id'])) {
            return false;
        }

        foreach (StoredData::decode($this->state->PLANET['b_building_id']) as $ElementArray) {
            if ($ElementArray[self::QUEUE_ELEMENT_ID] == SHIPYARD || $ElementArray[self::QUEUE_ELEMENT_ID] == NANITE_FACTORY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Executes the order commands of the request on the locked planet row: `fmenge` (the counts to build, refused with a
     * message when the hangar queue is full) or action=delete (cancel queue entries), then redirects back to the same
     * list. Null when the request carries neither.
     *
     * @param array<int|string, float> $buildTodo request fmenge (element id => count)
     * @param int $Count number of entries already in the hangar queue
     */
    private function runHangarCommand(array $buildTodo, string $action, string $mode, int $Count): ?Response
    {
        $LNG = $this->state->LNG;
        if (!empty($buildTodo)) {
            $maxBuildQueue = $this->configs->get()->max_elements_ships;
            if ($maxBuildQueue != 0 && $Count >= $maxBuildQueue) {
                return $this->messageResponse($LNG->format('bd_max_builds', $maxBuildQueue));
            }

            $db = $this->db;
            $db->startTransaction();
            $this->state->PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $this->state->PLANET['id']]);
            if ($this->isShipyardUpgrading()) {
                // a shipyard/nanite upgrade was queued after the page started: judged on the locked row
                $db->rollback();

                return $this->redirectToHangar($mode);
            }
            $this->buildAuftr($buildTodo, $this->input()->string('orderMode') === 'upto');
            $db->commit();

            return $this->redirectToHangar($mode);
        }

        if ($action == "delete") {
            $db = $this->db;
            $db->startTransaction();
            $this->state->PLANET = $db->selectSingle("SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;", [':planetId' => $this->state->PLANET['id']]);
            $this->cancelAuftr();
            $db->commit();

            return $this->redirectToHangar($mode);
        }

        return null;
    }

    /** redirect back to the ship list (mode fleet) or the defence list */
    private function redirectToHangar(string $mode): Response
    {
        return $this->redirectToRoute('game_shipyard_show', ['kind' => $mode === 'defense' ? 'defense' : 'shipyard']);
    }

    /**
     * Template data of the running hangar queue (entries with name, amount and build time, time already spent, remaining
     * queue time), empty array when the queue is empty.
     *
     * @param array<int, bool> $elementInQueue filled with the element ids found in the queue (to block one-time elements)
     * @return array<string, mixed>
     */
    private function hangarQueueData(array &$elementInQueue): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $ElementQueue = !empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [];
        $buildList = [];

        if (!empty($ElementQueue)) {
            $Shipyard = [];
            $QueueTime = 0;
            foreach ($ElementQueue as $Element) {
                if (empty($Element)) {
                    continue;
                }

                $elementInQueue[$Element[self::QUEUE_ELEMENT_ID]] = true;
                $ElementTime = $this->buildFunctions()->getBuildingTime($USER, $this->state->PLANET, $Element[self::QUEUE_ELEMENT_ID]);
                $QueueTime += $ElementTime * $Element[self::QUEUE_AMMOUNT];
                $Shipyard[] = [$LNG['tech'][$Element[self::QUEUE_ELEMENT_ID]], $Element[self::QUEUE_AMMOUNT], $ElementTime, $Element[self::QUEUE_ELEMENT_ID]];
            }

            $buildList = [
                'Queue' => $Shipyard,
                'b_hangar_id_plus' => $this->state->PLANET['b_hangar'],
                'queue_time' => max($QueueTime - $this->state->PLANET['b_hangar'], 0),
                'pretty_time_b_hangar' => $this->viewHelpers()->prettyTime(max($QueueTime - $this->state->PLANET['b_hangar'], 0)),
            ];
        }

        return $buildList;
    }

    /**
     * Template rows of the buildable ships/defenses/missiles in $elementIDs: costs, build time, affordable amount (capped
     * by the silo capacity for missiles), requirements still missing (only when the player shows all buildable
     * elements), "already built" for one-time elements and the display flags.
     *
     * @param list<int> $elementIDs elements to list
     * @param array<int, bool> $elementInQueue element ids that are in the hangar queue
     * @return array<int, array<string, mixed>> element id => row
     */
    private function elementList(array $elementIDs, array $elementInQueue): array
    {
        $USER = $this->state->USER;
        $elementList = [];
        $Missiles = [];
        $queuedCounts = [];
        foreach (!empty($this->state->PLANET['b_hangar_id']) ? StoredData::decode($this->state->PLANET['b_hangar_id']) : [] as $queueEntry) {
            $queuedCounts[$queueEntry[self::QUEUE_ELEMENT_ID]] = ($queuedCounts[$queueEntry[self::QUEUE_ELEMENT_ID]] ?? 0) + $queueEntry[self::QUEUE_AMMOUNT];
        }

        foreach ($this->elements()->ids('missile') as $elementID) {
            $Missiles[$elementID] = $this->state->PLANET[$this->elements()->column($elementID)];
        }

        // the page only reads from here on: one typed snapshot of the player and the planet serves the whole listing
        $userModel = $this->models()->user($USER);
        $planetModel = $this->models()->planet($this->state->PLANET);

        $MaxMissiles = $this->buildFunctions()->maxRocketsFor($planetModel, $Missiles);

        foreach ($elementIDs as $Element) {
            if (!$this->buildFunctions()->isEnabled($Element)) {
                continue;
            }
            $requirementsList = [];
            // every enabled ship/defense is listed; missing requirements lock it (template: needs + greyed-out button)
            if (!$this->buildFunctions()->isAccessibleFor($userModel, $planetModel, $Element)) {
                if ($this->elements()->get($Element)->requirements !== []) {
                    foreach ($this->elements()->get($Element)->requirements as $requireID => $RedCount) {
                        $requirementsList[$requireID] = [
                            'count' => $RedCount,
                            'own'   => isset($this->state->PLANET[$this->elements()->column($requireID)]) ? $this->state->PLANET[$this->elements()->column($requireID)] : $USER[$this->elements()->column($requireID)],
                        ];
                    }
                }
            }

            $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $Element);
            $costOverflow = $this->buildFunctions()->restPriceFor($userModel, $planetModel, $Element, $costResources);
            $elementTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Element, $costResources);
            $buyable = $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $Element, $costResources);
            $maxBuildable = $this->buildFunctions()->maxConstructibleFor($userModel, $planetModel, $Element, $costResources);
            if (str_starts_with(strval($Element), 2)) {
                $type = $this->buildFunctions()->getType($Element);
            } elseif (str_starts_with(strval($Element), 4)) {
                $type = "defense";
            } else {
                $type = "missile";
            }

            if (isset($MaxMissiles[$Element])) {
                $maxBuildable = min($maxBuildable, $MaxMissiles[$Element]);
            }
            $maxBuildable = min($maxBuildable, $this->configs->get()->max_fleet_per_build);

            $AlreadyBuild = in_array($Element, $this->elements()->ids('one'))
                && (isset($elementInQueue[$Element]) || $this->state->PLANET[$this->elements()->column($Element)] != 0);

            $fade = ($USER['missing_requirements_opacity'] && !empty($requirementsList))
                || ($USER['missing_resources_opacity'] && !$buyable)
                || $AlreadyBuild;

            $elementList[$Element] = [
                'id' => $Element,
                'available' => $this->state->PLANET[$this->elements()->column($Element)],
                'costResources' => $costResources,
                'costOverflow' => $costOverflow,
                'elementTime' => $elementTime,
                'buyable' => $buyable,
                'maxBuildable' => floatToString($maxBuildable),
                'queued' => $queuedCounts[$Element] ?? 0,
                'AlreadyBuild' => $AlreadyBuild,
                'requirements' => $requirementsList,
                'fade' => $fade,
                'type' => $type,
            ];
        }

        return $elementList;
    }

    /**
     * Persist pinned hangar ship filters without changing group visibility.
     */
    #[GameRoute('game', 'game/shipyard/filters', 'shipyard', 'saveFilters', ['POST'])]
    public function saveFilters(): Response
    {
        $ships = $this->input()->intList('ships');
        $filters = $this->hangarFilters()->mergeShips(
            $this->hangarFilters()->parse($this->state->USER['hangar_filters'] ?? null),
            $ships,
        );
        $encoded = $this->hangarFilters()->encode($filters);

        $this->db->update(
            'UPDATE %%USERS_SETTINGS%% SET hangar_filters = :filters WHERE user_id = :userID;',
            [
                ':filters' => $encoded,
                ':userID' => $this->state->USER['id'],
            ],
        );

        $this->state->USER['hangar_filters'] = $encoded;

        return $this->jsonResponse([
            'ok' => true,
            'filters' => $filters,
        ]);
    }
}
