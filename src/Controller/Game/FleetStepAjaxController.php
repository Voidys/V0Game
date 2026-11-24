<?php

/**
 * pr0game powered by steemnova
 * Fleets
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Fleet\FleetTemplateStore;
use App\Fleet\ShipPreset;
use App\Game\Core\Session;
use App\Game\Enum\Mission;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Fleet dispatch helper (XHR): drops the fleet prepared in the session when the dispatch pages are left, fleet templates and the fleet save suggestions. */
class FleetStepAjaxController extends AbstractGameController
{
    public $returnData = [];

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    /**
     * This function will delete the prepared fleet in session via its token.
     * Called by ajax in flotten.js on browser page change.
     * Function addBeforeUnload() adds the listener to the window object. It is called in FleetStep1 and 2 pages.
     * CheckTarget() and CheckResources() are called by forms of Sttep1 and 2 to proceed, which is also a page change.
     * To avoid deleting the fleet token, removeBeforeUnload() is called within them.
     * On failed checks addBeforeUnload() is called again.
     */
    #[GameRoute('game', 'game/fleet/step-ajax', 'fleetStepAjax', 'show', ['POST'])]
    public function show(): Response
    {
        // state-changing: POST only (the beacon is one) and thereby covered by the central CSRF check
        $token = $this->input()->string('token');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (isset($fleet[$token])) {
            unset($fleet[$token]);
            $session->fleet = $fleet;
            $session->save();
        }

        return new Response();
    }

    /**
     * Saves the fleet prepared under `token` (step 1) as a template (request: token, name). At most FleetTemplateStore::MAX_PER_USER
     * per player. Answers JSON {ok, templates} or {ok: false, error}.
     */
    #[GameRoute('game', 'game/fleet/step-ajax/template-save', 'fleetStepAjax', 'templateSave', ['POST'])]
    public function templateSave(): Response
    {
        $LNG = $this->state->LNG;
        $store = new FleetTemplateStore($this->db);
        $userId = (int) $this->state->USER['id'];
        $fleet = $this->sessions()->load()->fleet[$this->input()->string('token')]['fleet'] ?? null;
        $name = FleetTemplateStore::cleanName($this->input()->rawString('name'));
        $ships = is_array($fleet) ? ShipPreset::parse(ShipPreset::format($fleet), ShipPreset::flyable($this->elements()->ids('fleet'))) : null;
        if ($name === null || $ships === null) {
            return $this->jsonResponse(['ok' => false, 'error' => $LNG['fl_tpl_invalid']]);
        }
        if ($store->create($userId, $name, ShipPreset::format($ships), $this->state->runtime->timestamp) === 0) {
            return $this->jsonResponse(['ok' => false, 'error' => $LNG->format('fl_tpl_limit', FleetTemplateStore::MAX_PER_USER)]);
        }

        return $this->jsonResponse(['ok' => true, 'templates' => $store->all($userId)]);
    }

    /** Deletes one of the player's fleet templates (request: id). Answers JSON {ok, templates}. */
    #[GameRoute('game', 'game/fleet/step-ajax/template-delete', 'fleetStepAjax', 'templateDelete', ['POST'])]
    public function templateDelete(): Response
    {
        $store = new FleetTemplateStore($this->db);
        $userId = (int) $this->state->USER['id'];
        $store->delete($userId, $this->input()->int('id'));

        return $this->jsonResponse(['ok' => true, 'templates' => $store->all($userId)]);
    }

    /**
     * Fleet save suggestions for the fleet prepared under `token` (request: token): the player's other planets and moons
     * the fleet can reach on its own deuterium at the slowest speed (10 %), longest flight first (moons before planets on
     * equal flight time), at most three. Each choice carries the target, mission (deploy), speed step, flight time in
     * seconds and fuel. Answers JSON {choices: [...]}.
     */
    #[GameRoute('game', 'game/fleet/step-ajax/fleet-save', 'fleetStepAjax', 'fleetSave', ['GET'])]
    public function fleetSave(): Response
    {
        $fleet = $this->sessions()->load()->fleet[$this->input()->string('token')]['fleet'] ?? null;
        if (!is_array($fleet) || $fleet === []) {
            return $this->jsonResponse(['choices' => []]);
        }
        $PLANET = $this->state->PLANET;
        $userModel = $this->models()->user($this->state->USER);
        $functions = $this->fleetFunctions();
        $gameSpeed = $functions->getGameSpeedFactor();
        $maxSpeed = $functions->fleetMaxSpeedFor($fleet, $userModel);
        $choices = [];
        foreach ($this->state->USER['PLANETS'] ?? [] as $target) {
            if ($target['id'] == $PLANET['id']) {
                continue;
            }
            $distance = $functions->getTargetDistance([$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']], [$target['galaxy'], $target['system'], $target['planet']]);
            $duration = $functions->missionDurationFor(1, $maxSpeed, $distance, $gameSpeed, $userModel);
            $fuel = $functions->fleetConsumptionFor($fleet, $duration, $distance, $userModel, $gameSpeed, startPlanetId: (int) $this->state->PLANET['id']);
            if ($fuel > $PLANET['deuterium']) {
                continue;
            }
            $choices[] = [
                'name' => (string) $target['name'], 'galaxy' => (int) $target['galaxy'], 'system' => (int) $target['system'], 'planet' => (int) $target['planet'],
                'type' => (int) $target['planet_type'], 'mission' => Mission::Station->value, 'speed' => 1, 'duration' => $duration, 'fuel' => (int) round($fuel),
            ];
        }
        usort($choices, static fn(array $a, array $b): int => $b['duration'] <=> $a['duration'] ?: ($a['type'] === 3 ? 0 : 1) <=> ($b['type'] === 3 ? 0 : 1));

        return $this->jsonResponse(['choices' => array_slice($choices, 0, 3)]);
    }
}
