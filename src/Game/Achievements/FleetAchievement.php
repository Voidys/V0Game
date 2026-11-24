<?php

namespace App\Game\Achievements;

use App\Game\Enum\Mission;

/**
 * Achievement rules tied to fleet flights and the market place: dispatch checks (called from FleetLauncher::sendFleet),
 * arrival and fleet-end checks (FlyingFleetHandler), market offers (MarketPurchase, MissionCaseTrade) and the moon
 * tries (MissionCaseAttack). All checks exit early on cheap conditions; missiles never count as fleets.
 */
class FleetAchievement
{
    use \App\Game\Core\GameUtilities;

    /** Seconds without a break a fleet must have been in flight for "90 days in motion". */
    private const MOTION_SECONDS = 90 * 86400;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Dispatch checks of a freshly launched fleet (already inserted into `fleets`).
     * Long haul(84), That was close(13), Grand tour(88), Frankfurt trainstation(18), 90 days in motion(2).
     *
     * @param int $ownerId owner of the fleet
     * @param int $startPlanetId planet or moon the fleet started from
     * @param int $mission mission id
     * @param int $startTime dispatch time
     * @param int $arrivalTime arrival time at the target (one way)
     * @param float $consumption deuterium burnt as fuel
     * @param int $universe universe of the fleet
     */
    public function onDispatch(int $ownerId, int $startPlanetId, int $mission, int $startTime, int $arrivalTime, float $consumption, int $universe): void
    {
        if ($mission === Mission::Missile->value) {
            return;
        }

        $todo = $this->missingAchievements($ownerId, [2, 13, 18, 84, 88]);

        $this->markMoving($ownerId, $startTime, isset($todo[2]));

        if (isset($todo[84]) && $arrivalTime - $startTime > 86400) {
            $this->achievement()->setAchievement($ownerId, 84);
        }
        if (isset($todo[13])) {
            $this->checkCloseCall($ownerId, $startPlanetId, $startTime);
        }
        if (isset($todo[88])) {
            $this->checkGrandTour($ownerId, $universe);
        }
        if (isset($todo[18]) && $consumption > 0) {
            $this->checkFuelBurnt($ownerId, $startPlanetId, $startTime, $consumption);
        }
    }

    /**
     * An event of a fleet was processed (called by FlyingFleetHandler after the mission ran, with the row as it was
     * claimed). Leap day(85): the fleet arrives at its target on 29 February. When the fleet is gone, the 90 days in
     * motion record is reset if the owner has no other fleet left.
     *
     * @param array<string, mixed> $fleet fleet row as claimed
     */
    public function onEvent(array $fleet): void
    {
        if ($fleet['status'] == FLEET_OUTWARD && $fleet['mission'] != Mission::Missile->value && date('m-d', (int) $fleet['arrival_time']) === '02-29') {
            $this->grant((int) $fleet['owner'], 85);
        }

        $this->onFleetGone((int) $fleet['owner'], (int) $fleet['id']);
    }

    /**
     * Fleet-end bookkeeping for 90 days in motion(2): when the fleet no longer exists and the owner has no other fleet
     * in the air the continuous-flight clock is reset, otherwise the 90 day condition is evaluated.
     */
    public function onFleetGone(int $ownerId, int $fleetId): void
    {
        $exists = $this->db->selectSingle('SELECT 1 AS x FROM %%FLEETS%% WHERE `id` = :id;', [':id' => $fleetId], 'x');
        if ($exists !== false) {
            return;
        }

        $other = $this->db->selectSingle('SELECT 1 AS x FROM %%FLEETS%% WHERE `owner` = :owner AND `mission` != :missile LIMIT 1;', [
            ':owner' => $ownerId,
            ':missile' => Mission::Missile->value,
        ], 'x');
        if ($other === false) {
            // plain read first: the UPDATE would lock the users row inside the event transaction
            $running = $this->db->selectSingle('SELECT `fleets_moving_since` AS s FROM %%USERS%% WHERE `id` = :id;', [':id' => $ownerId], 's');
            if ((int) $running !== 0) {
                $this->db->update('UPDATE %%USERS%% SET `fleets_moving_since` = 0 WHERE `id` = :id AND `fleets_moving_since` != 0;', [':id' => $ownerId]);
            }

            return;
        }
        $this->checkInMotion($ownerId);
    }

