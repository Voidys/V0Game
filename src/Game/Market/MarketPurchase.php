<?php

namespace App\Game\Market;

use App\Game\Enum\MessageType;
use App\Game\Enum\Mission;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Buying a marketplace offer: validates the offer for the current player, sends the buyer's cargo fleet, re-routes the
 * seller's held fleet to the buyer, updates the fleet log, the fleet event and the trade row, and notifies both
 * players by system message.
 *
 * The caller (MarketPlaceController::show) wraps buy() in a database transaction; the offer row is locked
 * (FOR UPDATE) so that a concurrent buyer finds it no longer holding. Every failure is returned as a translated
 * message string (nothing is written before the last validation passed, only the in-memory PLANET row is touched
 * and the caller restores it on rollback).
 */
class MarketPurchase
{
    use GameUtilities;

    /** Seller whose sale is counted for Market maker after the commit (see afterCommit()). */
    private ?int $soldTo = null;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private ?MarketRules $rules = null,
    ) {
        $this->rules ??= new MarketRules($db, $configs, $state);
    }

    /**
     * Buys the offer $FleetID (shipType: 1 = prefer small cargo, else large cargo).
     *
     * Side effects: deducts the paid resources and fuel from the current planet (in memory), creates the buyer's fleet,
     * updates fleets/log_fleets/fleets_event/trades of the seller's fleet and sends two system messages.
     *
     * @return string translated message for the player (success or the reason the purchase was refused)
     */
    public function buy(int $FleetID, string $shipType): string
    {
        $LNG = $this->state->LNG;

        $this->lockBuyer();

        $vmode = $this->rules->checkVmode();
        if (!$vmode['buyable']) {
            return $vmode['reason'];
        }
        $checkResult = $this->rules->checkSlots($this->state->USER);
        if ($checkResult['result'] < 0) {
            return $checkResult['message'];
        }

        $fleetResult = $this->loadOffer($FleetID);
        if (!is_array($fleetResult)) {
            return $fleetResult;
        }
        $refusal = $this->validateOffer($fleetResult);
        if ($refusal !== null) {
            return $refusal;
        }
        $isFleetTrade = ($fleetResult['transaction_type'] == 1);

        $trip = $this->planBuyerTrip($fleetResult, $isFleetTrade, $shipType);
        if (is_string($trip)) {
            return $trip;
        }
        ['fleetArray' => $fleetArray, 'consumption' => $consumption, 'fleetResource' => $fleetResource, 'marketplace' => $marketplace] = $trip;

        $buyerfleet = $this->sendBuyerFleet($fleetResult, $isFleetTrade, $trip);

        $USER_2 = $this->db->selectSingle(
            "SELECT * FROM %%USERS%% as u 
            INNER JOIN %%USERS_SETTINGS%% as s ON s.`user_id` = u.`id` 
            WHERE u.`id` = :userId;",
            [':userId' => $fleetResult['owner']],
        );
        $sellerFleet = $this->fleetFunctions()->unserialize($fleetResult['ships']);
        $times = $this->sellerFleetTimes($fleetResult, $isFleetTrade, $sellerFleet, $USER_2, $marketplace);
        $this->rerouteSellerFleet($FleetID, $fleetResult, $isFleetTrade, $marketplace, $times, $buyerfleet);

        $this->notify($fleetResult, $isFleetTrade, $marketplace, $fleetResource, $consumption, $USER_2);

        // the cargo ships the buyer just sent (not the seller's fleet)
        $LC = $fleetArray[202] ?? 0;
        $HC = $fleetArray[203] ?? 0;
        return $LNG->format('market_p_msg_sent', $LC, $HC);
    }

    /**
     * Serialises parallel purchases of the same buyer (like FleetDispatch::dispatch()): locks the buyer's user row, then
     * the planet row (always in this order, before the offer row) and re-reads both, so the slot check, the cargo/fuel
     * and the resource affordability run on the locked rows and not on the copy read at the start of the request.
     */
    private function lockBuyer(): void
    {
        $user = $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE `id` = :userId FOR UPDATE;', [':userId' => $this->state->USER['id']]);
        if (is_array($user)) {
            $this->state->USER = array_merge($this->state->USER, $user);
        }
        $planet = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;', [':planetId' => $this->state->PLANET['id']]);
        if (is_array($planet)) {
            $this->state->PLANET = array_merge($this->state->PLANET, $planet);
        }
    }

    /**
     * Loads and locks (FOR UPDATE) the open offer of the current universe: same conditions as the offer list (trade fleet
     * holding at the market, stay not over, trade row without buyer), so a sold offer cannot be bought again.
     *
     * @return array<string, mixed>|string offer row (fleet + trade columns + seller's ally_id) or the "not found" message
     */
    private function loadOffer(mixed $FleetID): array|string
    {
        $LNG = $this->state->LNG;
        $sql = "SELECT f.*, t.*, u.`ally_id` FROM %%FLEETS%% f
            JOIN %%TRADES%% t ON f.`id` = t.`seller_fleet_id`
            JOIN %%USERS%% u ON f.`owner` = u.`id`
            WHERE f.`id` = :id AND f.`status` = :fleetState AND f.`mission` = :mission AND f.`universe` = :universe
              AND f.`stay_time` > :now AND t.`buyer_fleet_id` IS NULL FOR UPDATE;";
        $fleetResult = $this->db->selectSingle($sql, [
            ':id' => $FleetID,
            ':fleetState' => FLEET_HOLD,
            ':mission' => Mission::Trade->value,
            ':universe' => $this->universe()->current(),
            ':now' => $this->state->runtime->timestamp,
        ]);

        if ($this->db->rowCount() == 0) {
            return $LNG['market_p_msg_not_found'];
        }

        return $fleetResult;
    }

    /**
     * Checks that the current player may buy this offer: not their own, the seller's start planet still exists, a valid wanted resource, the diplomacy filter
     * and push prevention.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @return string|null the refusal message, null when the offer is buyable
     */
    private function validateOffer(array $fleetResult): ?string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        // an offer cannot be bought by its seller
        if ($fleetResult['owner'] == $USER['id']) {
            return $LNG['market_p_msg_own_offer'];
        }

        // the seller's fleet flies back to its start planet: when that planet is gone or destroyed the trade can never settle
        // (the buyer's payment would return while the seller's ships are delivered), so the offer is void (offer row is locked)
        // the planet row is locked last (after buyer user, buyer planet and offer), so a concurrent destruction waits for this purchase
        $sellerPlanet = $this->db->selectSingle(
            'SELECT `id`, `destruyed` FROM %%PLANETS%% WHERE `id` = :id FOR UPDATE;',
            [':id' => $fleetResult['start_id']],
        );
        if (empty($sellerPlanet) || !empty($sellerPlanet['destruyed'])) {
            return $LNG['market_p_msg_not_found'];
        }

        // a crafted offer may carry an invalid wanted resource
        if ($fleetResult['ex_resource_type'] >= 4 || $fleetResult['ex_resource_type'] <= 0) {
            return $LNG['market_p_msg_wrong_resource_type'];
        }

        // trade blocks (both directions) apply to every offer, the pact/partner filter to the offers that set one
        // pacts between the alliances
        $sql = "SELECT * FROM %%DIPLO%% WHERE ((owner_1 = :ow AND owner_2 = :ow2) OR"
            . " (owner_2 = :ow AND owner_1 = :ow2)) AND accept = 1;";
        $res = $this->db->select($sql, [
            ':ow' => $USER['ally_id'],
            ':ow2' => $fleetResult['ally_id'],
        ]);
        $level = null;
        if ($this->db->rowCount() != 0) {
            $level = $res[0]['level'];
        }
        $buy = $this->rules->checkDiplo(
            $fleetResult['filter_visibility'],
            $level,
            $fleetResult['ally_id'],
            $USER['ally_id'],
            $fleetResult['owner'],
        );
        if (!$buy['buyable']) {
            return $buy['reason'];
        }

        if ($this->rules->checkPush($fleetResult, $USER['id'], $fleetResult['owner'])) {
            return $LNG['market_buyable_no_tech'];
        }

        return null;
    }

    /**
     * Chooses the cargo ships the buyer sends and computes the trip, its fuel cost and the paid cargo.
     *
     * For a fleet offer the buyer also pays the fuel of the sold fleet's trip. The buyer must hold the paid resources
     * plus fuel on the current planet. On success the cost is already deducted from the in-memory PLANET row.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @param mixed                $shipType    1 = prefer small cargo
     * @return array{fleetArray: array<int, int|float>, consumption: int|float, fleetResource: array<int, int|float>, marketplace: int, startTime: int, stayTime: int, endTime: int}|string
     *         the trip plan, or the refusal message (not enough ships / resources)
     */
    private function planBuyerTrip(array $fleetResult, bool $isFleetTrade, mixed $shipType): array|string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        //if not in range 1-3
        // the buyer fleet carries the payment; for a resource offer it also has to hold the larger offered amount (the
        // seller's goods travel back in it), a fleet offer carries no goods
        if (!$isFleetTrade && $fleetResult['resource_amount'] > $fleetResult['ex_resource_amount']) {
            $amount['ex_resource_amount'] = $fleetResult['resource_amount'];
        } else {
            $amount['ex_resource_amount'] = $fleetResult['ex_resource_amount'];
        }
        $fleetNeeded = $this->calculateFleetSize($amount, $shipType);

        // not enough ships
        if (!$fleetNeeded) {
            return $LNG['market_p_msg_more_ships_is_needed'];
        }

        $fleetArray = array_filter($fleetNeeded);
        $amount = $fleetResult['ex_resource_amount'];
        $marketplace = ($this->configs->get($USER['universe'])->max_planets + 2);

        $Distance = $this->buyerTripDistance($fleetResult, $isFleetTrade, $marketplace);
        [$Duration, $consumption] = $this->buyerTripDurationAndFuel($fleetArray, $fleetResult, $isFleetTrade, $Distance);

        $fleetStartTime = $Duration + $this->state->runtime->timestamp;
        $fleetStayTime = $fleetStartTime;
        $fleetEndTime = $fleetStayTime + $Duration;

        $tooFar = $this->flightTimeRefusal($fleetResult, $isFleetTrade, $marketplace, $Duration);
        if ($tooFar !== null) {
            return $tooFar;
        }

        $fleetResource = $this->offeredCargo($fleetResult, $amount);

        if (!$this->payTrip($fleetResource, $consumption)) {
            return $LNG['market_p_msg_resources_error'];
        }

        return [
            'fleetArray' => $fleetArray,
            'consumption' => $consumption,
            'fleetResource' => $fleetResource,
            'marketplace' => $marketplace,
            'startTime' => $fleetStartTime,
            'stayTime' => $fleetStayTime,
            'endTime' => $fleetEndTime,
        ];
    }

    /**
     * The seller's flight time limit (filter_flighttime in seconds, 0 = none): neither the buyer's trip nor the seller
     * fleet's trip to the buyer may take longer.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @return string|null refusal message, null when the trade is within the limit
     */
    private function flightTimeRefusal(array $fleetResult, bool $isFleetTrade, int|float $marketplace, int|float $buyerDuration): ?string
    {
        $limit = (int) $fleetResult['filter_flighttime'];
        if ($limit <= 0) {
            return null;
        }

        $seller = $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE `id` = :userId;', [':userId' => $fleetResult['owner']]);
        $sellerTimes = $this->sellerFleetTimes($fleetResult, $isFleetTrade, $this->fleetFunctions()->unserialize($fleetResult['ships']), $seller, (int) $marketplace);
        $sellerDuration = max(0, $sellerTimes['arrival'] - $this->state->runtime->timestamp);

        return max($buyerDuration, $sellerDuration) > $limit ? $this->state->LNG['market_p_msg_too_far'] : null;
    }

    /**
     * Distance of the buyer's trip: to the seller's planet for fleet offers, otherwise to the market slot
     * (max_planets + 2) of the current system.
     *
     * @param array<string, mixed> $fleetResult offer row
     */
    private function buyerTripDistance(array $fleetResult, bool $isFleetTrade, int|float $marketplace): int|float
    {
        if ($isFleetTrade) {
            return $this->fleetFunctions()->getTargetDistance(
                [$this->state->PLANET['galaxy'], $this->state->PLANET['system'], $this->state->PLANET['planet']],
                [$fleetResult['end_galaxy'], $fleetResult['end_system'], $fleetResult['end_planet']],
            );
        }

        return $this->fleetFunctions()->getTargetDistance(
            [$this->state->PLANET['galaxy'], $this->state->PLANET['system'], $this->state->PLANET['planet']],
            [$this->state->PLANET['galaxy'], $this->state->PLANET['system'], $marketplace],
        );
    }

    /**
     * One-way duration and deuterium consumption of the buyer's fleet; for fleet offers the consumption of the
     * offered fleet's own flight over the same distance is added.
     *
     * @param array<int, int|float> $fleetArray ship id => count
     * @param array<string, mixed> $fleetResult offer row
     * @return array{0: int|float, 1: int|float} [duration, consumption]
     */
    private function buyerTripDurationAndFuel(array $fleetArray, array $fleetResult, bool $isFleetTrade, int|float $Distance): array
    {
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($this->state->USER);
        $SpeedAllMin = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $Duration = $this->fleetFunctions()->missionDurationFor(10, $SpeedAllMin, $Distance, $SpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $Duration, $Distance, $userModel, $SpeedFactor);

        if ($isFleetTrade) {
            $fleet = $this->fleetFunctions()->unserialize($fleetResult['ships']);
            $fleetSpeedAllMin = $this->fleetFunctions()->fleetMaxSpeedFor($fleet, $userModel);
            $fleetDuration = $this->fleetFunctions()->missionDurationFor(10, $fleetSpeedAllMin, $Distance, $SpeedFactor, $userModel);
            $bonusConsumption = $this->fleetFunctions()->fleetConsumptionFor($fleet, $fleetDuration, $Distance, $userModel, $SpeedFactor);
            $consumption += $bonusConsumption;
        }

        return [$Duration, $consumption];
    }

    /**
     * The resources the buyer's fleet carries to the seller: the offer's counter-value in its resource type.
     *
     * @param array<string, mixed> $fleetResult offer row (ex_resource_type 1 metal, 2 crystal, 3 deuterium)
     * @return array<int, int|float> resource id => amount
     */
    private function offeredCargo(array $fleetResult, int|float $amount): array
    {
        $met = 0;
        $cry = 0;
        $deu = 0;
        if ($fleetResult['ex_resource_type'] == 1) {
            $met = $amount;
        } elseif ($fleetResult['ex_resource_type'] == 2) {
            $cry = $amount;
        } elseif ($fleetResult['ex_resource_type'] == 3) {
            $deu = $amount;
        }

        return [
            RESOURCE_METAL => $met,
            RESOURCE_CRYSTAL => $cry,
            RESOURCE_DEUT => $deu,
        ];
    }

    /**
     * Takes the cargo and the fuel from the current planet when it can afford both.
     *
     * Side effect: reduces metal, crystal and deuterium of state->PLANET (nothing is saved here).
     *
     * @param array<int, int|float> $fleetResource resource id => amount
     * @return bool false (and nothing taken) when a resource is missing
     */
    private function payTrip(array $fleetResource, int|float $consumption): bool
    {
        $elements = $this->elements();

        if (
            $this->state->PLANET[$elements->column(RESOURCE_METAL)] - $fleetResource[RESOURCE_METAL] < 0
            || $this->state->PLANET[$elements->column(RESOURCE_CRYSTAL)] - $fleetResource[RESOURCE_CRYSTAL] < 0
            || $this->state->PLANET[$elements->column(RESOURCE_DEUT)] - $fleetResource[RESOURCE_DEUT] - $consumption < 0
        ) {
            return false;
        }

        $this->state->PLANET[$elements->column(RESOURCE_METAL)] -= $fleetResource[RESOURCE_METAL];
        $this->state->PLANET[$elements->column(RESOURCE_CRYSTAL)] -= $fleetResource[RESOURCE_CRYSTAL];
        $this->state->PLANET[$elements->column(RESOURCE_DEUT)] -= $fleetResource[RESOURCE_DEUT] + $consumption;

        return true;
    }

    /**
     * Creates the buyer's fleet from the current planet to the market (resource offer, mission trade) or to the
     * seller's planet (fleet offer, mission transport), carrying the paid resources.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @param array<string, mixed> $trip        plan from planBuyerTrip()
     * @return int|string id of the new fleet
     */
    private function sendBuyerFleet(array $fleetResult, bool $isFleetTrade, array $trip): mixed
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $marketplace = $trip['marketplace'];

        $buyerMission = $isFleetTrade ? Mission::Transport->value : Mission::Trade->value;
        $targetGalaxy = $isFleetTrade ? $fleetResult['start_galaxy'] : $PLANET['galaxy'];
        $targetSystem = $isFleetTrade ? $fleetResult['start_system'] : $PLANET['system'];
        $targetPlanet = $isFleetTrade ? $fleetResult['start_planet'] : $marketplace;
        $targetType = $isFleetTrade ? $fleetResult['start_type'] : 1;

        return $this->fleetFunctions()->sendFleet(
            $trip['fleetArray'],
            $buyerMission /*buyerfleet to automatically get rerouted*/,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $fleetResult['owner'],
            $fleetResult['start_id'],
            $targetGalaxy,
            $targetSystem,
            $targetPlanet,
            $targetType,
            $trip['fleetResource'],
            $trip['startTime'],
            $trip['stayTime'],
            $trip['endTime'],
            0,
            0,
            1,
            $trip['consumption'],
        );
    }

    /**
     * Timetable of the seller's fleet once it is re-routed to the buyer: a resource offer of the same system keeps its
     * original start/arrival and stays 6 hours at the market; other resource offers start now and stay 6 hours; a fleet
     * offer travels without staying.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @param array<int, int>      $sellerFleet ship id => amount of the held fleet
     * @param array<string, mixed> $USER_2      seller (users + settings row)
     * @return array{start: int, arrival: int, stay: int, end: int} start_time, arrival_time, stay_time, return_time
     */
    private function sellerFleetTimes(array $fleetResult, bool $isFleetTrade, array $sellerFleet, array $USER_2, int $marketplace): array
    {
        $PLANET = $this->state->PLANET;
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $Distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $marketplace],
            [$fleetResult['end_galaxy'], $fleetResult['end_system'], $marketplace],
        );
        $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($sellerFleet, $USER_2);
        $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $USER_2);
        if ($PLANET['galaxy'] == $fleetResult['end_galaxy'] && $PLANET['system'] == $fleetResult['end_system'] && !$isFleetTrade) {
            $Distance = $this->fleetFunctions()->getTargetDistance(
                [$fleetResult['start_galaxy'], $fleetResult['start_system'], $marketplace],
                [$fleetResult['end_galaxy'], $fleetResult['end_system'], $marketplace],
            );
            $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $USER_2);
            $startTime = $fleetResult['start_time'];
            $fleetStartTime = $fleetResult['arrival_time'];
            $fleetStayTime = $fleetStartTime + TIME_6_HOURS;
            $fleetEndTime = $fleetStayTime + $Duration;
        } elseif (!$isFleetTrade) {
            $startTime = $this->state->runtime->timestamp;
            $fleetStartTime = $Duration + $this->state->runtime->timestamp;
            $fleetStayTime = $fleetStartTime + TIME_6_HOURS;
            $fleetEndTime = $fleetStayTime + $Duration;
        } else {
            $startTime = $fleetResult['start_time'];
            $fleetStartTime = $Duration + $this->state->runtime->timestamp;
            $fleetStayTime = $fleetStartTime;
            $fleetEndTime = $fleetStayTime + $Duration;
        }

        return ['start' => $startTime, 'arrival' => $fleetStartTime, 'stay' => $fleetStayTime, 'end' => $fleetEndTime];
    }

    /**
     * Turns the seller's holding fleet into an outward fleet to the buyer: updates fleets (cargo emptied, new target
     * and times, cannot be recalled), its log row, its fleet event time, and links the buyer's fleet in the trade row.
     *
     * @param array<string, mixed> $fleetResult offer row
     * @param array{start: int, arrival: int, stay: int, end: int} $times from sellerFleetTimes()
     */
    private function rerouteSellerFleet(mixed $FleetID, array $fleetResult, bool $isFleetTrade, int $marketplace, array $times, mixed $buyerfleet): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $db = $this->db;
        $route = [
            'mission' => $fleetResult['transaction_type'] == 0 ? Mission::Trade->value : Mission::Transfer->value,
            'target_owner' => $USER['id'],
            'start_time' => $times['start'],
            'arrival_time' => $times['arrival'],
            'stay_time' => $times['stay'],
            'return_time' => $times['end'],
            'end_id' => $isFleetTrade ? $PLANET['id'] : 0,
            'end_planet' => $isFleetTrade ? $PLANET['planet'] : $marketplace,
            'end_system' => $PLANET['system'],
            'end_galaxy' => $PLANET['galaxy'],
        ];
        $db->updateRows('FLEETS', $route + [
            'resource_metal' => 0,
            'resource_crystal' => 0,
            'resource_deuterium' => 0,
            'status' => FLEET_OUTWARD,
            'cannotCancel' => 1,
        ], ['id' => $FleetID]);
        $db->updateRows('LOG_FLEETS', $route + [
            'start_time_formated' => $this->db->formatDate($times['start']),
            'arrival_time_formated' => $this->db->formatDate($times['arrival']),
            'stay_time_formated' => $this->db->formatDate($times['stay']),
            'return_time_formated' => $this->db->formatDate($times['end']),
            'status' => FLEET_OUTWARD,
            'cannotCancel' => 1,
        ], ['id' => $FleetID]);
        $sql = 'UPDATE %%FLEETS_EVENT%% SET `time` = :endTime WHERE `fleetID` = :fleetId;';
        $db->update($sql, [
            ':fleetId'  => $FleetID,
            ':endTime'  => $times['arrival'],
        ]);

        $sql = 'UPDATE %%TRADES%% SET `buyer_fleet_id` = :buyerFleetId,`buy_time` = NOW()
            WHERE `seller_fleet_id` = :fleetId;';
        $db->update($sql, [
            ':fleetId'  => $FleetID,
            ':buyerFleetId' => $buyerfleet,
        ]);
        $this->achievementGuard()->run(function () use ($fleetResult): void {
            $this->soldTo = $this->fleetAchievements()->onOfferBought($fleetResult, (int) $this->state->USER['id']);
        });
    }

    /**
     * Counts the sale for the seller's Market maker achievement; call once after the purchase transaction committed
     * (the counter update locks the seller's users row, which must not happen inside the buyer's transaction).
     */
    public function afterCommit(): void
    {
        $sellerId = $this->soldTo;
        $this->soldTo = null;
        if ($sellerId !== null) {
            $this->achievementGuard()->run(fn() => $this->fleetAchievements()->countSale($sellerId));
        }
    }

    /**
     * Sends the "bought" system message to the buyer and the "sold" message (in the seller's language) to the seller.
     *
     * @param array<string, mixed> $fleetResult   offer row
     * @param array<int, int|float> $fleetResource resources the buyer pays
     * @param array<string, mixed> $USER_2        seller (users + settings row)
     */
    private function notify(array $fleetResult, bool $isFleetTrade, int $marketplace, array $fleetResource, mixed $consumption, array $USER_2): void
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $sql = "SELECT `marketplace_galaxy`, `marketplace_system` FROM %%TRADES%% 
            WHERE `seller_fleet_id` = :fleetId;";
        $target = $this->db->selectSingle($sql, [
            ':fleetId' => $fleetResult['id'],
        ]);
        // To customer
        $Message = $LNG->format(
            'market_msg_trade_bought',
            sprintf(
                '%s:%s:%s',
                $isFleetTrade ? $fleetResult['start_galaxy'] : $target['marketplace_galaxy'],
                $isFleetTrade ? $fleetResult['start_system'] : $target['marketplace_system'],
                $isFleetTrade ? $fleetResult['start_planet'] : $marketplace,
            ),
            $fleetResource[RESOURCE_METAL],
            $LNG['tech'][RESOURCE_METAL],
            $fleetResource[RESOURCE_CRYSTAL],
            $LNG['tech'][RESOURCE_CRYSTAL],
            $fleetResource[RESOURCE_DEUT],
            $LNG['tech'][RESOURCE_DEUT],
            $consumption,
            $LNG['tech'][RESOURCE_DEUT],
        );
        $this->playerMessenger()->sendMessage(
            $USER['id'],
            0,
            $LNG['market_msg_trade_from'],
            MessageType::System->value,
            $LNG['market_msg_trade_topic'],
            $Message,
            $this->state->runtime->timestamp,
            1,
            $fleetResult['universe'],
        );

        // To salesmen
        $LNG_SALESMEN = $this->playerLookup()->getLanguage($USER_2['lang']);
        $Message = $LNG_SALESMEN->format(
            'market_msg_trade_sold',
            $PLANET['galaxy'] . ":" . $PLANET['system'] . ":" . ($isFleetTrade ? $marketplace : $PLANET['planet']),
            $fleetResult['resource_metal'],
            $LNG_SALESMEN['tech'][RESOURCE_METAL],
            $fleetResult['resource_crystal'],
            $LNG_SALESMEN['tech'][RESOURCE_CRYSTAL],
            $fleetResult['resource_deuterium'],
            $LNG_SALESMEN['tech'][RESOURCE_DEUT],
        );
        $this->playerMessenger()->sendMessage(
            $fleetResult['owner'],
            0,
            $LNG_SALESMEN['market_msg_trade_from'],
            MessageType::System->value,
            $LNG_SALESMEN['market_msg_trade_topic'],
            $Message,
            $this->state->runtime->timestamp,
            1,
            $fleetResult['universe'],
        );
    }

    /**
     * Picks the cargo ships a buyer sends to pay $amount: the preferred cargo type first (up to what the planet holds),
     * the other type for the rest.
     *
     * @param array{ex_resource_amount: int|float} $fleetResult amount to carry
     * @param mixed $shipType 1 = prefer small cargo, anything else = prefer large cargo
     * @return array<int, int|float>|false ship id => count, false when the planet's cargo ships cannot carry it
     */
    private function calculateFleetSize(array $fleetResult, mixed $shipType): array|false
    {
        $PLANET = $this->state->PLANET;
        $elements = $this->elements();
        // sorry world.
        $amount = $fleetResult['ex_resource_amount'];
        // cargo room per ship from the fleet rules (FleetMath::getFleetRoom), the same figure a real fleet of these ships carries
        $capacity = fn(int $shipId): int => $this->fleetFunctions()->getFleetRoom([$shipId => 1]);

        $F1capacity = 0;
        $F1type = 0;
        if ($shipType == 1) {
            // PRIO for LC
            $F1capacity = $capacity(SHIP_SMALL_CARGO);
            $F1type = SHIP_SMALL_CARGO;
        } else {
            // PRIO for HC
            $F1capacity = $capacity(SHIP_LARGE_CARGO);
            $F1type = SHIP_LARGE_CARGO;
        }

        $F1 = min($PLANET[$elements->column($F1type)], ceil($amount / $F1capacity));

        //taken
        $amountTMP = $amount - $F1 * $F1capacity;
        // If still fleet needed
        $F2 = 0;
        $F2capacity = 0;
        $F2type = 0;
        if ($amountTMP > 0) {
            if ($shipType == 1) {
                // We need HC
                $F2capacity = $capacity(SHIP_LARGE_CARGO);
                $F2type = SHIP_LARGE_CARGO;
            } else {
                // We need LC
                $F2capacity = $capacity(SHIP_SMALL_CARGO);
                $F2type = SHIP_SMALL_CARGO;
            }
            $F2 = min($PLANET[$elements->column($F2type)], ceil($amountTMP / $F2capacity));
            $amountTMP -= $F2 * $F2capacity;
        }

        if ($amountTMP > 0) {
            return false;
        }

        return [$F1type => $F1, $F2type => $F2];
    }
}
