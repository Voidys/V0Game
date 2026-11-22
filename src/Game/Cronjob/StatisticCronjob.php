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

namespace App\Game\Cronjob;

use App\Game\Core\Statbuilder;

/**
 * Ranking update (cronjob "statistic", every 30 minutes): recalculates points and ranks via Statbuilder.
 */
class StatisticCronjob extends AbstractCronjobTask
{
    /**
     * Calls Statbuilder::generateStats(), archiveStats() and buildRecords(); failures propagate to Cronjob::execute().
     */
    public function run(): void
    {
        // a failure is not caught here: Cronjob::execute() logs it as failed (no success entry) and reports it once
        $stat = new Statbuilder($this->db, $this->configs, $this->state);
        $stat->generateStats();
        $stat->archiveStats();
        $stat->buildRecords();
    }
}