    /**
     * 90 days in motion(2): the continuous flight clock (users.fleets_moving_since) is 90 days old.
     */
    private function checkInMotion(int $ownerId): void
    {
        $due = $this->db->selectSingle('SELECT 1 AS x FROM %%USERS%% WHERE `id` = :id AND `fleets_moving_since` BETWEEN 1 AND :limit;', [
            ':id' => $ownerId,
            ':limit' => $this->state->runtime->timestamp - self::MOTION_SECONDS,
        ], 'x');
        if ($due !== false) {
            $this->grant($ownerId, 2);
        }
    }

    /**
     * Starts the continuous flight clock when it is not running (one UPDATE); when it already runs and the
     * achievement is still missing the 90 day condition is evaluated instead. When the owner has no other fleet in
     * flight the clock is restarted unconditionally (a fleet removed by someone else's event never reset it).
     */
    private function markMoving(int $ownerId, int $now, bool $missing): void
    {
        // the new fleet is already inserted: it is alone when at most one row (itself) exists
        $fleets = $this->db->select('SELECT 1 AS x FROM %%FLEETS%% WHERE `owner` = :owner AND `mission` != :missile LIMIT 2;', [
            ':owner' => $ownerId,
            ':missile' => Mission::Missile->value,
        ]);
        if (count($fleets) <= 1) {
            $this->db->update('UPDATE %%USERS%% SET `fleets_moving_since` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $ownerId]);

            return;
        }
        $this->db->update('UPDATE %%USERS%% SET `fleets_moving_since` = :now WHERE `id` = :id AND `fleets_moving_since` = 0;', [
            ':now' => $now,
            ':id' => $ownerId,
        ]);
        if ($missing && $this->db->rowCount() === 0) {
            $this->checkInMotion($ownerId);
        }
    }

    /**
     * That was close(13): a hostile attack (attack, ACS, destruction) of another player lands on the start planet
     * within 5 seconds of the dispatch.
     */
    private function checkCloseCall(int $ownerId, int $planetId, int $now): void
    {
        $hit = $this->db->selectSingle(
            'SELECT 1 AS x FROM %%FLEETS%% WHERE `target_owner` = :owner AND `mission` IN (:attack, :acs, :destruction)
                AND `owner` != :owner AND `end_id` = :planet AND `status` = :outward
                AND `arrival_time` BETWEEN :from AND :to LIMIT 1;',
            [
                ':owner' => $ownerId,
                ':attack' => Mission::Attack->value,
                ':acs' => Mission::Acs->value,
                ':destruction' => Mission::Destruction->value,
                ':planet' => $planetId,
                ':outward' => FLEET_OUTWARD,
                ':from' => $now,
                ':to' => $now + 5,
            ],
            'x',
        );
        if ($hit !== false) {
            $this->grant($ownerId, 13);
        }
    }

    /**
     * Grand tour(88): outward fleets of the player head to every galaxy of the universe at the same time.
     */
    private function checkGrandTour(int $ownerId, int $universe): void
    {
        $galaxies = (int) $this->configs->get($universe)->max_galaxy;
        if ($galaxies < 3) {
            return;
        }
        $count = $this->db->selectSingle(
            'SELECT COUNT(DISTINCT `end_galaxy`) AS c FROM %%FLEETS%% WHERE `owner` = :owner AND `mission` != :missile AND `status` = :outward;',
            [':owner' => $ownerId, ':missile' => Mission::Missile->value, ':outward' => FLEET_OUTWARD],
            'c',
        );
        if ((int) $count >= $galaxies) {
            $this->grant($ownerId, 88);
        }
    }

    /**
     * Frankfurt trainstation(18): 5,000,000 deuterium burnt as fuel by fleets started from one planet within 24 hours.
     * The fuel is booked in hourly buckets (fuel_log); the window is the current and the 23 preceding hours (so it
     * never covers more than 24 hours). Buckets older than the window are pruned.
     */
    private function checkFuelBurnt(int $ownerId, int $planetId, int $now, float $consumption): void
    {
        $hour = intdiv($now, 3600);
        $this->db->insert('INSERT INTO %%FUEL_LOG%% (`planet_id`, `hour`, `amount`) VALUES (:planet, :hour, :amount)
            ON DUPLICATE KEY UPDATE `amount` = `amount` + :amount;', [
            ':planet' => $planetId,
            ':hour' => $hour,
            ':amount' => (int) round($consumption),
        ]);
        $row = $this->db->selectSingle(
            'SELECT COALESCE(SUM(IF(`hour` > :from, `amount`, 0)), 0) AS burnt, COUNT(*) AS buckets FROM %%FUEL_LOG%% WHERE `planet_id` = :planet;',
            [':from' => $hour - 24, ':planet' => $planetId],
        );
        if ((int) $row['buckets'] > 25) {
            $this->db->delete('DELETE FROM %%FUEL_LOG%% WHERE `planet_id` = :planet AND `hour` <= :from;', [':planet' => $planetId, ':from' => $hour - 24]);
        }
        if ((float) $row['burnt'] >= 5000000) {
            $this->grant($ownerId, 18);
        }
    }

