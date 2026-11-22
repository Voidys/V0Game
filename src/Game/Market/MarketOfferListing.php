<?php

namespace App\Game\Market;

use App\Game\Enum\Mission;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\MarketManager;
use App\Game\Core\Singleton;

/**
 * Builds the list of open marketplace offers (fleets holding at the market without a buyer yet) as shown to the
 * current player: offered/wanted resources, trip durations for the player's cargo ships, buyability and value ratio.
 * Read only.
 */
class MarketOfferListing
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private ?MarketRules $rules = null,
    ) {
        $this->rules ??= new MarketRules($db, $configs, $state);
    }

    /**
     * Loads the open offers of the current universe (oldest stay time first) with the diplomacy level between the
     * seller's and the player's alliance.
     *
     * @return list<array<string, mixed>> offer rows (users, trades, pact and fleet columns joined)
     */
    private function loadOffers(): array
    {
        $USER = $this->state->USER;
        $sql = 'SELECT u.*, t.*, packts.*, f.*
			FROM %%FLEETS%% f
			JOIN %%USERS%% u ON f.`owner` = u.`id`
            JOIN %%TRADES%% t ON f.`id` = t.`seller_fleet_id`
			LEFT JOIN (
				SELECT `owner_2` as al ,`level`, `accept` FROM %%DIPLO%% WHERE `owner_1` = :al
				UNION
				SELECT `owner_1` as al,`level`, `accept` FROM %%DIPLO%% WHERE `owner_2` = :al) as packts
			ON al = u.`ally_id`
			WHERE f.`mission` = :trade
			AND f.`universe` = :universe
            AND f.`status` = :fleetState
            AND t.`buyer_fleet_id` IS NULL
            AND f.`stay_time` > :now
            ORDER BY f.`stay_time` ASC;';
        return $this->db->select($sql, [
            ':al'           => $USER['ally_id'],
            ':trade'        => Mission::Trade->value,
            ':buyerid'      => $USER['id'],
            ':universe'     => $this->universe()->current(),
            ':fleetState'   => FLEET_HOLD,
            ':now'          => $this->state->runtime->timestamp,
        ]);
    }

    /**
     * The offers for the template (`FlyingFleetList`).
     *
     * @return list<array<string, mixed>>
     */
    public function offers(?MarketManager $pMarket = null): array
    {
        $pMarket ??= new MarketManager($this->db, $this->configs, $this->state);
        $list = [];
        foreach ($this->loadOffers() as $fleetsRow) {
            $list[] = $this->describeOffer($fleetsRow, $pMarket);
        }

        return $list;
    }

    /**
     * Trip durations (market to the player's planet) for an offer: the sold fleet itself (`from`), and one small and one
     * large cargo ship of the player (what the buyer has to send).
     *
     * @param array<string, mixed> $fleetsRow offer row
     * @param array<int, int>      $FROM_fleet ship id => amount of the offered fleet
     * @return array{from: int|float, lc: int|float, hc: int|float}
     */
    private function tripDurations(array $fleetsRow, array $FROM_fleet): array
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $marketplace = ($this->configs->get($USER['universe'])->max_planets + 2);
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        //FROM
        $FROM_Distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$fleetsRow['end_galaxy'], $fleetsRow['end_system'], $marketplace],
        );
        $FROM_SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($FROM_fleet, $fleetsRow);
        $FROM_Duration = $this->fleetFunctions()->getMissionDuration(
            10,
            $FROM_SpeedAllMin,
            $FROM_Distance,
            $SpeedFactor,
            $fleetsRow,
        );
        $sql = "SELECT `marketplace_galaxy`, `marketplace_system` 
                FROM %%TRADES%% WHERE `seller_fleet_id` = :fleetId;";
        $target = $this->db->selectSingle($sql, [
            ':fleetId' => $fleetsRow['id'],
        ]);
        //TO
        $TO_Distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$target['marketplace_galaxy'], $target['marketplace_system'], $marketplace],
        );
        $TO_LC_SPEED = $this->fleetFunctions()->getFleetMaxSpeed([202 => 1], $USER);
        $TO_LC_DUR = $this->fleetFunctions()->getMissionDuration(10, $TO_LC_SPEED, $TO_Distance, $SpeedFactor, $USER);
        $TO_HC_SPEED = $this->fleetFunctions()->getFleetMaxSpeed([203 => 1], $USER);
        $TO_HC_DUR = $this->fleetFunctions()->getMissionDuration(10, $TO_HC_SPEED, $TO_Distance, $SpeedFactor, $USER);

        return ['from' => $FROM_Duration, 'lc' => $TO_LC_DUR, 'hc' => $TO_HC_DUR];
    }

    /**
     * Whether the current player can buy the offer, and if not why (own offer, push prevention, the seller's flight time limit, diplomacy filter).
     *
     * @param array<string, mixed> $fleetsRow offer row
     * @return array{buyable: bool, reason: string}
     */
    private function buyability(array $fleetsRow, MarketManager $pMarket, int|float $sellerDuration): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $buyer = $USER['id'];
        $seller = $fleetsRow['owner'];

        if ($buyer == $seller) {
            return [
                'buyable' => false,
                'reason' => 'N/A', // todo $LNG['text'] - creative input needed
            ];
        }
        if ($this->rules->checkPush($fleetsRow, $buyer, $seller, $pMarket)) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_no_tech'],
            ];
        }
        //Level 5 - enemies
        //Level 0 - 3 alliance
        $limit = (int) $fleetsRow['filter_flighttime'];
        if ($limit > 0 && $sellerDuration > $limit) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_too_far'],
            ];
        }
        return $this->rules->checkDiplo(
            $fleetsRow['filter_visibility'],
            $fleetsRow['level'],
            $fleetsRow['ally_id'],
            $USER['ally_id'],
            $seller,
        );
    }

    /**
     * Template row of one offer.
     *
     * @param array<string, mixed> $fleetsRow offer row
     * @return array<string, mixed>
     */
    private function describeOffer(array $fleetsRow, MarketManager $pMarket): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $resourceNames = [
            1 => $LNG['tech'][RESOURCE_METAL],
            2 => $LNG['tech'][RESOURCE_CRYSTAL],
            3 => $LNG['tech'][RESOURCE_DEUT],
        ];
        $resourceN = $resourceNames[$fleetsRow['ex_resource_type']] ?? ' ';
        $offer = $this->rules->resolveOfferedResource(
            (int) $fleetsRow['resource_type'],
            $fleetsRow['resource_amount'],
            $fleetsRow['resource_metal'],
            $fleetsRow['resource_crystal'],
            $fleetsRow['resource_deuterium'],
            $resourceNames,
        );
        $offerType = $offer['id'];
        $offerAmount = $offer['amount'];
        $offerName = $offer['name'];

        //Level of diplo
        if ($fleetsRow['accept'] == 0) {
            $fleetsRow['level'] = null;
        }
        $FROM_fleet =  $this->fleetFunctions()->unserialize($fleetsRow['ships']);
        $durations = $this->tripDurations($fleetsRow, $FROM_fleet);

        $fleet_str = '';
        foreach ($FROM_fleet as $name => $amount) {
            $fleet_str .= $LNG['shortNames'][$name] . ' x' . $amount . "\n";
        }

        $buy = $this->buyability($fleetsRow, $pMarket, $durations['from']);

        $fleetValue = $pMarket->getFleetValue($FROM_fleet);
        $askType = (int) $fleetsRow['ex_resource_type'];
        $ask = ($askType >= 1 && $askType <= 3)
            ? $pMarket->convertExpResTypeToDeut($askType, $fleetsRow['ex_resource_amount'])
            : 0;
        if ($fleetsRow['transaction_type'] == 1) {
            $tradeDeut = $fleetValue;
        } elseif ($offerType >= 1 && $offerType <= 3) {
            $tradeDeut = $pMarket->convertExpResTypeToDeut($offerType, (float) $offerAmount);
        } else {
            $tradeDeut = 0;
        }
        $tradeRatio = $ask > 0 ? $tradeDeut / $ask : 0;
        $fleetRatio = $this->viewHelpers()->prettyNumber($tradeRatio, MarketManager::REFERENCE_DECIMALS);

        return [
            'id'                        => $fleetsRow['id'],
            'type'                      => $fleetsRow['transaction_type'],
            'resource_metal'            => $fleetsRow['resource_metal'],
            'resource_crystal'          => $fleetsRow['resource_crystal'],
            'resource_deuterium'        => $fleetsRow['resource_deuterium'],
            'fleet'                     => $fleet_str,
            'diplo'                     => $fleetsRow['level'],
            'from_alliance'             => $fleetsRow['ally_id'] == $USER['ally_id'],
            'possible_to_buy'           => $buy['buyable'],
            'reason'                    => $buy['reason'],
            'offer_resource'            => $offerName,
            'offer_resource_id'         => $offerType,
            'offer_resource_amount'     => $offerAmount,
            'wanted_resource'           => $resourceN,
            'wanted_resource_id'        => $fleetsRow['ex_resource_type'],
            'wanted_resource_amount'    => $fleetsRow['ex_resource_amount'],
            'end'                       => $fleetsRow['stay_time'] - $this->state->runtime->timestamp,
            'from_duration'             => $durations['from'],
            'to_lc_duration'            => $durations['lc'],
            'to_hc_duration'            => $durations['hc'],
            'offer_deut'                => $tradeDeut,
            'ask_deut'                  => $ask,
            'fleet_ratio'               => $fleetRatio,
        ];
    }
}
