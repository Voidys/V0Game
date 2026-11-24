<?php

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Queues of all planets and moons on one page: research of the account, construction and shipyard per planet. */
#[\App\Controller\RequireModule('MODULE_IMPERIUM')]
class QueuesController extends AbstractGameController
{
    /**
     * Lists the running construction and shipyard queue of every planet and moon (countdown, further entries) plus the
     * account-wide research. Recalculates all planets first so the countdowns are current. Read-only.
     */
    #[GameRoute('game', 'game/queues', 'queues')]
    public function show(): Response
    {
        $overview = $this->queueOverview();
        $planets = $overview->recalculatePlanets();
        $shipyardColumn = $this->elements()->column(SHIPYARD);

        $rows = [];
        foreach ($planets as $planet) {
            $rows[] = [
                'id' => (int) $planet['id'],
                'name' => $planet['name'],
                'galaxy' => (int) $planet['galaxy'],
                'system' => (int) $planet['system'],
                'planet' => (int) $planet['planet'],
                'image' => $planet['image'],
                'style' => (int) $planet['planet_style'],
                'isMoon' => $planet['planet_type'] == \App\Game\Enum\PlanetType::Moon->value,
                'current' => (int) $planet['id'] === (int) $this->state->PLANET['id'],
                'building' => $overview->building($planet),
                'shipyard' => $overview->shipyard($planet),
                'hasShipyard' => ($planet[$shipyardColumn] ?? 0) > 0,
            ];
        }

        return $this->render('game/page.queues.default.html.twig', [
            'rows' => $rows,
            'research' => $overview->research(),
            'umode' => (int) $this->state->USER['urlaubs_modus'],
            'enablePlanetStyles' => $this->state->USER['enable_planet_styles'],
        ]);
    }
}
