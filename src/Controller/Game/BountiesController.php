<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Engagement\Bounties\Bounties;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounties (optional engagement feature, universe setting bounties_enabled): the open bounties, the form to place one
 * with resources of the current planet, and the player's own bounties. The pages answer "module inactive" while the
 * feature is off.
 */
class BountiesController extends AbstractGameController
{
    private const ERRORS = ['amount', 'resources', 'target', 'self', 'placer', 'limit', 'cooldown', 'gone', 'progress', 'planet'];

    private function feature(): Bounties
    {
        return new Bounties($this->db, $this->configs, $this->state);
    }

    public function assertModuleAvailable(): void
    {
        if (!$this->feature()->enabled((int) $this->state->USER['universe'])) {
            $this->printPageError($this->state->LNG['sys_module_inactive']);
        }
    }

    /** The bounty page: open bounties, the placing form and the bounties of the player (`notice` / `error` = result of the last placing). */
    #[GameRoute('game', 'game/bounties', 'bounties')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $universe = (int) $USER['universe'];
        $now = $this->state->runtime->timestamp;
        $feature = $this->feature();
        $error = $this->input()->string('error');
        $notice = null;
        if (in_array($error, self::ERRORS, true)) {
            $notice = ['error' => true, 'text' => $LNG['bo_err_' . $error]];
        } elseif (in_array($this->input()->string('notice'), ['placed', 'cancelled'], true)) {
            $notice = ['error' => false, 'text' => $LNG['bo_' . $this->input()->string('notice')]];
        }

        return $this->render('game/page.bounties.default.html.twig', [
            'open' => array_map(fn(array $row): array => $this->row($row, $now, true), $feature->openList($universe, $now, (int) $USER['id'])),
            'mine' => array_map(fn(array $row): array => $this->row($row, $now, false), $feature->placedBy((int) $USER['id'])),
            'notice' => $notice,
            'feePercent' => (int) $feature->option($universe, 'bounty_fee_percent'),
            'minTotal' => (int) $feature->option($universe, 'bounty_min_total'),
            'planetName' => $this->state->PLANET['name'],
        ]);
    }

    /**
     * Places a bounty (POST target, metal, crystal, deuterium) with resources of the current planet and goes back to the page.
     */
    #[GameRoute('game', 'game/bounties/place', 'bounties', 'place', ['POST'])]
    public function place(): Response
    {
        $input = $this->input();
        $amounts = [];
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $amounts[$resource] = (int) min(Bounties::MAX_AMOUNT + 1, max(0, floor($input->bigNumber($resource))));
        }
        $result = $this->feature()->place($this->state->USER, (int) $this->state->PLANET['id'], trim($input->rawString('target')), $amounts, $this->state->runtime->timestamp);

        return $this->redirectToRoute('game_bounties_show', isset($result['error']) ? ['error' => $result['error']] : ['notice' => 'placed']);
    }

    /**
     * Takes back an own open bounty without progress (POST id): the stake after the fee returns to the main planet.
     */
    #[GameRoute('game', 'game/bounties/cancel', 'bounties', 'cancel', ['POST'])]
    public function cancel(): Response
    {
        $result = $this->feature()->cancel((int) $this->state->USER['id'], $this->input()->int('id'), $this->state->runtime->timestamp);

        return $this->redirectToRoute('game_bounties_show', $result === 'ok' ? ['notice' => 'cancelled'] : ['error' => $result]);
    }

    /**
     * One bounty row for the template.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row, int $now, bool $open): array
    {
        return [
            'id' => (int) $row['id'],
            'target' => $row['target_name'],
            'metal' => (int) $row['metal'],
            'crystal' => (int) $row['crystal'],
            'deuterium' => (int) $row['deuterium'],
            'total' => (int) $row['metal'] + (int) $row['crystal'] + (int) $row['deuterium'],
            'required' => (int) ceil((float) $row['required_value']),
            'progress' => $open ? (int) floor((float) $row['my_credit']) : 0,
            'expires' => $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], (int) $row['expires_at'], $this->state->USER['timezone']),
            'status' => $row['status'],
            'left' => max(0, (int) $row['expires_at'] - $now),
        ];
    }
}
