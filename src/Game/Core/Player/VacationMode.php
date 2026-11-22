<?php

namespace App\Game\Core\Player;

use App\Game\Enum\Mission;
use App\Game\Core\StoredData;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\GameUtilities;

/**
 * Entering and leaving vacation mode: freezes production and sends hold fleets home on entry, postpones
 * queues, wreck fields and recalculates resources on exit. Collaborators: ResourceUpdate, FleetFunctions,
 * PlayerUtilAchievement.
 */
class VacationMode
{
    use GameUtilities;

    /** Production percentage columns of a planet (value 0-10 = 0-100 %), in the order of `planets.porcent_saved`. */
    public const PERCENT_COLUMNS = [
        'metal_mine_porcent',
        'crystal_mine_porcent',
        'deuterium_sintetizer_porcent',
        'solar_plant_porcent',
        'fusion_plant_porcent',
        'solar_satelit_porcent',
    ];

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Ends vacation mode once the minimum duration has passed.
     *
     * Callers hold a transaction; the user row is re-read under lock so a concurrent request cannot end the vacation
     * twice. All queues (research, buildings on every planet) and wreck field timers are postponed by the time spent
     * in vacation, mine/plant percentages are reset to 10 and the resources are recalculated for every planet.
     * Does nothing if the user is not in vacation or urlaubs_until is still in the future.
     *
     * @param array<string, mixed> $USER user row, updated in place
     * @param array<string, mixed>|null $PLANET current planet row, updated in place
     */
    public function disable_vmode(array &$USER, ?array &$PLANET = null): void
    {
        $db = $this->db;

        // Callers hold a transaction. Re-read the user under lock: a concurrent
        // request may already have ended vacation mode, and urlaubs_start = 0
        // would then shift every queue by TIMESTAMP.
        $sql = 'SELECT * FROM %%USERS%% WHERE `id` = :userID FOR UPDATE;';
        $u = $db->selectSingle($sql, [
            ':userID'   => $USER['id'],
        ]);
        if (empty($u) || $u['urlaubs_modus'] != 1) {
            return;
        }
        $USER = array_merge($USER, $u);
        $umode_start = $u['urlaubs_start'];
        $umode_delta = $this->state->runtime->timestamp - $umode_start;
        if ($USER['urlaubs_until'] <= $this->state->runtime->timestamp) {
            $this->shiftResearchQueue($USER, $umode_delta);
            if (isset($PLANET)) {
                $this->shiftBuildQueue($PLANET, $umode_delta);
            }
            $restored = $this->resumeOtherPlanets((int) $USER['id'], $umode_delta);

            $sql = 'UPDATE %%USERS%% SET
                `urlaubs_modus` = 0,
                `urlaubs_until` = 0,
                `urlaubs_start` = 0
                WHERE `id` = :userID;';
            $db->update($sql, [':userID'   => $USER['id']]);

            $USER['urlaubs_modus'] = 0;
            $USER['urlaubs_until'] = 0;
            $USER['urlaubs_start'] = 0;

            $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :id;";
            $PlanetsRAW = $db->select($sql, [
                ':id' => $USER['id'],
            ]);

            $PlanetRess = new ResourceUpdate($this->db, $this->configs, $this->state);
            if (isset($PLANET)) {
                // the rows were rewritten by resumeOtherPlanets(): the request's copy gets the restored levels too, and
                // one second of production so that CalcResource() rebuilds production and energy for this request
                foreach ($restored[$PLANET['id']] ?? [] as $column => $value) {
                    $PLANET[$column] = $value;
                }
                $PLANET['last_update'] = $this->state->runtime->timestamp - 1;
                $PLANET['eco_hash'] = '';
                [$USER, $PLANET] = $PlanetRess->CalcResource($USER, $PLANET, true);
                $this->playerUtilAchievements()->checkPlayerUtilAchievements33($USER['id']);
            }

            foreach ($PlanetsRAW as $CPLANET) {
                $CPLANET['eco_hash'] = '';
                [$USER, $CPLANET] = $PlanetRess->CalcResource($USER, $CPLANET, true);
            }
        }
    }

    /**
     * SQL expression that stores the planet's production percentages in a comma separated list (for `porcent_saved`).
     */
    public static function percentSnapshotSql(): string
    {
        return "CONCAT_WS(',', `" . implode('`, `', self::PERCENT_COLUMNS) . "`)";
    }