    /**
     * A market offer was bought (called with the seller's fleet row as it was before the purchase).
     * Market maker(66): 50 of the player's offers bought by other players; Fleetslot patron(41) via offerEnded().
     *
     * A purchase between linked accounts (multi group, same IP or device) does not count for Market maker. The sale
     * itself is not counted here (that locks the seller's users row, which deadlocks inside the buyer's transaction):
     * the seller id is returned and the caller passes it to countSale() after the commit.
     *
     * @param array<string, mixed> $sellerFleet seller fleet row of the offer
     * @return int|null seller whose sale is to be counted after the commit, null when it does not count
     */
    public function onOfferBought(array $sellerFleet, int $buyerId): ?int
    {
        $sellerId = (int) $sellerFleet['owner'];
        $this->offerEnded($sellerId, (int) $sellerFleet['arrival_time']);

        return (new \App\Game\Core\AccountLinks($this->db))->linked($buyerId, $sellerId, false) ? null : $sellerId;
    }

    /**
     * Counts one sale of the seller and grants Market maker(66) at 50.
     */
    public function countSale(int $sellerId): void
    {
        $this->db->update('UPDATE %%USERS%% SET `market_sales` = `market_sales` + 1 WHERE `id` = :id;', [':id' => $sellerId]);
        $sales = $this->db->selectSingle('SELECT `market_sales` FROM %%USERS%% WHERE `id` = :id;', [':id' => $sellerId], 'market_sales');
        if ((int) $sales >= 50) {
            $this->grant($sellerId, 66);
        }
    }

    /**
     * The hold time of a trade fleet ended: when it was the seller fleet of an unsold offer the offer expired.
     *
     * @param array<string, mixed> $fleet fleet row of the trade mission
     */
    public function onOfferExpired(array $fleet): void
    {
        $trade = $this->db->selectSingle('SELECT `buy_time` FROM %%TRADES%% WHERE `seller_fleet_id` = :id;', [':id' => $fleet['id']]);
        if (!empty($trade) && empty($trade['buy_time'])) {
            $this->offerEnded((int) $fleet['owner'], (int) $fleet['arrival_time']);
        }
    }

    /**
     * Fleetslot patron(41): an offer of the player was open for at least 7 days (from arrival at the market place to
     * the end by purchase or expiry) when it ended. Offers cannot be cancelled.
     */
    private function offerEnded(int $ownerId, int $openedAt): void
    {
        if ($this->state->runtime->timestamp - $openedAt >= 7 * 86400) {
            $this->grant($ownerId, 41);
        }
    }

    /**
     * Do we still have LF?(15): the player's first moon failed to appear in 10 fights at the maximum moon chance
     * (cascading moon chance counter FIRST_MOON_TRYS, counted per defender while no moon was received yet). Called
     * after a failed moon roll, so the fight that just failed is part of the count.
     *
     * @param int $defenderId the player whose planet was attacked
     * @param int|float $tries fights counted including the current one
     * @param int|float $moonsReceived moons the player received so far
     */
    public function checkMoonTries(int $defenderId, int|float $tries, int|float $moonsReceived): void
    {
        if ($tries >= 10 && $moonsReceived == 0) {
            $this->grant($defenderId, 15);
        }
    }

    /**
     * Unlocks the achievement unless the player already has it.
     */
    private function grant(int $userId, int $achievementId): void
    {
        if (!$this->achievement()->checkAchievement($userId, $achievementId)) {
            $this->achievement()->setAchievement($userId, $achievementId);
        }
    }

    /**
     * Subset of the given achievement ids the player does not have yet (one query), keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function missingAchievements(int $userId, array $ids): array
    {
        $rows = $this->db->select(
            'SELECT `achievementID` FROM %%USERS_TO_ACHIEVEMENTS%% WHERE `userID` = :user AND `achievementID` IN (' . implode(',', array_map('intval', $ids)) . ');',
            [':user' => $userId],
        );
        $missing = array_fill_keys($ids, true);
        foreach ($rows as $row) {
            unset($missing[(int) $row['achievementID']]);
        }

        return $missing;
    }
}
