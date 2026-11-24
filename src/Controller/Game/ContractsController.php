<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Engagement\Contracts\ContractBoard;
use App\Game\Engagement\Contracts\Contracts;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weekly contracts (optional engagement feature, universe setting contracts_enabled): the player's goals of the ISO week with
 * progress bars, the claim button of finished contracts and the weekly reroll.
 */
class ContractsController extends AbstractGameController
{
    private const NOTICES = ['claimed', 'claim_failed', 'rerolled', 'reroll_failed'];

    /** The page does not exist while the feature is off. */
    public function initialize(): void
    {
        parent::initialize();
        if (!(new Contracts($this->db, $this->configs, $this->state))->enabled((int) $this->state->USER['universe'])) {
            // the feature is off: the page does not exist for players
            $this->printPageError($this->state->LNG['sys_module_inactive']);
        }
    }

    /**
     * The contracts page: generates this week's contracts when the player has none yet.
     */
    #[GameRoute('game', 'game/contracts', 'contracts')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $universe = (int) $USER['universe'];
        $now = $this->state->runtime->timestamp;
        (new Contracts($this->db, $this->configs, $this->state))->ensureWeek($universe, (int) $USER['id'], $now);
        $board = (new ContractBoard($this->db, $this->configs, $this->state))->summary((int) $USER['id'], $universe, $now);
        foreach ($board['contracts'] as &$contract) {
            $contract['label'] = $LNG->format('eng_ct_kind_' . $contract['kind'], $this->viewHelpers()->prettyNumber($contract['target']));
            $contract['progressText'] = $this->viewHelpers()->prettyNumber($contract['progress']) . ' / ' . $this->viewHelpers()->prettyNumber($contract['target']);
        }
        unset($contract);
        foreach ($board['previous'] as &$old) {
            $old['label'] = $LNG->format('eng_ct_kind_' . $old['kind'], $this->viewHelpers()->prettyNumber($old['target']));
            $old['until'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $old['claimUntil'], $USER['timezone']);
        }
        unset($old);
        $left = max(0, $board['endsAt'] - $now);
        $notice = $this->input()->string('notice');

        return $this->render('game/page.contracts.default.html.twig', $board + [
            'daysLeft' => intdiv($left, 86400),
            'hoursLeft' => intdiv($left % 86400, 3600),
            'resourceMode' => (int) ($this->configs->get($universe)->contracts_reward_mode ?? 0) === 1 && (int) ($this->configs->get($universe)->contracts_resource_cap ?? 0) > 0,
            'notice' => in_array($notice, self::NOTICES, true) ? $LNG['eng_ct_notice_' . $notice] : null,
        ]);
    }

    /**
     * POST id: claims a finished contract of the player once (the reward goes to the current planet in resources mode).
     */
    #[GameRoute('game', 'game/contracts/claim', 'contracts', 'claim', ['POST'])]
    public function claim(): Response
    {
        $USER = $this->state->USER;
        $result = (new ContractBoard($this->db, $this->configs, $this->state))->claim(
            (int) $USER['id'],
            (int) $USER['universe'],
            $this->input()->int('id'),
            (int) $this->state->PLANET['id'],
            $this->state->runtime->timestamp,
        );

        return $this->redirectToRoute('game_contracts_show', ['notice' => $result['ok'] ? 'claimed' : 'claim_failed']);
    }

    /**
     * POST id: replaces an unfinished contract of the player by another kind (limited per week by contracts_rerolls).
     */
    #[GameRoute('game', 'game/contracts/reroll', 'contracts', 'reroll', ['POST'])]
    public function reroll(): Response
    {
        $USER = $this->state->USER;
        $done = (new ContractBoard($this->db, $this->configs, $this->state))->reroll(
            (int) $USER['id'],
            (int) $USER['universe'],
            $this->input()->int('id'),
            $this->state->runtime->timestamp,
        );

        return $this->redirectToRoute('game_contracts_show', ['notice' => $done ? 'rerolled' : 'reroll_failed']);
    }
}
