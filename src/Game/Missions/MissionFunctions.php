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

namespace App\Game\Missions;

use App\Game\Enum\MessageType;
use App\Game\Enum\Mission;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Database;
use App\Game\Core\Language;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;

/**
 * Base class of all fleet missions (MissionCase*): holds the flying-fleet row being processed ($_fleet), the
 * pending column changes ($_upd for `fleets`, $_updLog for `log_fleets`) and the common event operations
 * (change state/time, persist, return the fleet home, unload goods, kill the fleet, standard messages). A mission
 * is created by FlyingFleetHandler per due event and then gets TargetEvent / EndStayEvent / ReturnEvent called
 * (MissionInterface). All writes are meant to run inside the transaction opened by FlyingFleetHandler.
 */
class MissionFunctions
{
    use \App\Game\Core\GameUtilities;

    public $kill = 0;
    public $_fleet = [];
    public $_upd = [];
    public $_updLog = [];
    public $eventTime = 0;
    protected $statsHandler;

    protected function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {
        $this->statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
    }

    /**
     * Sets a `fleets` column on the in-memory row and queues it for SaveFleet().
     */
    protected function UpdateFleet($Option, $Value)
    {
        $this->_fleet[$Option] = $Value;
        $this->_upd[$Option] = $Value;
    }

    /**
     * Queues a `log_fleets` column change (history of the fleet) for SaveFleet().
     */
    protected function UpdateFleetLog($Option, $Value)
    {
        $this->_updLog[$Option] = $Value;
    }

    /**
     * Sets the fleet status (FLEET_OUTWARD / FLEET_HOLD / FLEET_RETURN) and the matching next event time
     * (arrival_time, stay_time, return_time) that SaveFleet() writes to `fleets_event`.
     */
    protected function setState($Value)
    {
        $this->_fleet['status'] = $Value;
        $this->_upd['status'] = $Value;

        switch ($Value) {
            case FLEET_OUTWARD:
                $this->eventTime = $this->_fleet['arrival_time'];
                break;
            case FLEET_RETURN:
                $this->eventTime = $this->_fleet['return_time'];
                break;
            case FLEET_HOLD:
                $this->eventTime = $this->_fleet['stay_time'];
                break;
        }
    }

    /**
     * Persists the queued changes: UPDATE fleets (+ log_fleets) and the next event time in fleets_event.
     * For every mission except colonisation, expedition and trade it first stores the current total points of fleet
     * owner and target owner in log_fleets (for ACS all fleets of the group), i.e. the points at the moment of
     * arrival. Does nothing more when the fleet was already killed (KillFleet()).
     */
    protected function SaveFleet()
    {
        $targetMission = $this->_fleet['mission'];
        if ($targetMission != Mission::Colonisation->value && $targetMission != Mission::Expedition->value && $targetMission != Mission::Trade->value) {
            // set points of fleet and target owner during arrival (SaveFleet is always called on TargetEvent)
            if ($this->_fleet['group'] != 0) {
                $sql = 'UPDATE %%LOG_FLEETS%% l
                    LEFT JOIN %%STATPOINTS%% owner_stats ON owner_stats.`id_owner` = l.`owner` AND owner_stats.`stat_type` = 1
                    LEFT JOIN %%STATPOINTS%% target_stats ON target_stats.`id_owner` = l.`target_owner` AND target_stats.`stat_type` = 1
                    SET l.`owner_points` = COALESCE(owner_stats.`total_points`, 0), l.`target_owner_points` = COALESCE(target_stats.`total_points`, 0)
                    WHERE l.`group` = :acsId;';
                $this->db->update($sql, [
                    ':acsId' => $this->_fleet['group'],
                ]);
            } else {
                $sql = 'UPDATE %%LOG_FLEETS%% l
                    LEFT JOIN %%STATPOINTS%% owner_stats ON owner_stats.`id_owner` = l.`owner` AND owner_stats.`stat_type` = 1
                    LEFT JOIN %%STATPOINTS%% target_stats ON target_stats.`id_owner` = l.`target_owner` AND target_stats.`stat_type` = 1
                    SET l.`owner_points` = COALESCE(owner_stats.`total_points`, 0), l.`target_owner_points` = COALESCE(target_stats.`total_points`, 0)
                    WHERE `id` = :fleetId;';
                $this->db->update($sql, [
                    ':fleetId' => $this->_fleet['id'],
                ]);
            }
        }

        if ($this->kill == 1) {
            return;
        }

        if (!empty($this->_upd)) {
            $this->db->updateRows('FLEETS', $this->_upd, ['id' => $this->_fleet['id']]);

            $sql = 'UPDATE %%FLEETS_EVENT%% SET `time` = :time WHERE `fleetID` = :fleetId;';
            $this->db->update($sql, [
                ':time'     => $this->eventTime,
                ':fleetId'  => $this->_fleet['id'],
            ]);
        }

        if (!empty($this->_updLog)) {
            $this->db->updateRows('LOG_FLEETS', $this->_updLog, ['id' => $this->_fleet['id']]);
        }
    }

