<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Alliance\AllianceRepository;
use App\Game\Engagement\AllianceGoals\AllianceGoals;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setting the weekly alliance goal (optional engagement feature, universe setting alliance_goals_enabled). The progress bar
 * itself is part of the alliance page (template game/engagement/_alliance_goal).
 */
class AllianceGoalController extends AbstractGameController
{
    /**
     * POST kind, target: the leader or a member with the ADMIN right sets (or, while it has no progress, changes) the goal of
     * the week. Answers with a message page.
     */
    #[GameRoute('game', 'game/alliance-goal', 'alliance_goal', 'set', ['POST'])]
    public function set(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $universe = (int) $USER['universe'];
        $goals = new AllianceGoals($this->db, $this->configs, $this->state);
        $back = [['url' => $this->router->generate('game_alliance_show'), 'label' => $LNG['lm_alliance']]];
        if (!$goals->enabled($universe)) {
            return $this->messageResponse($LNG['sys_module_inactive'], $back);
        }
        $repository = new AllianceRepository($this->db, $this->configs, $this->state);
        $alliance = (int) $USER['ally_id'] > 0 ? $repository->find((int) $USER['ally_id']) : null;
        if ($alliance === null || empty($repository->rightsOf($USER, $alliance)['ADMIN'])) {
            return $this->messageResponse($LNG['eng_ag_err_rights'], $back);
        }
        $error = $goals->setGoal($universe, $alliance, (int) $USER['id'], $this->input()->string('kind'), $this->input()->int('target'), $this->state->runtime->timestamp);

        return $this->messageResponse($error === null ? $LNG['eng_ag_saved'] : $LNG[$error], $back);
    }
}
