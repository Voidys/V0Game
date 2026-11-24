<?php

namespace App\Game\Core\Player;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;
use App\Game\Core\StoredData;

/**
 * Queue status of all planets of the player (empire page, queue page): recalculated planet rows plus the
 * running construction, shipyard queue and research as display data.
 */
class QueueOverview
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Locks the user row and all planet rows of the player (FOR UPDATE, in a transaction) and brings every planet up to
     * date with ResourceUpdate::CalcResource(). The USER state is refreshed from the locked row first because the
     * page-start copy is stale under concurrent requests and the recalculation writes the b_tech* fields back.
     *
     * @return list<array<string, mixed>> the recalculated planet rows in the player's sort order
     */
    public function recalculatePlanets(): array
    {
        $db = $this->db;
        $db->startTransaction();
        $u = $db->selectSingle("SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE u.id = :userID FOR UPDATE;", [':userID' => $this->state->USER['id']]);
        // Calc from the locked user row. The page-start copy is stale under
        // concurrent requests and SavePlanetToDB writes its b_tech* fields back.
        if (!empty($u)) {
            $this->state->USER = array_merge($this->state->USER, $u);
        }
        $order = $this->state->USER['planet_sort_order'] == 1 ? 'DESC' : 'ASC';

        // locks the planet rows for the recalculation below
        $planetQuery = $db->qb()
            ->select('*')
            ->from($db->table('PLANETS'))
            ->where('id_owner = :userID')
            ->andWhere("destruyed = '0'")
            ->setParameter('userID', $this->state->USER['id'])
            ->forUpdate();
        PlanetQueries::orderBySetting($planetQuery, $this->state->USER['planet_sort'], $order);
        $PlanetsRAW = $db->fetchAll($planetQuery);

        $PLANETS = [];

        $PlanetRess = new ResourceUpdate($this->db, $this->configs, $this->state);

        foreach ($PlanetsRAW as $CPLANET) {
            [$this->state->USER, $CPLANET] = $PlanetRess->CalcResource($this->state->USER, $CPLANET, true);

            $PLANETS[] = $CPLANET;
            unset($CPLANET);
        }

        $db->commit();

        return $PLANETS;
    }

    /**
     * The running construction of a planet: first entry (id, level, seconds left), number of further entries and the
     * seconds until the whole queue is done; false when the queue is empty.
     *
     * @param array<string, mixed> $Planet recalculated planet row
     *
     * @return array{id: int, level: int, left: int, more: int, total: int}|false
     */
    public function building(array $Planet): array|false
    {
        if (empty($Planet['b_building']) || empty($Planet['b_building_id'])) {
            return false;
        }
        $USER = $this->state->USER;
        $queue = StoredData::decode($Planet['b_building_id']);
        if (!is_array($queue) || !isset($queue[0][0], $queue[0][1])) {
            return false;
        }
        $left = $USER['urlaubs_modus'] ? $Planet['b_building'] - $USER['urlaubs_start'] : $Planet['b_building'] - $this->state->runtime->timestamp;
        $last = end($queue);

        return [
            'id' => $queue[0][0],
            'level' => $queue[0][1],
            'left' => max(0, $left),
            'more' => count($queue) - 1,
            'total' => max(0, $left + (isset($last[3]) && count($queue) > 1 ? $last[3] - $Planet['b_building'] : 0)),
        ];
    }

    /**
     * The shipyard queue of a planet: first entry (id, amount, seconds left), number of further entries and the seconds
     * until the whole queue is done; false when the queue is empty.
     *
     * @param array<string, mixed> $Planet recalculated planet row
     *
     * @return array{id: int, amount: int, left: int, more: int, total: int}|false
     */
    public function shipyard(array $Planet): array|false
    {
        if (empty($Planet['b_hangar_id'])) {
            return false;
        }
        $USER = $this->state->USER;
        $queue = StoredData::decode($Planet['b_hangar_id']);
        if (!is_array($queue) || !isset($queue[0][0], $queue[0][1])) {
            return false;
        }
        $total = -$Planet['b_hangar'];
        foreach ($queue as $item) {
            $total += $this->buildFunctions()->getBuildingTime($USER, $Planet, $item[0]) * $item[1];
        }
        $first = $this->buildFunctions()->getBuildingTime($USER, $Planet, $queue[0][0]) * $queue[0][1] - $Planet['b_hangar'];

        return [
            'id' => $queue[0][0],
            'amount' => $queue[0][1],
            'left' => max(0, $first),
            'more' => count($queue) - 1,
            'total' => max(0, $total),
        ];
    }

    /**
     * The running research (global for the player): tech id, target level, seconds left and the planet whose lab runs it; false when idle.
     *
     * @return array{id: int, level: int, left: int, planet: int}|false
     */
    public function research(): array|false
    {
        $USER = $this->state->USER;
        if (empty($USER['b_tech_id']) || empty($USER['b_tech_queue'])) {
            return false;
        }
        $queue = StoredData::decode($USER['b_tech_queue']);
        if (!is_array($queue) || !isset($queue[0][0], $queue[0][1])) {
            return false;
        }
        $left = $USER['urlaubs_modus'] ? $USER['b_tech'] - $USER['urlaubs_start'] : $USER['b_tech'] - $this->state->runtime->timestamp;

        return ['id' => $queue[0][0], 'level' => $queue[0][1], 'left' => max(0, $left), 'planet' => (int) ($USER['b_tech_planet'] ?: $queue[0][4])];
    }
}
