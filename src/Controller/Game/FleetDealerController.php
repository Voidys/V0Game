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

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;

/** Ship dealer: sells ships of the current planet for resources (modes: show, send). */
#[\App\Controller\RequireModule('MODULE_FLEET_TRADER')]
class FleetDealerController extends AbstractGameController
{
    /**
     * Sells ships (request: shipID, count) of the current planet: only ships listed in `trade_allowed_ships`, not in vacation mode, with the
     * planet row locked. Pays the ship cost minus the `trade_charge` percent in resources and removes the ships.
     */
    #[GameRoute('game', 'game/fleet-dealer', 'fleetDealer', 'send', ['POST'])]
    public function send(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $shipID = $this->input()->int('shipID');
        $Count = max(0, round($this->input()->float('count')));
        $allowedShipIDs = explode(',', $this->configs->get()->trade_allowed_ships);

        if ($USER['urlaubs_modus'] == 1) {
            return $this->backToDealer($LNG['fl_vacation_mode_active']);
        }

        if (
            empty($shipID)
            || empty($Count)
            || !in_array($shipID, $allowedShipIDs)
        ) {
            return $this->backToDealer($LNG['tr_exchange_error']);
        }

        $shipColumn = $e->column($shipID);
        $db = $this->db;
        $db->startTransaction();
        $sql = 'SELECT * FROM %%PLANETS%% WHERE id = :planetID FOR UPDATE;';
        $lockedPlanet = $db->selectSingle($sql, [
            ':planetID' => $this->state->PLANET['id'],
        ]);

        if (empty($lockedPlanet) || $lockedPlanet[$shipColumn] < $Count) {
            $db->rollback();

            return $this->backToDealer($LNG['tr_exchange_error']);
        }

        $tradeCharge = 1 - (min(max($this->tradeFee(), 0), 90) / 100);
        $resources = [
            RESOURCE_METAL => $Count * $e->cost($shipID)->metal * $tradeCharge,
            RESOURCE_CRYSTAL => $Count * $e->cost($shipID)->crystal * $tradeCharge,
            RESOURCE_DEUT => $Count * $e->cost($shipID)->deuterium * $tradeCharge,
        ];

        // Debit ships first with a stock guard so a parallel sell cannot
        // credit resources twice for the same units.
        $sql = 'UPDATE %%PLANETS%% SET ' . $shipColumn . ' = ' . $shipColumn . ' - :count'
            . ' WHERE id = :planetID AND ' . $shipColumn . ' >= :count;';
        $db->update($sql, [
            ':count'    => $Count,
            ':planetID' => $this->state->PLANET['id'],
        ]);

        if ($db->rowCount() !== 1) {
            $db->rollback();

            return $this->backToDealer($LNG['tr_exchange_error']);
        }

        $this->ecoObj->addResources($this->state->PLANET['id'], $resources, $this->state->PLANET);
        $this->state->PLANET[$shipColumn] = $lockedPlanet[$shipColumn] - $Count;
        $db->commit();

        return $this->backToDealer($LNG['tr_exchange_done'], 'sys_forward');
    }

    /**
     * Ends the request with $message and a link back to the dealer page (label sys_back, or $labelKey for the success message).
     */
    private function backToDealer(mixed $message, string $labelKey = 'sys_back'): Response
    {
        return $this->messageResponse($message, [
            [
                'label' => $this->state->LNG[$labelKey],
                'url'   => $this->router->generate('game_fleet_dealer_show'),
            ],
        ]);
    }

    /**
     * Ship dealer form with the tradeable ships and the charge.
     */
    #[GameRoute('game', 'game/fleet-dealer', 'fleetDealer')]
    public function show(): Response
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $Cost = [];

        $allowedShipIDs = explode(',', $this->configs->get()->trade_allowed_ships);

        foreach ($allowedShipIDs as $shipID) {
            if (in_array($shipID, $e->ids('fleet')) || in_array($shipID, $e->ids('defense'))) {
                $Cost[$shipID]  = [$PLANET[$e->column((int) $shipID)], $LNG['tech'][$shipID], $e->cost((int) $shipID)->toArray()];
            }
        }

        if (empty($Cost)) {
            return $this->messageResponse($LNG['ft_empty'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => $this->router->generate('game_fleet_dealer_show'),
                ],
            ]);
        }

        $shipImg = [];
        foreach ($allowedShipIDs as $shipID) {
            $shipImg[$shipID] = $this->state->THEME->asset('gebaeude/' . $shipID . '.gif');
        }

        return $this->render('game/page.fleetDealer.default.html.twig', [
            'shipIDs'   => $allowedShipIDs,
            'shipImg'   => $shipImg,
            'CostInfos' => $Cost,
            'Charge'    => round($this->tradeFee(), 2),
        ]);
    }

    /** Merchant fee in percent: the universe's trade_charge times the trader event factor (cheaper during a trade fair). */
    private function tradeFee(): float
    {
        $config = $this->configs->get();

        return (float) $config->trade_charge
            * $this->eventModifiers()->factor((int) $config->uni, \App\Game\Engagement\Events\EventModifiers::TRADER_COST);
    }
}
