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

namespace App\Game\Core;

use App\Game\Enum\Mission;
use App\Game\Missions\MissionCaseACS;
use App\Game\Missions\MissionCaseAttack;
use App\Game\Missions\MissionCaseColonisation;
use App\Game\Missions\MissionCaseDestruction;
use App\Game\Missions\MissionCaseExpedition;
use App\Game\Missions\MissionCaseMIP;
use App\Game\Missions\MissionCasePiratebaseAttack;
use App\Game\Missions\MissionCasePiratebaseRecycling;
use App\Game\Missions\MissionCasePiratebaseSpy;
use App\Game\Missions\MissionCaseRecycling;
use App\Game\Missions\MissionCaseSpy;
use App\Game\Missions\MissionCaseStay;
use App\Game\Missions\MissionCaseStayAlly;
use App\Game\Missions\MissionCaseTrade;
use App\Game\Missions\MissionCaseTransfer;
use App\Game\Missions\MissionCaseTransport;
use App\Game\Missions\MissionInterface;

/**
 * Processes the due fleet events: for every fleet whose `fleets_event` row carries this run's lock token (set by
 * the caller/cronjob) it instantiates the mission class of the fleet's mission id (missionClasses()) and calls
 * TargetEvent / ReturnEvent / EndStayEvent depending on the fleet status, oldest event first. Fleets of an unknown
 * mission are deleted. Every event runs in its own transaction (see run()); the caller only claims the events.
 */
