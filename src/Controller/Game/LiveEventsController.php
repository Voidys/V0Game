<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Core\FlyingFleetsTable;
use App\Game\Core\LiveEventsPayload;
use App\Routing\GameRoute;
use App\Routing\LeanBoot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON feed of the fleet events of the overview. Split from the overview so that a poll does not lock and update the
 * planet (no resource update, like the chat poll).
 */
class LiveEventsController extends AbstractGameController
{
    /** polls are read only: no resource update of the current planet */
    protected $disableEcoSystem = true;

    /**
     * JSON feed of the fleet events of the overview (same table as the page): events with plain-text line, mission,
     * own/hostile flags, origin and target, arrival timestamp and remaining seconds, the hostile count and a hash of
     * the list. Polled by public/scripts/game/live-events.js; read only, so no token and no rate limit.
     */
    #[GameRoute('game', 'game/events', 'events')]
    #[LeanBoot(LeanBoot::PLANET, LeanBoot::DATA)]
    public function events(): Response
    {
        $table = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $table->setUser($this->state->USER['id']);
        // the feed does not depend on the current planet (only the phalanx does): lean boot loads none
        $table->setPlanet((int) ($this->state->PLANET['id'] ?? 0));
        $payload = LiveEventsPayload::build($table->renderTable());
        $payload['now'] = $this->state->runtime->timestamp;

        return new JsonResponse($payload, 200, ['Cache-Control' => 'no-store']);
    }
}
