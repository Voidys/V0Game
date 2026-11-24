<?php

namespace App\Game\Core\Player;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Whether a fight between two players is neutral (no honor, no engagement credit): inactive defender, same alliance or
 * buddies. Shared by HonorPoints and the engagement features.
 */
class PairNeutrality
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** Inactive defender, same alliance or buddies (the rules of calculate_honorpoints_single()). */
    public function isNeutral(int $attacker, int $defender): bool
    {
        $sql = 'SELECT `ally_id`, `onlinetime` FROM %%USERS%% WHERE `id` = :id;';
        $attackerUser = $this->db->selectSingle($sql, [':id' => $attacker]);
        $defenderUser = $this->db->selectSingle($sql, [':id' => $defender]);
        if (empty($attackerUser) || empty($defenderUser)) {
            return true;
        }

        if (
            $this->state->runtime->timestamp - INACTIVE > $defenderUser['onlinetime']
            || ($attackerUser['ally_id'] == $defenderUser['ally_id'] && $attackerUser['ally_id'] != 0)
        ) {
            return true;
        }

        $buddy = $this->db->selectSingle(
            'SELECT count(*) as anz FROM %%BUDDY%%
            WHERE (`sender` = :attacker AND `owner` = :defender) OR (`sender` = :defender AND `owner` = :attacker);',
            [':attacker' => $attacker, ':defender' => $defender],
            'anz',
        );

        return $buddy > 0;
    }
}
