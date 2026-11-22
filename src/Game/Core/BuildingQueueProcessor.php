<?php

namespace App\Game\Core;

/**
 * Building queue of a planet (b_building / b_building_id, StoredData JSON): finishes the first element when its end
 * time has passed and starts the next affordable one.
 *
 * Collaborators: BuildFunctions (price, build time, buyability, field projection), QueueFailureNotifier (messages),
 * QueueState (planet, built deltas, stock update callback).
 */
class BuildingQueueProcessor
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Finishes the first element of the planet's building queue when its end time has passed: brings the stock to the
     * end time, applies the new level (or destroys one level) and removes the element from the queue.
     *
     * @return bool true when more elements are queued (caller starts the next one)
     */
    public function finishFirst(QueueState $st)
    {
        $elements = $this->elements();
        if (empty($st->planet['b_building_id']) || empty($st->planet['b_building_id']) || $st->planet['b_building'] > $st->time) {
            return false;
        }

        $CurrentQueue = StoredData::decode($st->planet['b_building_id']);

        $Element = $CurrentQueue[0][0];
        $BuildLevel = $CurrentQueue[0][1];
        $BuildEndTime = $CurrentQueue[0][3];
        $BuildMode = $CurrentQueue[0][4];

        if (!isset($st->builded[$Element])) {
            $st->builded[$Element] = 0;
        }

        $OnHash = in_array($Element, $elements->ids('prod'));
        ($st->updateResource)($BuildEndTime, !$OnHash);

        if ($BuildMode == 'build') {
            $st->planet['field_current']      += 1;
            $st->planet[$elements->column($Element)]  = $BuildLevel;
            $st->builded[$Element]            = $BuildLevel;
        } else {
            $st->planet['field_current']      -= 1;
            $st->planet[$elements->column($Element)]  = $BuildLevel - 1;
            $st->builded[$Element]            = $BuildLevel - 1;
        }

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $st->planet['b_building'] = 0;
            $st->planet['b_building_id'] = '';

            return false;
        } else {
            $st->planet['b_building_id'] = StoredData::encode($CurrentQueue);
            return true;
        }
    }

    /**
     * Starts the first element of the building queue: pays it from the planet, sets its end time and stores the queue.
     * Elements that cannot be afforded, have no fields or no level left to destroy are dropped (with a message to the
     * user when users.hof is 1) and the next element is tried, with the remaining queue re-timed.
     */
    public function startNext(QueueState $st)
    {
        $elements = $this->elements();
        $notifier = $this->gameUtility(QueueFailureNotifier::class);
        $LNG = $notifier->language($st->user['id']);

        if (empty($st->planet['b_building_id'])) {
            $st->planet['b_building'] = 0;
            $st->planet['b_building_id'] = '';
            return false;
        }

        $CurrentQueue = !empty($st->planet['b_building_id']) ? StoredData::decode($st->planet['b_building_id']) : [];
        $Loop = true;

        $BuildEndTime = 0;
        $NewQueue = '';

        while ($Loop === true) {
            $ListIDArray = $CurrentQueue[0];
            $Element = $ListIDArray[0];
            $Level = $ListIDArray[1];
            $BuildMode = $ListIDArray[4];
            $ForDestroy = ($BuildMode == 'destroy') ? true : false;
            // snapshot per round: payFromWorkingPlanet() changes the state arrays between rounds
            $userModel = $this->models()->user($st->user);
            $planetModel = $this->models()->planet($st->planet);
            $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $Element, $ForDestroy, $Level);
            $BuildTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Element, $costResources, $ForDestroy);
            $HaveResources = $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $Element, $costResources);
            // Only the job that is about to start occupies a field now. Later queue
            // entries are still pending, and earlier ones are already on $st->planet.
            $HaveFields = $ForDestroy || $this->buildFunctions()->queueFieldProjectionFor($planetModel, [$ListIDArray])->valid;
            $BuildEndTime = $st->planet['b_building'] + $BuildTime;
            $CurrentQueue[0] = [$Element, $Level, $BuildTime, $BuildEndTime, $BuildMode, $costResources];
            $HaveNoMoreLevel = false;

            if ($ForDestroy && $st->planet[$elements->column($Element)] == 0) {
                $HaveResources = false;
                $HaveNoMoreLevel = true;
            }

            if ($HaveResources === true && $HaveFields === true) {
                $this->payFromWorkingPlanet($st, $costResources);
                // the waiting entries follow the started one: re-chain their durations and end times
                if (count($CurrentQueue) > 1) {
                    $st->planet['b_building_id'] = StoredData::encode($CurrentQueue);
                    $tail = $this->retimeBuildingQueue($st, array_slice($CurrentQueue, 1), $BuildEndTime, 1);
                    $CurrentQueue = array_merge([$CurrentQueue[0]], $tail);
                }
                $NewQueue = StoredData::encode($CurrentQueue);
                $Loop = false;
            } else {
                if ($st->user['hof'] == 1) {
                    if ($HaveNoMoreLevel) {
                        $Message = $LNG->format('sys_nomore_level', $LNG['tech'][$Element]);
                    } elseif ($HaveResources !== true) {
                        $Message = $notifier->notEnoughResources($LNG, $st->planet, $Element, $costResources);
                    } else {
                        $Message = $notifier->noMoreFields($LNG, $st->planet, $Element);
                    }

                    $notifier->send($LNG, $st->user['id'], 'sys_buildlist', $Message, $st->time);
                }

                array_shift($CurrentQueue);

                if (count($CurrentQueue) == 0) {
                    $BuildEndTime = 0;
                    $NewQueue = '';
                    $Loop = false;
                } else {
                    $CurrentQueue = $this->retimeBuildingQueue($st, $CurrentQueue, $BuildEndTime - $BuildTime);
                }
            }
        }

        $st->planet['b_building'] = $BuildEndTime;
        $st->planet['b_building_id'] = $NewQueue;

        return true;
    }

    /**
     * Deducts the cost of a started building from the working planet and from the built deltas.
     *
     * @param array<int, float|int> $costResources
     */
    private function payFromWorkingPlanet(QueueState $st, array $costResources): void
    {
        $elements = $this->elements();

        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            if (isset($costResources[$resourceId])) {
                $st->builded[$resourceId] -= $costResources[$resourceId];
                $st->planet[$elements->column($resourceId)] -= $costResources[$resourceId];
            }
        }
    }

    /**
     * Recomputes duration and end time of the remaining building queue entries, chained from $baseTime.
     *
     * @param array<int, array> $queue
     * @param array<mixed> $queue
     * @param int|null $preceding queue entries in front of $queue that count for queued robotics/nanite (null: none)
     * @return array<int, array>
     */
    private function retimeBuildingQueue(QueueState $st, array $queue, int|float $baseTime, ?int $preceding = null): array
    {
        $NewQueue = [];
        $userModel = $this->models()->user($st->user);
        $planetModel = $this->models()->planet($st->planet);
        foreach (array_values($queue) as $index => $ListIDArray) {
            $ListIDArray[2] = $this->buildFunctions()->buildingTimeFor(
                $userModel,
                $planetModel,
                $ListIDArray[0],
                null,
                $ListIDArray[4] == 'destroy',
                $ListIDArray[1],
                $preceding === null ? 0 : $preceding + $index,
            );
            $baseTime += $ListIDArray[2];
            $ListIDArray[3] = $baseTime;
            $NewQueue[] = $ListIDArray;
        }

        return $NewQueue;
    }
}
