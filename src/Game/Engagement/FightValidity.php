<?php

declare(strict_types=1);

namespace App\Game\Engagement;

use App\Game\Core\Player\PairNeutrality;

/**
 * Anti-abuse of the engagement features that reward fights (weekly contracts, alliance goals): a fight counts for a player
 * only when the opponent side lost at least a minimum value and holds an opponent who is a real enemy, i.e. not the player
 * himself, not in the same alliance, no buddy, no alliance pact partner, not flagged as multi account / same IP, and not
 * neutral by the honor rules (inactive, see PairNeutrality). Pirate bases never count, and a user whose units were worth less than 10 % of his side's total at the start of the fight (and who is not alone) gets no credit.
 */
final class FightValidity
{
    /** Metal+crystal value the opponent side has to lose for a fight to count. */
    public const MIN_OPPONENT_LOSS = 10000;

    /** @var array<string, bool> report id and user => verdict, shared by the features of a request (a fight is judged once per user) */
    private static array $verdicts = [];

    public function __construct(
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Whether the fight counts for the user (who must have fought on one side of the outcome).
     */
    public function counts(BattleOutcome $outcome, int $userId): bool
    {
        $key = $outcome->reportId . ':' . $userId;
        if (!isset(self::$verdicts[$key])) {
            if (count(self::$verdicts) > 500) {
                self::$verdicts = [];
            }
            self::$verdicts[$key] = $this->judge($outcome, $userId);
        }

        return self::$verdicts[$key];
    }

    /** The verdict of counts() (not cached). */
    private function judge(BattleOutcome $outcome, int $userId): bool
    {
        if ($outcome->kind === 'pirate' || !$outcome->holdsShare($userId)) {
            return false;
        }
        if ($outcome->isAttacker($userId)) {
            $opponents = array_keys($outcome->defenders);
            $opponentLoss = $outcome->losses->defenderValue;
        } elseif ($outcome->isDefender($userId)) {
            $opponents = array_keys($outcome->attackers);
            $opponentLoss = $outcome->losses->attackerValue;
        } else {
            return false;
        }
        if ($opponentLoss < self::MIN_OPPONENT_LOSS) {
            return false;
        }
        foreach ($opponents as $opponent) {
            if ($this->isRealEnemy($userId, (int) $opponent)) {
                return true;
            }
        }

        return false;
    }

    /** True when the two users are real enemies (none of the exclusions of the class description applies). */
    public function isRealEnemy(int $userId, int $opponent): bool
    {
        if ($opponent <= 0 || $opponent === $userId) {
            return false;
        }
        if ((new PairNeutrality($this->db, $this->configs, $this->state))->isNeutral($userId, $opponent)) {
            return false;
        }

        return !(new Seasons\FightNeutrality($this->db, $this->state->runtime->timestamp))->pairIsNeutral($userId, $opponent);
    }
}