    /**
     * Decodes `planets.porcent_saved` into column => value; missing or malformed snapshots give every column 10 (100 %).
     *
     * @return array<string, string>
     */
    public static function percentsFromSnapshot(?string $snapshot): array
    {
        $values = explode(',', (string) $snapshot);
        $valid = count($values) === count(self::PERCENT_COLUMNS);
        foreach ($values as $value) {
            $valid = $valid && ctype_digit($value) && (int) $value <= 10;
        }

        $percents = [];
        foreach (self::PERCENT_COLUMNS as $i => $column) {
            $percents[$column] = $valid ? (string) (int) $values[$i] : '10';
        }

        return $percents;
    }

    /**
     * Postpones the research queue by the length of the vacation (end times + b_tech).
     * Side effects: UPDATE users (b_tech, b_tech_queue); $USER is updated in place.
     *
     * @param array<string, mixed> $USER user row (b_tech, b_tech_queue, id)
     * @param int $umode_delta seconds spent in vacation mode
     */
    private function shiftResearchQueue(array &$USER, int $umode_delta): void
    {
        $db = $this->db;
        if (!empty($USER['b_tech']) && !empty($USER['b_tech_queue'])) {
            $CurrentQueue       = StoredData::decode($USER['b_tech_queue']);
            foreach ($CurrentQueue as &$TechArray) {
                $TechArray[3] += $umode_delta;
            }
            $USER['b_tech_queue'] = StoredData::encode($CurrentQueue);
            unset($CurrentQueue);
            $sql = 'UPDATE %%USERS%% SET
                `b_tech` = `b_tech` + :umode_delta,
                `b_tech_queue` = :b_tech_queue
                WHERE `id` = :userID;';
            $db->update($sql, [
                ':umode_delta' => $umode_delta,
                ':userID'   => $USER['id'],
                ':b_tech_queue' => $USER['b_tech_queue'],
            ]);
            $USER['b_tech'] = $USER['b_tech'] + $umode_delta;
        }
    }

    /**
     * Postpones the building queue of the planet the player is on by the vacation length (in memory only; the
     * row is written later by resumeOtherPlanets()/the caller).
     *
     * @param array<string, mixed> $PLANET planet row (b_building, b_building_id), updated in place
     * @param int $umode_delta seconds spent in vacation mode
     */
    private function shiftBuildQueue(array &$PLANET, int $umode_delta): void
    {
        if (!empty($PLANET['b_building']) && !empty($PLANET['b_building_id'])) {
            $PLANET['b_building'] = $PLANET['b_building'] + $umode_delta;
            $CurrentQueue       = StoredData::decode($PLANET['b_building_id']);
            foreach ($CurrentQueue as &$BuildArray) {
                $BuildArray[3] += $umode_delta;
            }
            $PLANET['b_building_id'] = StoredData::encode($CurrentQueue);
            unset($CurrentQueue);
        }
    }

    /**
     * Postpones building queues and wreck fields of all non-destroyed planets by the vacation length and
     * restores the production percentages of mines, power plants and satellites the player had before the vacation
     * (`porcent_saved`; 10 = 100 % when there is no snapshot). The eco hash is cleared: the vacation entry zeroed
     * production and energy, so the next update must rebuild them (the hash alone would match).
     * Side effects: locks the planets (FOR UPDATE), UPDATE planets and planet_wreckfield.
     *
     * @param int $userId
     * @param int $umode_delta seconds spent in vacation mode
     * @return array<int, array<string, string>> planet id => restored percentage columns
     */
    private function resumeOtherPlanets(int $userId, int $umode_delta): array
    {
        $db = $this->db;
        $sql = 'SELECT `id`, `b_building`, `b_building_id`, `porcent_saved` FROM %%PLANETS%% WHERE `id_owner` = :userID AND `destruyed` = 0 FOR UPDATE;';
        $planets = $db->select($sql, [
            ':userID'   => $userId,
        ]);
        $restored = [];

        foreach ($planets as $CPLANET) {
            if (!empty($CPLANET['b_building']) && !empty($CPLANET['b_building_id'])) {
                $CPLANET['b_building'] = $CPLANET['b_building'] + $umode_delta;
                $CurrentQueue = StoredData::decode($CPLANET['b_building_id']);
                foreach ($CurrentQueue as &$BuildArray) {
                    $BuildArray[3] += $umode_delta;
                }
                $CPLANET['b_building_id'] = StoredData::encode($CurrentQueue);
                unset($CurrentQueue);
            }

            $sql = 'UPDATE %%PLANETS%% SET
                `b_building` = :building,
                `b_building_id` = :current_queue,
                `last_update` = :timestamp,
                `eco_hash` = "",
                `porcent_saved` = "",
                `metal_mine_porcent` = :metal,
                `crystal_mine_porcent` = :crystal,
                `deuterium_sintetizer_porcent` = :deuterium,
                `solar_plant_porcent` = :solar,
                `fusion_plant_porcent` = :fusion,
                `solar_satelit_porcent` = :satellite
                WHERE `id` = :planetID;';
            $percents = self::percentsFromSnapshot($CPLANET['porcent_saved']);
            $restored[$CPLANET['id']] = $percents;
            $db->update($sql, [
                ':metal' => $percents['metal_mine_porcent'],
                ':crystal' => $percents['crystal_mine_porcent'],
                ':deuterium' => $percents['deuterium_sintetizer_porcent'],
                ':solar' => $percents['solar_plant_porcent'],
                ':fusion' => $percents['fusion_plant_porcent'],
                ':satellite' => $percents['solar_satelit_porcent'],
                ':planetID' => $CPLANET['id'],
                ':building' => $CPLANET['b_building'],
                ':current_queue' => $CPLANET['b_building_id'],
                ':timestamp' => $this->state->runtime->timestamp,
            ]);

            $sql = 'UPDATE %%PLANET_WRECKFIELD%% SET
                `created` = `created` + :umode_delta,
                `repair_order_start` =`repair_order_start` + :umode_delta,
                `repair_order_end` = `repair_order_end` + :umode_delta
                WHERE `planetID` = :planetID;';
            $db->update($sql, [
                ':umode_delta' => $umode_delta,
                ':planetID' => $CPLANET['id'],
            ]);

            unset($CPLANET);
        }

        return $restored;
    }