class FlyingFleetHandler
{
    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
        private ErrorReporter $errors,
    ) {}

    /** Seconds a failed event is parked before it is claimed again. */
    public const QUARANTINE_SECONDS = 300;

    protected string $token;

    /**
     * Mission id => mission class. A method (not a static property) because the MISSION_* constants
     * only exist after the game bootstrap, while the container loads this class earlier.
     *
     * @return array<int, class-string>
     */
    public static function missionClasses(): array
    {
        return [
            Mission::Attack->value                  => MissionCaseAttack::class,
            Mission::Acs->value                     => MissionCaseACS::class,
            Mission::Transport->value               => MissionCaseTransport::class,
            Mission::Station->value                 => MissionCaseStay::class,
            Mission::Hold->value                    => MissionCaseStayAlly::class,
            Mission::Spy->value                     => MissionCaseSpy::class,
            Mission::Colonisation->value            => MissionCaseColonisation::class,
            Mission::Recycling->value               => MissionCaseRecycling::class,
            Mission::Destruction->value             => MissionCaseDestruction::class,
            Mission::Missile->value                 => MissionCaseMIP::class,
            Mission::Expedition->value              => MissionCaseExpedition::class,
            Mission::Trade->value                   => MissionCaseTrade::class,
            Mission::Transfer->value                => MissionCaseTransfer::class,
            Mission::PiratebaseAttack->value       => MissionCasePiratebaseAttack::class,
            Mission::PiratebaseSpy->value          => MissionCasePiratebaseSpy::class,
            Mission::PiratebaseRecycling->value    => MissionCasePiratebaseRecycling::class,
        ];
    }

    public function setToken(string $token): void
    {
        $this->token    = $token;
    }

    /** Whether an unclaimed event of the universe is due (one plain read, no writes). */
    public function hasDue(int $universe): bool
    {
        return $this->db->selectSingle('SELECT 1 AS due FROM %%FLEETS_EVENT%% WHERE `lock` IS NULL AND `time` <= :time AND `universe` = :universe LIMIT 1;', [
            ':time' => $this->state->runtime->timestamp,
            ':universe' => $universe,
        ], 'due') !== false;
    }

    /**
     * Claims the due events of a universe (sets a fresh random lock token on all unlocked `fleets_event` rows with
     * time <= now) in a short transaction of their own, runs them (run()) and releases the events that were not
     * reached. Atomic claims make parallel callers (page requests, the cron task, `app:fleets:run`) safe: no event is
     * processed twice. A deadlock that outlasts the retries of Database::transactional() is thrown.
     *
     * @return int number of claimed events
     */
    public function processDue(int $universe): int
    {
        $token = getRandomString();
        $db = $this->db;

        // Nothing due is the usual case on a page request: one plain read instead of transaction + UPDATE + commit.
        // An event that becomes due right after this read is claimed by the next caller, as before.
        $due = $db->selectSingle('SELECT 1 AS due FROM %%FLEETS_EVENT%% WHERE `lock` IS NULL AND `time` <= :time AND `universe` = :universe LIMIT 1;', [
            ':time' => $this->state->runtime->timestamp,
            ':universe' => $universe,
        ], 'due');
        if ($due === false) {
            return 0;
        }

        $claimed = $db->transactional(function () use ($db, $token, $universe): int {
            $db->update('UPDATE %%FLEETS_EVENT%%
                SET `lock` = :token
                WHERE `lock` IS NULL AND `time` <= :time AND `universe` = :universe;', [
                ':token' => $token,
                ':time' => $this->state->runtime->timestamp,
                ':universe' => $universe,
            ]);

            return $db->rowCount();
        });

        if ($claimed > 0) {
            try {
                $this->setToken($token);
                $this->run();
            } finally {
                // events the run did not reach (or could not release) go back to the next caller
                $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `lock` = :token;', [':token' => $token]);
            }
        }

        return $claimed;
    }

    /**
     * Handles all events locked with the token (see setToken()), ordered by event time then fleet id. Every event runs in
     * its own transaction (Database::transactional, deadlocks are repeated) and releases its lock when it is done, so a
     * failing event is rolled back, reported (game log + Discord) and quarantined (lock released, event time pushed back
     * by QUARANTINE_SECONDS) while the others go on. The fleet rows are read once up front, as before: an event
     * sees its fleet as it was at the claim, even when an earlier event of the batch (e.g. an ACS battle) changed it.
     */
    public function run(): void
    {
        $fleetResult = $this->db->select(
            'SELECT f.*
            FROM %%FLEETS_EVENT%% fe
            INNER JOIN %%FLEETS%% f ON fe.`fleetID` = f.`id`
            WHERE fe.`lock` = :token ORDER BY fe.`time` ASC, fe.`fleetID` ASC;',
            [':token' => $this->token],
        );

        foreach ($fleetResult as $fleetRow) {
            try {
                $this->db->transactional(fn() => $this->runEvent($fleetRow));
            } catch (\Throwable $exception) {
                $this->quarantine($fleetRow, $exception);
            }
        }
    }

    /**
     * Runs one event in the open transaction: locks the event row (skips it when an earlier event of the batch removed the
     * fleet), calls the mission and releases the event lock.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function runEvent(array $fleetRow): void
    {
        $db = $this->db;
        $locked = $db->selectSingle(
            'SELECT `fleetID` FROM %%FLEETS_EVENT%% WHERE `fleetID` = :fleetId AND `lock` = :token FOR UPDATE;',
            [':fleetId' => $fleetRow['id'], ':token' => $this->token],
        );
        if (empty($locked)) {
            return;
        }

        $missionName = $this->missionClass((int) $fleetRow['mission']);
        if ($missionName === null) {
            $db->delete('DELETE FROM %%FLEETS%% WHERE `id` = :fleetId;', [':fleetId' => $fleetRow['id']]);
        } else {
            /** @var MissionInterface $missionObj */
            $missionObj = new $missionName($fleetRow, $this->db, $this->configs, $this->state);

            switch ($fleetRow['status']) {
                case FLEET_OUTWARD:
                    $missionObj->TargetEvent();
                    break;
                case FLEET_RETURN:
                    $missionObj->ReturnEvent();
                    break;
                case FLEET_HOLD:
                    $missionObj->EndStayEvent();
                    break;
            }
        }

        $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `fleetID` = :fleetId AND `lock` = :token;', [
            ':fleetId' => $fleetRow['id'],
            ':token' => $this->token,
        ]);
    }

    /** Mission class of a mission id, null for an unknown mission (its fleet is deleted). */
    protected function missionClass(int $mission): ?string
    {
        return self::missionClasses()[$mission] ?? null;
    }

    /**
     * Parks a failed event: reports the exception, releases the lock and pushes the event time back so the same event
     * cannot stall every later batch.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function quarantine(array $fleetRow, \Throwable $exception): void
    {
        $this->errors->reportIsolated($exception, 'fleet ' . $fleetRow['id'] . ', mission ' . $fleetRow['mission'] . ', status ' . $fleetRow['status']);

        try {
            $this->db->update(
                'UPDATE %%FLEETS_EVENT%% SET `lock` = NULL, `time` = :time WHERE `fleetID` = :fleetId AND `lock` = :token;',
                [
                    ':time' => $this->state->runtime->timestamp + self::QUARANTINE_SECONDS,
                    ':fleetId' => $fleetRow['id'],
                    ':token' => $this->token,
                ],
            );
        } catch (\Throwable $releaseFailure) {
            $this->errors->reportIsolated($releaseFailure, 'quarantine of fleet ' . $fleetRow['id']);
        }
    }
}
