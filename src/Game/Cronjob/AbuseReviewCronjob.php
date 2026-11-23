<?php

namespace App\Game\Cronjob;

use App\Game\Admin\AbuseReport\AbuseReportQuery;
use App\Game\Admin\AbuseReport\AbuseReviewService;

/**
 * Hourly refresh of the anti-multi / push-trade review queue: scores the candidate pairs of every universe and
 * inserts/updates/re-opens reviews at or above the universe setting abuse_review_threshold (default 50).
 */
class AbuseReviewCronjob extends AbstractCronjobTask
{
    /** Scores the pairs of each universe (see AbuseReviewService::refresh()). */
    public function run(): void
    {
        $now = $this->state->runtime->timestamp;
        foreach ($this->universe()->availableUniverses() as $universe) {
            $threshold = (int) ($this->configs->get((int) $universe)->abuse_review_threshold ?? AbuseReviewService::DEFAULT_THRESHOLD);
            $service = new AbuseReviewService($this->db, new AbuseReportQuery($this->db, $this->elements()));
            $service->refresh((int) $universe, max(1, $threshold), $now);
        }
    }
}
