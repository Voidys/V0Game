<?php

namespace App\Game\Core;

use App\Game\Engagement\Events\EventModifiers;

/**
 * Shipyard queue of a planet: turns the elapsed build time into finished ships.
 *
 * Collaborators: BuildFunctions (build time per ship), QueueState (planet, built deltas). Queue format: StoredData
 * JSON list of [elementId, count] in planets.b_hangar_id, elapsed time in planets.b_hangar.
 */
class HangarQueueProcessor
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /** most checkpoints one shipyard entry adds per update (more finished units are grouped into this many steps) */
    private const MAX_CHECKPOINTS_PER_ENTRY = 48;

    /**
     * Times (ascending) at which units that change production or energy (solar satellites) finish within the update
     * from the planet's last_update up to (excluding) $st->time, so the resource update can be advanced to them: each
     * time is the moment the unit completes. An entry that finishes more than MAX_CHECKPOINTS_PER_ENTRY units in the
     * interval gets evenly spaced checkpoints instead of one per unit: production is then booked with the count of the
     * step's start, so it is too low by at most half the growth of one step. Empty (and no build time lookup) when no
     * producing unit is queued.
     *
     * @return list<int>
     */
    public function productionCheckpoints(QueueState $st): array
    {
        if (empty($st->planet['b_hangar_id'])) {
            return [];
        }

        $elements = $this->elements();
        $queue = StoredData::decode($st->planet['b_hangar_id']);
        $producers = array_filter($queue, static fn(array $item): bool => in_array((int) $item[0], $elements->ids('prod'), true));
        if ($producers === []) {
            return [];
        }

        $userModel = $this->models()->user($st->user);
        $planetModel = $this->models()->planet($st->planet);
        $available = $st->planet['b_hangar'] + $this->creditedSeconds($st, $st->planet['last_update']);
        $spent = 0;
        $times = [];
        foreach ($queue as $item) {
            $unitTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, (int) $item[0]);
            if ($unitTime <= 0) {
                continue;
            }
            $finished = (int) min($item[1], floor(($available - $spent) / $unitTime));
            if ($finished <= 0) {
                break;
            }
            if (in_array((int) $item[0], $elements->ids('prod'), true)) {
                $steps = min($finished, self::MAX_CHECKPOINTS_PER_ENTRY);
                for ($step = 1; $step <= $steps; $step++) {
                    $unit = (int) ceil($step * $finished / $steps);
                    $time = (int) ($st->planet['last_update'] + $spent + $unit * $unitTime - $st->planet['b_hangar']);
                    if ($time > $st->planet['last_update'] && $time < $st->time) {
                        $times[$time] = $time;
                    }
                }
            }
            if ($finished < $item[1]) {
                break;
            }
            $spent += $item[1] * $unitTime;
        }
        ksort($times);

        return array_values($times);
    }

    /**
     * Finishes the part of the shipyard queue that fits into the elapsed build time (b_hangar): completed ships are
     * added to the planet and to the built deltas, the rest stays queued. Elements with a build time of 0 complete at once.
     *
     * @param int|null $since time up to which the elapsed build time was already counted (default: the planet's last_update)
     * @return bool false when the queue was empty
     */
    public function process(QueueState $st, ?int $since = null)
    {
        $elements = $this->elements();

        $BuildQueue = !empty($st->planet['b_hangar_id']) ? StoredData::decode($st->planet['b_hangar_id']) : [];
        if (!$BuildQueue) {
            $st->planet['b_hangar'] = 0;
            $st->planet['b_hangar_id'] = '';
            return false;
        }

        $st->planet['b_hangar']   += $this->creditedSeconds($st, $since ?? $st->planet['last_update']);
        $BuildArray = [];
        $userModel = $this->models()->user($st->user);
        $planetModel = $this->models()->planet($st->planet);
        foreach ($BuildQueue as $Item) {
            $AcumTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Item[0]);
            $BuildArray[] = [$Item[0], $Item[1], $AcumTime];
        }

        $NewQueue = [];
        $Done = false;
        foreach ($BuildArray as $Item) {
            $Element = $Item[0];
            $Count = $Item[1];

            if ($Done == false) {
                $BuildTime = $Item[2];
                $Element = (int) $Element;
                if ($BuildTime == 0) {
                    if (!isset($st->builded[$Element])) {
                        $st->builded[$Element] = 0;
                    }

                    $st->builded[$Element]            += $Count;
                    $st->planet[$elements->column($Element)]  += $Count;
                    continue;
                }

                $Build = max(min(floor($st->planet['b_hangar'] / $BuildTime), $Count), 0);

                if ($Build == 0) {
                    $NewQueue[] = [$Element, $Count];
                    $Done = true;
                    continue;
                }

                if (!isset($st->builded[$Element])) {
                    $st->builded[$Element] = 0;
                }

                $st->builded[$Element]            += $Build;
                $st->planet['b_hangar']           -= $Build * $BuildTime;
                $st->planet[$elements->column($Element)]  += $Build;
                $Count                              -= $Build;

                if ($Count == 0) {
                    continue;
                } else {
                    $Done = true;
                }
            }
            $NewQueue[] = [$Element, $Count];
        }

        if (empty($NewQueue)) {
            $st->planet['b_hangar_id'] = '';
            $st->planet['b_hangar'] = 0;
        } else {
            $st->planet['b_hangar_id'] = StoredData::encode($NewQueue);
        }

        return true;
    }

    /**
     * Build seconds earned between $from and the update time. Unit times already include today's BUILD_SPEED event factor,
     * so the elapsed seconds are scaled by (average factor over the window / today's factor): idle time before or after an
     * event is not boosted. Unchanged (plain elapsed seconds) when no event ran.
     */
    private function creditedSeconds(QueueState $st, int|float $from): int|float
    {
        $elapsed = $st->time - $from;
        if ($elapsed <= 0) {
            return $elapsed;
        }
        $universe = (int) $st->user['universe'];
        $events = $this->eventModifiers();
        $now = $events->factor($universe, EventModifiers::BUILD_SPEED);
        $average = $events->factorOver($universe, EventModifiers::BUILD_SPEED, (int) $from, (int) $st->time);

        return abs($average - $now) < 0.0001 ? $elapsed : $elapsed * $average / $now;
    }
}
