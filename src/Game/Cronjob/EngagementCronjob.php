<?php

namespace App\Game\Cronjob;

use App\Game\Engagement\EngagementHooks;

/**
 * Engagement features tick (cronjob "engagement", every 5 minutes): universe events, contracts, seasons, bounties,
 * alliance wars and goals, derelicts and the pirate mothership. Each feature returns at once when it is switched off.
 */
class EngagementCronjob extends AbstractCronjobTask
{
    /**
     * Ticks every engagement feature of every universe, then lets expired derelicts and a mothership that ran out leave.
     */
    public function run(): void
    {
        $hooks = new EngagementHooks($this->db, $this->configs, $this->state);
        $now = $this->state->runtime->timestamp;
        foreach ($this->universe()->availableUniverses() as $universe) {
            $universe = (int) $universe;
            $this->guarded(fn() => $hooks->tick($universe, $now));
            // expired bounties are returned to the placers even after the admin switched the feature off
            $this->guarded(fn() => (new \App\Game\Engagement\Bounties\Bounties($this->db, $this->configs, $this->state))->refundExpired($universe, $now));
            // expired derelicts / a mothership that ran out leave even when the feature was switched off meanwhile
            $this->guarded(fn() => (new \App\Game\Engagement\Derelicts\Derelicts($this->db, $this->configs, $this->state))->cleanup($universe, $now));
            $this->guarded(fn() => (new \App\Game\Engagement\Mothership\Mothership($this->db, $this->configs, $this->state))->expire($universe, $now));
            // the catch-up average (daily cache) is refreshed here, never in a player request, also while events are off
            $this->guarded(function () use ($universe, $now): void {
                $catchUp = $this->catchUpBoost();
                if ($catchUp->enabled($universe)) {
                    $catchUp->averagePoints($universe, $now);
                }
            });
            // old relic log lines and the finds snapshots of fleets that are gone
            $this->guarded(fn() => (new \App\Game\Engagement\Relics\RelicInventory($this->db, $this->configs, $this->state))->cleanup($universe, $now));
        }
    }

    /** Runs one step: a failure is logged and the other steps still run; deadlocks and lock timeouts are passed on for the retry. */
    private function guarded(callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            if (\App\Game\Core\Database::isRetryable($e)) {
                throw $e;
            }
            error_log('engagement cronjob step failed: ' . $e->getMessage());
        }
    }
}
