<?php

declare(strict_types=1);

namespace App\Game\Engagement\Wars;

use App\Game\BattleEngine\BattleSide;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Engagement\BattleOutcome;
use App\Game\Engagement\Seasons\FightNeutrality;

/**
 * Adds a finished fight to the open wars between the alliances of its two sides: every side scores the value the other
 * side's alliance lost, the attacking alliance also the loot (shared by head among the alliances of the attackers).
 * Only human players count (bots, pirates and players without an alliance are ignored), and fights below
 * war_min_fight_value are skipped.
 */
final class WarScoring
{
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly AllianceWars $wars,
    ) {}

    /** Scores the fight for every open war between an alliance of the attackers and one of the defenders. */
    public function apply(BattleOutcome $outcome): void
    {
        if ($outcome->attackers === [] || $outcome->defenders === [] || $outcome->totalLost() < $this->wars->minFightValue($outcome->universe)) {
            return;
        }
        $attackers = $this->alliances(array_keys($outcome->attackers));
        $defenders = $this->alliances(array_keys($outcome->defenders));
        $neutral = new FightNeutrality($this->db, $outcome->time);
        $headcount = count($outcome->attackers);
        foreach (array_unique($attackers) as $x) {
            foreach (array_unique($defenders) as $y) {
                if ($x === $y || ($war = $this->wars->openWar($outcome->universe, $x, $y, $outcome->time)) === null) {
                    continue;
                }
                // alts and friends of the other side (same IP, buddies, pacts) do not count as enemies
                $mine = $this->enemies(array_keys($attackers, $x, true), array_keys($defenders, $y, true), $neutral);
                $theirs = $this->enemies(array_keys($defenders, $y, true), array_keys($attackers, $x, true), $neutral);
                if ($mine === [] || $theirs === []) {
                    continue;
                }
                $gain = $this->lost($outcome, BattleSide::Defender, $theirs);
                $back = $this->lost($outcome, BattleSide::Attacker, $mine);
                $this->add($war, $x, $gain, $back, $outcome->lootTotal() * count($mine) / max(1, $headcount), $outcome->time);
            }
        }
    }

    /**
     * The users of one side that are not neutral to at least one user of the other side.
     *
     * @param list<int|string> $users
     * @param list<int|string> $others
     * @return list<int>
     */
    private function enemies(array $users, array $others, FightNeutrality $neutral): array
    {
        $result = [];
        foreach ($users as $user) {
            foreach ($others as $other) {
                if (!$neutral->pairIsNeutral((int) $user, (int) $other)) {
                    $result[] = (int) $user;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Lost value of some users on a side.
     *
     * @param list<int> $users
     */
    private function lost(BattleOutcome $outcome, BattleSide $side, array $users): float
    {
        $sum = 0.0;
        foreach ($users as $userId) {
            $sum += (float) $outcome->losses->userValue($side, $userId);
        }

        return $sum;
    }

    /**
     * Updates the war row: alliance $attacker scored $gain and $loot, the other alliance $back.
     *
     * @param array<string, mixed> $war
     */
    private function add(array $war, int $attacker, float $gain, float $back, float $loot, int $now): void
    {
        $ownIsA = (int) $war['ally_a'] === $attacker;
        $this->db->update('UPDATE %%ENG_WARS%% SET `score_a` = `score_a` + :sa, `score_b` = `score_b` + :sb, `loot_a` = `loot_a` + :la, `loot_b` = `loot_b` + :lb, `fights` = `fights` + 1
            WHERE `id` = :id AND `ended_at` IS NULL;', [
            ':sa' => $ownIsA ? $gain : $back,
            ':sb' => $ownIsA ? $back : $gain,
            ':la' => $ownIsA ? $loot : 0,
            ':lb' => $ownIsA ? 0 : $loot,
            ':id' => (int) $war['id'],
        ]);
    }

    /**
     * Alliance of every human player of a side that is in an alliance.
     *
     * @param list<int|string> $userIds
     * @return array<int, int> user id => alliance id
     */
    private function alliances(array $userIds): array
    {
        $ids = implode(',', array_map('intval', $userIds));
        $rows = $this->db->select('SELECT u.`id`, u.`ally_id` FROM %%USERS%% u WHERE u.`id` IN (' . $ids . ') AND u.`ally_id` > 0
            AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.`user_id` = u.`id`);');
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (int) $row['ally_id'];
        }

        return $map;
    }
}