    /**
     * Starts vacation mode: sets urlaubs_modus/start (and urlaubs_until = now + vmode_min_time, or 0 when
     * caused by a ban), zeroes production and energy on all planets and sends back fleets that hold at the user's planets.
     *
     * @param array<string, mixed> $USER user row
     * @param array<string, mixed>|null $PLANET current planet row, zeroed in place
     * @param bool $byBan vacation imposed by a ban (no minimum duration)
     */
    public function enable_vmode(array &$USER, ?array &$PLANET, bool $byBan = false): void
    {
        $db = $this->db;

        $sql = 'UPDATE %%USERS%% SET `urlaubs_modus` = 1, `urlaubs_until` = :time, `urlaubs_start` = :startTime
            WHERE `id` = :userID';
        $db->update($sql, [
            ':userID'       => $USER['id'],
            ':time'         => $byBan ? 0 : ($this->state->runtime->timestamp + $this->configs->get()->vmode_min_time),
            ':startTime'    => $this->state->runtime->timestamp,
        ]);

        // remember the player's levels for the way back (a second call while already in vacation keeps the first snapshot)
        $db->update('UPDATE %%PLANETS%% SET `porcent_saved` = ' . self::percentSnapshotSql() . " WHERE `id_owner` = :userID AND `porcent_saved` = '';", [
            ':userID'   => $USER['id'],
        ]);

        $sql = 'UPDATE %%PLANETS%% SET `energy_used` = 0, `energy` = 0, `metal_mine_porcent` = "0",
            `crystal_mine_porcent` = "0", `deuterium_sintetizer_porcent` = "0", `solar_plant_porcent` = "0",
            `fusion_plant_porcent` = "0", `solar_satelit_porcent` = "0", `metal_perhour` = 0,
            `crystal_perhour` = 0, `deuterium_perhour` = 0 WHERE `id_owner` = :userID;';
        $db->update($sql, [
            ':userID'   => $USER['id'],
        ]);

        $sql = 'SELECT `id`, `owner` FROM %%FLEETS%%
            WHERE `universe` = :universe
            AND `mission` = :fleet_mision
            AND `target_owner` = :id;';
        $FleetsRAW = $db->select($sql, [
            ':universe'     => $this->universe()->current(),
            ':fleet_mision' => Mission::Hold->value,
            ':id'           => $USER['id'],
        ]);
        foreach ($FleetsRAW as $Fleet) {
            $attacker['id'] = $Fleet['owner'];
            $this->fleetFunctions()->SendFleetBack($attacker, $Fleet['id']);
        }
        if (!empty($PLANET)) {
            $PLANET['energy_used'] = '0';
            $PLANET['energy'] = '0';
            $PLANET['metal_mine_porcent'] = '0';
            $PLANET['crystal_mine_porcent'] = '0';
            $PLANET['deuterium_sintetizer_porcent'] = '0';
            $PLANET['solar_plant_porcent'] = '0';
            $PLANET['fusion_plant_porcent'] = '0';
            $PLANET['solar_satelit_porcent'] = '0';
            $PLANET['metal_perhour'] = '0';
            $PLANET['crystal_perhour'] = '0';
            $PLANET['deuterium_perhour'] = '0';
            $this->playerUtilAchievements()->checkPlayerUtilAchievements31($USER['id']);
        }
    }
}
