<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relic actions (optional engagement feature, universe setting relics_enabled): equip on the current planet, unequip,
 * lock, dismantle and reforge. All are POST (CSRF-protected like every game POST), act on one relic id of the player
 * and redirect back to the resources page, which shows the relics and the result of the action as a notice. The
 * actions answer "module inactive" while the feature is off. The page itself is part of the resources page.
 */
class RelicsController extends AbstractGameController
{
    /** session key of the result notice (shown once by the resources page) */
    public const NOTICE_KEY = 'relics_notice';

    public function assertModuleAvailable(): void
    {
        if (!$this->relicBonus()->enabled((int) $this->state->USER['universe'])) {
            $this->printPageError($this->state->LNG['sys_module_inactive']);
        }
    }

    /** Equips the relic (POST id) on the current planet in the relic's slot; a relic in that slot goes back to storage. */
    #[GameRoute('game', 'game/relics/equip', 'relics', 'equip', ['POST'])]
    public function equip(): Response
    {
        $code = $this->relicInventory()->equip($this->userId(), $this->universeId(), $this->input()->int('id'), (int) $this->state->PLANET['id'], $this->state->runtime->timestamp);

        return $this->done($code, 'equip');
    }

    /** Takes the relic (POST id) off its planet. */
    #[GameRoute('game', 'game/relics/unequip', 'relics', 'unequip', ['POST'])]
    public function unequip(): Response
    {
        $code = $this->relicInventory()->unequip($this->userId(), $this->universeId(), $this->input()->int('id'), $this->state->runtime->timestamp);

        return $this->done($code, 'unequip');
    }

    /** Toggles the lock of the relic (POST id): a locked relic cannot be dismantled. */
    #[GameRoute('game', 'game/relics/lock', 'relics', 'lock', ['POST'])]
    public function lock(): Response
    {
        return $this->done($this->relicInventory()->toggleLock($this->userId(), $this->input()->int('id')), 'lock');
    }

    /** Dismantles the relic (POST id) into dust. */
    #[GameRoute('game', 'game/relics/dismantle', 'relics', 'dismantle', ['POST'])]
    public function dismantle(): Response
    {
        $code = $this->relicInventory()->dismantle($this->userId(), $this->universeId(), $this->input()->int('id'), $this->state->runtime->timestamp);

        return $this->done($code, 'dismantle');
    }

    /** Rerolls the value of one substat (POST id, sub = index) for dust and resources of the current planet. */
    #[GameRoute('game', 'game/relics/reforge', 'relics', 'reforge', ['POST'])]
    public function reforge(): Response
    {
        $code = $this->relicInventory()->reforge(
            $this->userId(),
            $this->universeId(),
            $this->input()->int('id'),
            $this->input()->int('sub'),
            (int) $this->state->PLANET['id'],
            $this->state->runtime->timestamp,
        );

        return $this->done($code, 'reforge');
    }

    private function userId(): int
    {
        return (int) $this->state->USER['id'];
    }

    private function universeId(): int
    {
        return (int) $this->state->USER['universe'];
    }

    /**
     * Stores the result notice for the resources page and goes back there. The text is rl_msg_<code> for an error code,
     * rl_msg_ok_<action> after a success.
     */
    private function done(string $code, string $action): Response
    {
        $LNG = $this->state->LNG;
        $ok = $code === 'ok';
        $key = $ok ? 'rl_msg_ok_' . $action : 'rl_msg_' . $code;
        $this->sessions()->symfonySession()->set(self::NOTICE_KEY, ['text' => (string) $LNG[$key], 'error' => !$ok]);

        return $this->redirectToRoute('game_resources_show');
    }
}
