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

use Cron\CronExpression;
use DateTime;
use Exception;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Discord;

/**
 * Scheduler core for the `cronjobs` table (name, class, cron expression min/hours/dom/month/dow, isActive, nextTime,
 * lock). bin/console app:cron:run asks getNeedTodoExecutedJobs() for the due jobs every minute and runs each
 * through execute(); every task class is a CronjobTaskInterface service in this namespace.
 */
class Cronjob
{
    /** Cronjob tasks as Symfony services (tag app.game_cronjob), set by App\Runtime\GameKernel. */
    /**
     * @param \Psr\Container\ContainerInterface|null $tasks cronjob task services (container); without it the tasks are
     *                                       built by hand from the same services
     */
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        #[\Symfony\Component\DependencyInjection\Attribute\AutowireLocator('app.game_cronjob')]
        private ?\Psr\Container\ContainerInterface $tasks = null,
    ) {}

    /**
     * Runs one job: only if it is active and not locked. Sets the lock (token = md5 of the request time), runs the task
     * (container service tagged app.game_cronjob, else built by hand), computes its next run time, releases the lock and
     * writes a row to cronjobs_log, then reports success to Discord. Task exceptions release the lock and are
     * reported to Discord (not rethrown, nextTime stays unchanged so the job is retried on the next run).
     * `$force` (manual run from the admin) skips the due-time check. A job that another runner locked or already ran is skipped silently.
     * @throws Exception for an unknown, inactive or locked job id.
     */
    public function execute(int $cronjobID, bool $force = false): void
    {

        // "<start time>:<random>": the start time lets releaseStaleLocks() free the lock of a run that died (kill, OOM, restart)
        $lockToken = $this->state->runtime->timestamp . ':' . bin2hex(random_bytes(8));

        $db = $this->db;

        $sql = 'SELECT class FROM %%CRONJOBS%% WHERE isActive = :isActive AND cronjobID = :cronjobId AND `lock` IS NULL;';

        $cronjobClassName = $db->selectSingle($sql, [
            ':isActive' => 1,
            ':cronjobId' => $cronjobID,
        ], 'class');

        if (empty($cronjobClassName)) {
            throw new Exception(sprintf("Unknown cronjob %s or cronjob is deactive!", $cronjobID));
        }

        // take the lock atomically: only one runner wins, and only while the job is still due (a runner with a stale
        // list of due jobs finds nextTime already moved on by the runner before it)
        $db->update('UPDATE %%CRONJOBS%% SET `lock` = :lock WHERE cronjobID = :cronjobId AND isActive = 1 AND `lock` IS NULL AND (:force = 1 OR nextTime IS NULL OR nextTime < :now);', [
            ':lock' => $lockToken,
            ':cronjobId' => $cronjobID,
            ':now' => $this->state->runtime->timestamp,
            ':force' => $force ? 1 : 0,
        ]);
        if ($db->rowCount() !== 1) {
            return;
        }

        $cronjobClassName = 'App\\Game\\Cronjob\\' . $cronjobClassName;
        $done = false;
        try {
            /** @var CronjobTaskInterface $cronjobObj */
            $cronjobObj = $this->tasks !== null && $this->tasks->has($cronjobClassName)
                ? $this->tasks->get($cronjobClassName)   // Symfony service (see config/services.yaml)
                : new $cronjobClassName($this->db, $this->configs, $this->state);
            $cronjobObj->run();

            $this->reCalculateCronjobs($cronjobID);
            $done = true;

            $db->insert('INSERT INTO %%CRONJOBS_LOG%% SET `cronjobId` = :cronjobId, `executionTime` = :executionTime, `lockToken` = :lockToken', [
                ':cronjobId' => $cronjobID,
                ':executionTime' => $this->db->formatDate($this->state->runtime->timestamp),
                ':lockToken' => $lockToken,
            ]);

            (new Discord($this->configs, $this->state))->sendLog($cronjobClassName, ['successful' => true]);
        } catch (\Throwable $exception) {
            // a failed run is reported (not rethrown); nextTime stays unchanged so the job is retried on the next run
            if (!$done) {
                (new Discord($this->configs, $this->state))->sendLog($cronjobClassName, null, $exception);
            }
        } finally {
            // unlock in every case, also after an \Error
            $db->update('UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE cronjobID = :cronjobId AND `lock` = :lock;', [
                ':cronjobId' => $cronjobID,
                ':lock' => $lockToken,
            ]);
        }
    }

    /** a lock older than this belongs to a run that died without its finally block (SIGKILL, OOM, container restart) */
    public const STALE_LOCK_SECONDS = 7200;

    /**
     * Frees locks of runs that started more than STALE_LOCK_SECONDS ago (only tokens with a start time; old md5 tokens
     * stay for the admin "unlock" button), so a crashed run cannot block its job forever.
     */
    private function releaseStaleLocks(): void
    {
        $this->db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE `lock` LIKE '%:%' AND CAST(SUBSTRING_INDEX(`lock`, ':', 1) AS UNSIGNED) < :stale;",
            [':stale' => $this->state->runtime->timestamp - self::STALE_LOCK_SECONDS],
        );
    }

    /**
     * Ids of the active, unlocked jobs whose nextTime lies in the past.
     * @return int[]
     */
    public function getNeedTodoExecutedJobs(): array
    {
        $this->releaseStaleLocks();
        $sql = 'SELECT cronjobID
		FROM %%CRONJOBS%%
		WHERE isActive = :isActive AND nextTime < :time AND `lock` IS NULL;';

        $cronjobResult = $this->db->select($sql, [
            ':isActive' => 1,
            ':time' => $this->state->runtime->timestamp,
        ]);

        $cronjobList = [];

        foreach ($cronjobResult as $cronjobRow) {
            $cronjobList[] = $cronjobRow['cronjobID'];
        }

        return $cronjobList;
    }

    /**
     * Unix timestamp of the last logged run of the job with this `name` (cronjobs_log), false if it never ran.
     */
    public function getLastExecutionTime(string $cronjobName): int|false
    {
        // one lookup per job id: an equality on the leading index column lets MySQL read MAX(executionTime) from the index end
        // without scanning the (ever growing) log of the job
        $lastTime = null;
        $ids = $this->db->select('SELECT cronjobID FROM %%CRONJOBS%% WHERE name = :cronjobName;', [':cronjobName' => $cronjobName]);
        foreach ($ids as $row) {
            $time = $this->db->selectSingle('SELECT MAX(executionTime) as executionTime FROM %%CRONJOBS_LOG%% WHERE cronjobId = :cronjobId;', [
                ':cronjobId' => $row['cronjobID'],
            ], 'executionTime');
            if ($time !== null && $time !== false && ($lastTime === null || $time > $lastTime)) {
                $lastTime = $time;
            }
        }

        if (empty($lastTime)) {
            return false;
        }

        return strtotime($lastTime);
    }

    /**
     * Sets nextTime of one job (or all when null) to the next run of its cron expression strictly after now (CronExpression already skips the current minute, so `* * * * *` runs every minute; the former +60 s made it skip one).
     * With $keepDue, jobs that are already due (nextTime in the past) are left alone, so a bulk recalculation (daily job,
     * cache clear) cannot swallow runs that were missed during downtime.
     */
    public function reCalculateCronjobs(?int $cronjobID = null, bool $keepDue = false): void
    {
        $db = $this->db;

        if (!empty($cronjobID)) {
            $sql = 'SELECT cronjobID, nextTime, min, hours, dom, month, dow FROM %%CRONJOBS%% WHERE cronjobID = :cronjobId;';
            $cronjobResult = $db->select($sql, [
                ':cronjobId' => $cronjobID,
            ]);
        } else {
            $sql = 'SELECT cronjobID, nextTime, min, hours, dom, month, dow FROM %%CRONJOBS%%;';
            $cronjobResult = $db->select($sql);
        }

        $sql = 'UPDATE %%CRONJOBS%% SET nextTime = :nextTime WHERE cronjobID = :cronjobId;';

        foreach ($cronjobResult as $cronjobRow) {
            if ($keepDue && $cronjobRow['nextTime'] !== null && $cronjobRow['nextTime'] < $this->state->runtime->timestamp) {
                continue;
            }
            $cronTabString = implode(' ', [$cronjobRow['min'], $cronjobRow['hours'], $cronjobRow['dom'], $cronjobRow['month'], $cronjobRow['dow']]);

            $cron = new CronExpression($cronTabString);
            $nextTime = $cron->getNextRunDate(new DateTime('@' . $this->state->runtime->timestamp))->getTimestamp();

            $db->update($sql, [
                ':nextTime' => $nextTime,
                ':cronjobId' => $cronjobRow['cronjobID'],
            ]);
        }
    }
}
