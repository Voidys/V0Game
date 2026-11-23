<?php

namespace App\Game\Core;

/** Pure arithmetic of shipyard orders: "build up to X" targets and the caps of a single order. */
final class HangarOrderPlanner
{
    /**
     * Units to order so that owned + queued + ordered reaches $target, never below 0 and never above $cap (when given).
     * The cap is the smaller of the affordable amount and the per-order limit; missing units beyond it are left out.
     */
    public static function upTo(float $target, float $owned, float $queued, ?float $cap = null): float
    {
        $missing = max(floor($target) - max($owned, 0) - max($queued, 0), 0);

        return $cap === null ? $missing : min($missing, max($cap, 0));
    }
}
