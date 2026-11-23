<?php

namespace App\Game\Fleet;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Enum\Mission;
use App\Security\FleetDailyCaps;

/**
 * Sends one planned collection transport through the regular fleet dispatch (FleetDispatch): the same form token and
 * request fields a player's step 2 form would produce are prepared for the source planet, so every rule (slots, fuel,
 * cargo, protection, daily caps, vacation) is checked exactly as for a hand-sent fleet. A refusal becomes a result entry.
 */
class ResourceCollectionDispatcher
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly ?FleetDailyCaps $dailyCaps = null,
    ) {}

    /**
     * Sends the transport from $source to $target.
     *
     * @param array<string, mixed> $source planet row of the start planet
     * @param array<string, mixed> $target planet row of the destination
     * @param array{ships: array<int, int>, cargo: array<int, int>, capacity: int} $plan from ResourceCollector::plan()
     * @return array{ok: bool, message: string, arrival: string} outcome; arrival = formatted arrival time when sent
     */
    public function send(array $source, array $target, array $plan): array
    {
        $saved = [$this->state->PLANET, $this->state->requestInput, $this->state->requestPost];
        try {
            $this->prepare($source, $target, $plan);
            $variables = (new FleetDispatch($this->db, $this->configs, $this->state, $this->dailyCaps))->dispatch();

            return ['ok' => true, 'message' => '', 'arrival' => (string) $variables['fleetStartTime']];
        } catch (FleetDispatchRefused $refused) {
            $message = $refused->getMessage() !== '' ? $refused->getMessage() : $this->state->LNG['fl_invalid_mission'];

            return ['ok' => false, 'message' => (string) $message, 'arrival' => ''];
        } finally {
            [$this->state->PLANET, $this->state->requestInput, $this->state->requestPost] = $saved;
        }
    }

    /**
     * Stores the prepared fleet (what steps 1 and 2 keep in the session) under a fresh token and points the request
     * fields of the dispatch at it.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @param array<string, mixed> $plan
     */
    private function prepare(array $source, array $target, array $plan): void
    {
        $token = getRandomString();
        $session = $this->sessions()->load();
        $fleet = is_array($session->fleet) ? $session->fleet : [];
        $fleet[$token] = [
            'ownPlanet' => $source['id'],
            'time' => $this->state->runtime->timestamp,
            'fleet' => $plan['ships'],
            'fleetRoom' => $plan['capacity'],
            'predefinedResources' => ['met' => '', 'krist' => '', 'deut' => ''],
            'distance' => $this->fleetFunctions()->getTargetDistance(
                [$source['galaxy'], $source['system'], $source['planet']],
                [$target['galaxy'], $target['system'], $target['planet']],
            ),
            'targetGalaxy' => (int) $target['galaxy'],
            'targetSystem' => (int) $target['system'],
            'targetPlanet' => (int) $target['planet'],
            'targetType' => (int) $target['planet_type'],
            'fleetGroup' => 0,
            'fleetSpeed' => 100,
        ];
        $session->fleet = $fleet;
        $session->save();

        $this->state->PLANET = $source;
        $this->state->requestPost = $this->state->requestInput = [
            'token' => $token,
            'mission' => Mission::Transport->value,
            'metal' => $plan['cargo'][901],
            'crystal' => $plan['cargo'][902],
            'deuterium' => $plan['cargo'][903],
        ];
    }
}