    /**
     * Reads (and row-locks, FOR UPDATE) the debris columns `der_<resource>` of a debris carrier row (planet or pirate
     * base) together with their sum as `total`.
     *
     * @param string $tableKey PLANETS or PIRATE_BASES
     * @param array<int> $debrisIDs resource ids that can lie in the field (metal, crystal)
     * @return array<string, mixed>|false the row, false when it is gone
     */
    protected function lockDebrisField(string $tableKey, int|string $rowId, array $debrisIDs): array|false
    {
        $columns = array_map(fn(int $debrisID): string => '`der_' . $this->elements()->column($debrisID) . '`', $debrisIDs);
        $qb = $this->db->qb()
            ->select(...$columns)
            ->addSelect('(' . implode(' + ', $columns) . ') AS total')
            ->from($this->db->table($tableKey))
            ->where('`id` = :planetId')
            ->setParameter('planetId', $rowId)
            ->forUpdate();

        return $this->db->fetchRow($qb);
    }

    /**
     * Removes the collected goods from the debris columns of a debris carrier row (never below 0).
     *
     * @param string $tableKey PLANETS or PIRATE_BASES
     * @param array<int, int|float> $collectedGoods resource id => amount taken
     */
    protected function takeDebris(string $tableKey, int|string $rowId, array $collectedGoods): void
    {
        $qb = $this->db->qb()->update($this->db->table($tableKey))
            ->where('`id` = :planetId')
            ->setParameter('planetId', $rowId);
        foreach ($collectedGoods as $debrisID => $amount) {
            $column = '`der_' . $this->elements()->column($debrisID) . '`';
            $qb->set($column, 'GREATEST(0, ' . $column . ' - :take' . $debrisID . ')')->setParameter('take' . $debrisID, $amount);
        }
        $this->db->execute($qb);
    }

    /**
     * Loads the debris from a locked debris field into the fleet cargo. The room is the recyclers' capacity plus whatever
     * the other ships have left after the cargo already on board (it can only reduce it); a field bigger than the room is
     * taken proportionally (ceil) in both resources. Updates the fleet (cargo) and the fleet log (gained_*), and removes
     * the goods from the field row.
     *
     * @param string $tableKey PLANETS or PIRATE_BASES
     * @param array<string, mixed> $targetData row from lockDebrisField() (der_* columns and `total` > 0)
     * @return array{0: array<int, int|float>, 1: array<int, int|float>} collected and remaining goods by resource id
     */
    protected function collectDebris(string $tableKey, array $targetData): array
    {
        $e = $this->elements();
        $debrisIDs = [RESOURCE_METAL, RESOURCE_CRYSTAL];
        $collectedGoods = [];
        $remainingGoods = [];

        $recyclerStorage = 0;
        $otherFleetStorage = 0;
        foreach ($this->fleetFunctions()->unserialize($this->_fleet['ships']) as $shipId => $shipAmount) {
            if ($e->isShipType($shipId, SHIP_TYPE_RECYCLER)) {
                $recyclerStorage += $e->capacity($shipId) * $shipAmount;
            } else {
                $otherFleetStorage += $e->capacity($shipId) * $shipAmount;
            }
        }

        $incomingGoods = 0;
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceID) {
            $incomingGoods += $this->_fleet['resource_' . $e->column($resourceID)];
        }

        $totalStorage = $recyclerStorage + min(0, $otherFleetStorage - $incomingGoods);

