<?php

/**
 *  Steemnova
 *   by Adam "dotevo" Jordanek 2018
 *
 * For the full copyright and license information, please view the LICENSE
 *
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\Core\MarketManager;
use App\Game\Market\MarketOfferListing;
use App\Game\Market\MarketPurchase;
use App\Game\Market\MarketRules;
use App\Game\Market\MarketTradeHistory;

/** Market place: player trade offers for resources and fleets. */
#[\App\Controller\RequireModule('MODULE_MISSION_TRADE')]
class MarketPlaceController extends AbstractGameController
{
    /**
     * Marketplace page: lists the open offers, the reference ratios and the trade histories.
     */
    #[GameRoute('game', 'game/market', 'marketPlace')]
    public function show(): Response
    {
        return $this->marketPage('');
    }

    /**
     * Buys an offer (inside a transaction; request: fleetID, shipType), then shows the marketplace page with the result message.
     */
    #[GameRoute('game', 'game/market/buy', 'marketPlace', 'buy', ['GET', 'POST'])]
    public function buy(): Response
    {
        if ($redirect = $this->redirectUnlessPost('game_market_place_show')) {
            return $redirect;
        }

        $LNG = $this->state->LNG;
        $db = $this->db;
        $rules = new MarketRules($this->db, $this->configs, $this->state);

        // MarketPurchase::buy() reduces $PLANET in memory; restore it when the purchase is rolled back
        $planetBackup = $this->state->PLANET;
        // Lock the offer row for the whole purchase, so a concurrent buyer
        // waits and then no longer finds it with fleet_mess = 2.
        $db->startTransaction();
        try {
            $purchase = new MarketPurchase($this->db, $this->configs, $this->state, $rules);
            $message = $purchase->buy($this->input()->int('fleetID'), $this->input()->string('shipType'));
            $db->commit();
            $purchase->afterCommit();
        } catch (\Throwable $e) {
            $db->rollback();
            $this->state->PLANET = $planetBackup;
            // Deadlock/contention on FOR UPDATE must not hit the generic
            // "dev was notified" error page (see PR review on #1774).
            $message = $LNG['market_p_msg_purchase_failed'];
        }

        return $this->marketPage($message);
    }

    /**
     * The marketplace page with the open offers, ratios and trade histories.
     */
    private function marketPage(string $message): Response
    {
        $rules = new MarketRules($this->db, $this->configs, $this->state);

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $FlyingFleetList = (new MarketOfferListing($this->db, $this->configs, $this->state, $rules))->offers($pMarket);
        $history = new MarketTradeHistory($this->db, $this->configs, $this->state, $rules);

        $deutRelative = $pMarket->getDeutRelativeRatios();
        $refrates = $deutRelative;
        $ratioBaseline = 'deuterium';
        // $refrates = $pMarket->getReferenceRatios();
        // $ratioBaseline = array_search(min($deutRelative), $deutRelative, true);

        $this->addScript('marketplace.js');

        return $this->render('game/page.marketPlace.default.html.twig', [
            'message'               => $message,
            'FlyingFleetList'       => $FlyingFleetList,
            'resourceHistory'       => $history->resourceTrades(),
            'fleetHistory'          => $history->fleetTrades(),
            'ratio_metal'           => $this->viewHelpers()->prettyNumber($refrates['metal'], MarketManager::REFERENCE_DECIMALS),
            'ratio_crystal'         => $this->viewHelpers()->prettyNumber($refrates['crystal'], MarketManager::REFERENCE_DECIMALS),
            'ratio_deuterium'       => $this->viewHelpers()->prettyNumber($refrates['deuterium'], MarketManager::REFERENCE_DECIMALS),
            'ratio_base_metal'      => sprintf('%.12F', $deutRelative['metal']),
            'ratio_base_crystal'    => sprintf('%.12F', $deutRelative['crystal']),
            'ratio_base_deuterium'  => sprintf('%.12F', $deutRelative['deuterium']),
            'ratio_baseline'        => $ratioBaseline,
        ]);
    }
}
