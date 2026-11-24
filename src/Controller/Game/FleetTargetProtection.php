<?php

namespace App\Controller\Game;

use App\Game\Enum\Mission;

/**
 * Early warning for the dispatch steps: whether the final dispatch would be refused because the target player is under
 * noob protection or too strong (the same rules and the same texts as FleetDispatchMissionRules, nothing is changed).
 */
trait FleetTargetProtection
{
    /** Missions the dispatch checks against the protection rules. */
    private const PROTECTED_MISSIONS = [Mission::Attack->value, Mission::Acs->value, Mission::Hold->value, Mission::Spy->value, Mission::Destruction->value];

    /**
     * Refusal text for hostile missions against the owner of the target, '' when the dispatch is allowed. A too strong target
     * only blocks when the universe has no outlaw rule (otherwise the dispatch makes the player an outlaw and step 2 asks first).
     * With a $mission the returning-player protection of the target is checked as well (only aggressive missions are refused).
     */
    private function protectionNotice(int $targetOwnerId, int $mission = 0): string
    {
        $USER = $this->state->USER;
        if ($targetOwnerId <= 0 || $targetOwnerId == $USER['id']) {
            return '';
        }
        $target = $this->playerLookup()->getPlayerById($targetOwnerId);
        $own = $this->playerLookup()->getPlayerById((int) $USER['id']);
        if (empty($target) || empty($own)) {
            return '';
        }
        $protection = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($USER + $own), $this->models()->user($target));
        $LNG = $this->state->LNG;
        if ($protection['NoobPlayer']) {
            return (string) $LNG['fl_player_is_noob'];
        }
        if ($protection['StrongPlayer'] && !$this->configs->get()->noob_protection_outlaw) {
            return (string) $LNG['fl_player_is_strong'];
        }
        if (in_array($mission, \App\Game\Engagement\Returning\ReturningPlayers::AGGRESSIVE, true)
            && (new \App\Game\Engagement\Returning\ReturningPlayers($this->db, $this->configs, $this->state))->protectedUntil((int) $target['universe'], $targetOwnerId) > 0) {
            return (string) $LNG['fl_player_is_returning'];
        }

        return '';
    }
}