        // fast way
        $collectFactor = min(1, $totalStorage / $targetData['total']);
        foreach ($debrisIDs as $debrisID) {
            $fleetColName = 'resource_' . $e->column($debrisID);
            $logColName = 'gained_' . $e->column($debrisID);
            $debrisColName = 'der_' . $e->column($debrisID);

            $collectedGoods[$debrisID] = ceil($targetData[$debrisColName] * $collectFactor);
            $remainingGoods[$debrisID] = max(0, $targetData[$debrisColName] - $collectedGoods[$debrisID]);

            $this->UpdateFleet($fleetColName, $this->_fleet[$fleetColName] + $collectedGoods[$debrisID]);
            $this->UpdateFleetLog($logColName, $collectedGoods[$debrisID]);
        }

        $this->takeDebris($tableKey, $this->_fleet['end_id'], $collectedGoods);

        return [$collectedGoods, $remainingGoods];
    }

    /**
     * Tells the engagement features (contracts, seasons, bounties, wars, battle feed, ...) about a finished real fight.
     * Guarded per feature, so it never breaks the fight.
     *
     * @param array<int|string, string> $userAttack attacking user id => name
     * @param array<int|string, string> $userDefend defending user id => name (left out for pirate bases)
     * @param array<int, int|float> $loot resource id => stolen amount
     * @param array<int, int|float> $debris resource id => new debris
     */
    protected function fireBattleOutcome(array $userAttack, array $userDefend, \App\Game\BattleEngine\BattleResult $combatResult, string $reportID, array $loot, array $debris, string $kind): void
    {
        $hooks = new \App\Game\Engagement\EngagementHooks($this->db, $this->configs, $this->state);
        if (!$hooks->anyEnabled((int) $this->_fleet['universe'])) {
            return;
        }
        $this->achievementGuard()->run(fn() => $this->dispatchBattleOutcome($hooks, $userAttack, $userDefend, $combatResult, $reportID, $loot, $debris, $kind));
    }

    /**
     * Builds the BattleOutcome of the fight and hands it to the engagement hooks (see fireBattleOutcome()).
     *
     * @param array<int|string, string> $userAttack
     * @param array<int|string, string> $userDefend
     * @param array<int, int|float> $loot
     * @param array<int, int|float> $debris
     */
    private function dispatchBattleOutcome(\App\Game\Engagement\EngagementHooks $hooks, array $userAttack, array $userDefend, \App\Game\BattleEngine\BattleResult $combatResult, string $reportID, array $loot, array $debris, string $kind): void
    {
        $outcome = new \App\Game\Engagement\BattleOutcome(
            (int) $this->_fleet['universe'],
            $reportID,
            (int) $this->_fleet['arrival_time'],
            array_filter(array_map('strval', $userAttack), fn($name, $id) => (int) $id > 0, ARRAY_FILTER_USE_BOTH),
            $kind === 'pirate' ? [] : array_filter(array_map('strval', $userDefend), fn($name, $id) => (int) $id > 0, ARRAY_FILTER_USE_BOTH),
            $this->netLosses($combatResult),
            (string) $combatResult->won,
            $loot,
            $debris,
            (int) $this->_fleet['end_galaxy'],
            (int) $this->_fleet['end_system'],
            (int) $this->_fleet['end_planet'],
            (int) $this->_fleet['end_type'],
            (int) $this->_fleet['end_id'],
            $kind,
            $this->startValuePerUser($combatResult),
        );
        $hooks->onBattle($outcome);
    }

    /**
     * The fight losses for the engagement features: the defense the planet rebuilds for free after the fight
     * (BattleResult::$repaired) is not a real loss, so its metal+crystal value is taken off the planet owner's and the
     * defending side's loss (otherwise a defense-heavy alt makes staged fights look far bigger than they cost).
     */
    private function netLosses(\App\Game\BattleEngine\BattleResult $combatResult): \App\Game\BattleEngine\BattleLosses
    {
        $losses = $combatResult->losses;
        $owner = (int) ($this->_fleet['target_owner'] ?? 0);
        $repaired = 0;
        foreach ($combatResult->repaired as $unitId => $row) {
            $repaired += (float) ($row['units'] ?? 0) * $this->elements()->cost((int) $unitId)->metalCrystal();
        }
        if ($repaired <= 0) {
            return $losses;
        }
        // the side total includes the defense; the per-user values count ships only (the engines leave defense out), so
        // the planet owner's entry is only lowered when it holds more than its ships would (never below 0)
        $taken = min($repaired, (float) $losses->defenderValue);
        $perUser = $losses->valuePerUser;
        if ($owner > 0 && isset($perUser['defender'][$owner])) {
            $perUser['defender'][$owner] = max(0, $perUser['defender'][$owner] - max(0, $taken - ($losses->defenderValue - array_sum($perUser['defender']))));
        }

        return new \App\Game\BattleEngine\BattleLosses($losses->attackerValue, max(0, $losses->defenderValue - $taken), $perUser, $losses->capacityPerUser);
    }

    /**
     * Value (metal + crystal + deuterium) of the units every user brought into the fight, from the start state of the round log.
     *
     * @return array<int, int|float> user id => value
     */
    private function startValuePerUser(\App\Game\BattleEngine\BattleResult $combatResult): array
    {
        $values = [];
        foreach ([$combatResult->startAttackers(), $combatResult->startDefenders()] as $fleets) {
            foreach ($fleets as $fleet) {
                $userId = (int) $fleet->userId();
                foreach ($fleet->units as $unitId => $amount) {
                    $values[$userId] = ($values[$userId] ?? 0) + $amount * $this->elements()->cost((int) $unitId)->metalCrystalDeuterium();
                }
            }
        }

        return $values;
    }

    /**
     * Checks the recycling achievements for a fleet that found an empty debris field (nothing collected).
     *
     * @param array<mixed> $collectedGoods
     */
    protected function checkEmptyRecycling(array $collectedGoods): void
    {
        $e = $this->elements();
        $fleetData = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $recCount = 0;
        foreach ($fleetData as $shipId => $shipAmount) {
            if ($e->isShipType($shipId, SHIP_TYPE_RECYCLER)) {
                $recCount += $shipAmount;
            }
        }
        $this->achievementGuard()->run(fn() => $this->miscAchievements()->checkRecAchievements($this->_fleet['owner'], $collectedGoods, $recCount));
    }

    /**
     * Sends the recycling report to the fleet owner: what was collected, the total the field held before, and how many
     * recyclers the remainder needs.
     *
     * @param array<int, int|float> $collectedGoods by resource id
     * @param array<int, int|float> $remainingGoods by resource id
     * @param array<string, mixed> $targetData row from lockDebrisField() (the field before the collection)
     */
    protected function sendRecyclingReport(array $collectedGoods, array $remainingGoods, array $targetData): void
    {
        $e = $this->elements();
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);
        $LNG->includeData(['INGAME']);

        $Message = $LNG->format(
            'sys_recy_gotten',
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($collectedGoods[RESOURCE_METAL]),
            $this->viewHelpers()->prettyNumber($collectedGoods[RESOURCE_CRYSTAL]),
            $this->viewHelpers()->prettyNumber($targetData['der_' . $e->column(RESOURCE_METAL)]),
            $this->viewHelpers()->prettyNumber($targetData['der_' . $e->column(RESOURCE_CRYSTAL)]),
            $LNG['tech'][RESOURCE_METAL],
            $LNG['tech'][RESOURCE_CRYSTAL],
        );
        $Message .= '<br>';
        $Message .= $LNG->format(
            'bs_derbis_raport',
            $this->viewHelpers()->prettyNumber(ceil(array_sum($remainingGoods) / $e->capacity(SHIP_RECYCLER))),
            $LNG['tech'][SHIP_RECYCLER],
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::Transport->value,
            $LNG['sys_recy_report'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * Locks the user and planet rows and brings the planet's resources up to date for $time (ResourceUpdate), so a
     * fleet landing/arriving sees current stocks.
     * @param int|null $time timestamp to calculate to; null = now.
     */
    protected function UpdatePlanet($userId, $planetId, $time = null)
    {
        $sql = 'SELECT u.*, us.* FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id`
            WHERE u.`id` = :userId FOR UPDATE;';
        $targetUser = $this->db->selectSingle($sql, [
            ':userId'   => $userId,
        ]);
        $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;";
        $planet = $this->db->selectSingle($sql, [
            ':planetId' => $planetId,
        ]);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);
        $planetUpdater->CalcResource($targetUser, $planet, true, $time);
    }

    /**
     * Lands the fleet: adds its ships and cargo to the planet (start planet when $onStart, else the target planet
     * with its owner), after updating that planet's resources up to the landing time, and deletes the fleet.
     * @param bool $onStart true: return home (owner, start_id, return_time); false: land at the target (target_owner,
     * end_id, arrival_time).
     */
    protected function RestoreFleet($onStart = true)
    {
        $e = $this->elements();

        $fleetData = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $userId = $onStart == true ? $this->_fleet['owner'] : $this->_fleet['target_owner'];
        $planetId = $onStart == true ? $this->_fleet['start_id'] : $this->_fleet['end_id'];
        $time = $onStart == true ? $this->_fleet['return_time'] : $this->_fleet['arrival_time'];

        $this->UpdatePlanet($userId, $planetId, $time);

        $updateQuery = [];

        $param = [
            ':metal'        => $this->_fleet['resource_metal'],
            ':crystal'      => $this->_fleet['resource_crystal'],
            ':deuterium'    => $this->_fleet['resource_deuterium'],
            ':planetId'     => $planetId,
        ];

        foreach ($fleetData as $shipId => $shipAmount) {
            $updateQuery[] = "p.`" . $e->column($shipId) . "` = p.`" . $e->column($shipId)
                . "` + :" . $e->column($shipId);
            $param[':' . $e->column($shipId)] = $shipAmount;
        }

        $sql = 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
            ' . implode(', ', $updateQuery) . ',
            p.`metal` = p.`metal` + :metal,
            p.`crystal` = p.`crystal` + :crystal,
            p.`deuterium` = p.`deuterium` + :deuterium
            WHERE p.`id` = :planetId AND u.`id` = p.`id_owner`;';

        $this->db->update($sql, $param);

        $this->KillFleet();
    }

    /**
     * Unloads the cargo (metal, crystal, deuterium) into the start planet ($onStart) or the target planet and empties
     * the fleet's cargo columns; the fleet itself keeps flying.
     */
    protected function StoreGoodsToPlanet($onStart = false)
    {
        $sql = 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
            `metal` = `metal` + :metal,
            `crystal` = `crystal` + :crystal,
            `deuterium` = `deuterium` + :deuterium
            WHERE p.`id` = :planetId AND u.`id` = p.`id_owner`;';

        $this->db->update($sql, [
            ':metal'        => $this->_fleet['resource_metal'],
            ':crystal'      => $this->_fleet['resource_crystal'],
            ':deuterium'    => $this->_fleet['resource_deuterium'],
            ':planetId'     => ($onStart == true ? $this->_fleet['start_id'] : $this->_fleet['end_id']),
        ]);

        $this->UpdateFleet('resource_metal', '0');
        $this->UpdateFleet('resource_crystal', '0');
        $this->UpdateFleet('resource_deuterium', '0');
    }

    /**
     * Hostile missions must not act on a target whose owner is in vacation mode (it may have been
     * activated after the fleet was sent): the fleet turns around instead. Returns true when it did.
     */
    protected function returnIfTargetInVacation($targetOwnerId): bool
    {
        if (empty($targetOwnerId)) {
            return false;
        }

        $sql = 'SELECT `urlaubs_modus` FROM %%USERS%% WHERE `id` = :userId;';
        $vacation = $this->db->selectSingle($sql, [
            ':userId' => $targetOwnerId,
        ], 'urlaubs_modus');

        if (empty($vacation) && !$this->targetIsReturning((int) $targetOwnerId)) {
            return false;
        }

        if ($this->_fleet['group'] != 0 && $this->_fleet['mission'] == Mission::Attack->value) {
            $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                FROM %%AKS%%
                LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                WHERE %%AKS%%.`id` = :acsId;';
            $this->db->delete($sql, [
                ':acsId' => $this->_fleet['group'],
            ]);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();

        return true;
    }

    /**
     * Whether an aggressive mission (attack, ACS, spy, destruction, missile) arrives at a player who is under the
     * returning-player protection (it started after the fleet was sent): the fleet turns around like at a vacation player.
     */
    private function targetIsReturning(int $targetOwnerId): bool
    {
        if (!in_array((int) $this->_fleet['mission'], \App\Game\Engagement\Returning\ReturningPlayers::AGGRESSIVE, true)) {
            return false;
        }

        return $this->returningPlayers()->protectedUntil((int) $this->_fleet['universe'], $targetOwnerId) > 0;
    }

    /**
     * Deletes the fleet and its event row immediately and marks the mission as killed so SaveFleet() writes nothing.
     */
    protected function KillFleet()
    {
        $this->kill = 1;
        $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
            FROM %%FLEETS%% LEFT JOIN %%FLEETS_EVENT%% on `id` = `fleetId`
            WHERE `id` = :fleetId';

        $this->db->delete($sql, [
            ':fleetId'  => $this->_fleet['id'],
        ]);
    }

    /**
     * Language pack (L18N, FLEET, TECH, CUSTOM) for a message to a player: of the given language code, else the
     * player's setting by user id, else the language of the current request. Per-language packs are cached for the
     * process and returned as copies.
     */
    protected function getLanguage($language = null, $userID = null)
    {
        if (is_null($language) && !is_null($userID)) {
            $sql = 'SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
            $language = $this->db->selectSingle($sql, [
                ':userId' => $userID,
            ], 'lang');
        }

        if ($language === null) {
            // language of the current request (cookie / browser): not cacheable
            $LNG = new Language($this->configs, $language);
            $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM']);
            return $LNG;
        }

        // Reading and unserializing the language packs is the expensive part: load them once per language
        // and process, hand out copies (callers may add data to their object).
        if (!isset(self::$languageCache[$language])) {
            $LNG = new Language($this->configs, $language);
            $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM']);
            self::$languageCache[$language] = $LNG;
        }

        return clone self::$languageCache[$language];
    }

    /** @var array<string, Language> language packs of getLanguage() by language code */
    private static array $languageCache = [];

    /**
     * Sends the "fleet returned" system message to the fleet owner (game messages, system type, timestamped with
     * return_time): start/target planet, the cargo brought home and, if the ship counts differ from the start, the
     * list of lost/missing ships per type.
     */
    public function sendAttackReturnMessage()
    {
        $e = $this->elements();

        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetNameStart = $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetNameEnd	= $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['end_id'],
        ], 'name');

        $Message = $LNG->format(
            'sys_fleet_won',
            $planetNameEnd,
            GetTargetAddressLink($this->_fleet, ''),
            $planetNameStart,
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
        );

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $fleetArrayStart = $this->fleetFunctions()->unserialize($this->_fleet['start_ships']);

        if (isset($fleetArrayStart)) {
            $fleetAmmount = array_sum($fleetArray);
            $fleetAmmountStart = array_sum($fleetArrayStart);
            if ($fleetAmmountStart != $fleetAmmount) {
                if ($fleetAmmountStart > $fleetAmmount) {
                    $Message .= '<br><br>' . $LNG->format('sys_expe_back_home_ships_lost');
                }
                foreach ($e->ids('fleet') as $shipId) {
                    if (isset($fleetArrayStart[$shipId]) && isset($fleetArray[$shipId])) {
                        if ($fleetArrayStart[$shipId] != $fleetArray[$shipId]) {
                            $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId] - $fleetArray[$shipId]));
                        }
                    } elseif (isset($fleetArrayStart[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId]));
                    } elseif (isset($fleetArray[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArray[$shipId]));
                    }
                }
            }
        }

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * Tells the fleet owner that the targeted pirate base no longer exists (message dated now).
     */
    protected function sendPiratebaseGoneMessage()
    {
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $message = $LNG->format(
            'sys_mess_piratebase_gone',
            GetTargetAddressLink($this->_fleet, ''),
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['sys_mess_fleetback'],
            $message,
            $this->state->runtime->timestamp,
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * Return if the Battlereport has more destroyed Units than the given limit.
     *
     * @param int $units
     * @param int $universe
     *
     * @return boolean
     */
    public function checkForTopKB($units, $universe)
    {
        $db = $this->db;
        $sql = "SELECT `units` FROM %%TOPKB%% 
            WHERE `universe` = :universe AND `memorial` = 0 
            ORDER BY `units` DESC LIMIT 1 OFFSET :offset;";
        $topKbUnits = $db->selectSingle($sql, [
            ':universe'     => $universe,
            ':offset'  => TOPKB_LIMIT - 1,
        ], 'units');

        $result = false;
        if ($units > $topKbUnits) {
            $result = true;
        }

        return $result;
    }
}
