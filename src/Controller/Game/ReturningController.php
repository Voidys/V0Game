<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Welcome summary of a returning player (optional engagement feature, universe setting returning_enabled): dismissing it.
 * The summary itself is part of the overview (template game/engagement/_overview).
 */
class ReturningController extends AbstractGameController
{
    /**
     * POST: marks the welcome summary of the player as seen, back to the overview.
     */
    #[GameRoute('game', 'game/returning/dismiss', 'returning', 'dismiss', ['POST'])]
    public function dismiss(): Response
    {
        $this->returningPlayers()->dismiss((int) $this->state->USER['id']);

        return $this->redirectToRoute('game_overview_show');
    }
}
