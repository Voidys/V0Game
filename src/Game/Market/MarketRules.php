<?php

namespace App\Game\Market;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\MarketManager;
use App\Game\Core\Singleton;

/**
 * Buy-eligibility rules of the marketplace shared by the offer list and the purchase: fleet slots, vacation mode,
 * diplomacy filter of an offer, automatic push prevention and the display form of the offered resource.
 *
 * Collaborators: PlayerLookup (block/buddy status), FleetFunctions (slots), MarketManager (value comparison).
 * The "buy" results are arrays `['buyable' => bool, 'reason' => string]` (reason is a translated text for the player).
 */
class MarketRules
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Checks that the current player has a free fleet slot.
     *
     * @param array<string, mixed> $USER the player row
     * @return array{result: int, message?: string} result -1 and a translated message when no slot is free, else 0
     */
    public function checkSlots(array $USER): array
    {
        $LNG = $this->state->LNG;
        $ActualFleets = $this->fleetFunctions()->getCurrentFleets($USER['id']);
        if ($this->fleetFunctions()->getMaxFleetSlots($USER) <= $ActualFleets) {
            return ['result' => -1, 'message' => $LNG['fl_no_slots']];
        }
        return ['result' => 0];
    }

    /**
     * Players in vacation mode cannot buy.
     *
     * @return array{buyable: bool, reason: string}
     */
    public function checkVmode(): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if ($USER['urlaubs_modus'] == 1) {
            return [
                'buyable' => false,
                'reason' => $LNG['fa_vacation_mode_current'],
            ];
        }
        return [
            'buyable' => true,
            'reason' => '',
        ];
    }

    /**
     * Applies the diplomacy filter of an offer for the current player: trade blocks in either direction and
     * level-5 (enemy) pacts always forbid, "partners only" offers (visibility 1) need a buddy, the same alliance or a
     * pact of level 0-3.
     *
     * @param int|string $visibility  filter_visibility of the offer (0 = everyone, 1 = partners only, 2 = not enemies)
     * @param int|null   $level       diplomacy level between the two alliances, null without an accepted pact
     * @param int        $seller_ally alliance id of the seller
     * @param int        $ally        alliance id of the buyer
     * @param int        $receiverID  user id of the seller
     * @return array{buyable: bool, reason: string}
     */
    public function checkDiplo($visibility, $level, $seller_ally, $ally, $receiverID): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $blocked = $this->playerLookup()->get_block_status($USER['id'], $receiverID);
        $blockedInverse = $this->playerLookup()->get_block_status($receiverID, $USER['id']);
        $buddy = $this->playerLookup()->get_buddy_status($USER['id'], $receiverID);
        if (($visibility == 2 && $level == 5) || $blocked['block_trade'] || $blockedInverse['block_trade']) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_no_enemies'],
            ];
        }

        // players without an alliance (id 0) are not in one alliance together
        $sameAlliance = (int) $ally > 0 && $ally == $seller_ally;
        if ($visibility == 1 && !$buddy && !$sameAlliance && ($level == null || ($level > 3 && $level != 6))) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_only_trade_partners'],
            ];
        }

        return [
            'buyable' => true,
            'reason' => '',
        ];
    }

    /**
     * Automatic push prevention: is the offer a push (too much value for too little in return) between the two players?
     * An offer with an invalid wanted resource counts as a push, so it is never buyable and cannot break the page.
     *
     * @param array<string, mixed> $fleetsRow offer row (fleet joined with trade)
     * @param int|string           $buyer     user id
     * @param int|string           $seller    user id
     */
    public function checkPush(array $fleetsRow, $buyer, $seller, ?MarketManager $pMarket = null): bool
    {
        $pMarket = $pMarket ?? new MarketManager($this->db, $this->configs, $this->state);
        $offer = 0;
        $fleetMarket = $fleetsRow['transaction_type'] == 1;
        $askType = (int) $fleetsRow['ex_resource_type'];
        if ($askType < 1 || $askType > 3) {
            return true;
        }
        $ask = $pMarket->convertExpResTypeToDeut($askType, $fleetsRow['ex_resource_amount']);

        if ($fleetMarket) {
            $fleet = $this->fleetFunctions()->unserialize($fleetsRow['ships']);
            return $pMarket->isFleetPush($fleet, $ask, $seller, $buyer);
        } else {
            $offerArray = [
                'metal' => $fleetsRow['resource_metal'],
                'crystal' => $fleetsRow['resource_crystal'],
                'deuterium' => $fleetsRow['resource_deuterium'],
            ];

            $offer = $pMarket->convertToDeut($offerArray);
            return $pMarket->isPush($offer, $ask, $seller, $buyer);
        }
    }

    /**
     * Determines which resource an offer shows: the stored type (1-3), else the first non-empty resource of the fleet.
     *
     * @param int|string           $offerType      stored resource_type
     * @param mixed                $offerAmount    stored resource_amount
     * @param array<int, string>   $resourceNames  type (1-3) => translated name
     * @return array{id: int, amount: mixed, name: string}
     */
    public function resolveOfferedResource($offerType, $offerAmount, $metal, $crystal, $deuterium, array $resourceNames): array
    {
        if ($offerType < 1 || $offerType > 3) {
            if ($metal > 0) {
                $offerType = 1;
                $offerAmount = $metal;
            } elseif ($crystal > 0) {
                $offerType = 2;
                $offerAmount = $crystal;
            } elseif ($deuterium > 0) {
                $offerType = 3;
                $offerAmount = $deuterium;
            }
        }

        return [
            'id' => (int) $offerType,
            'amount' => $offerAmount,
            'name' => $resourceNames[$offerType] ?? ' ',
        ];
    }
}
