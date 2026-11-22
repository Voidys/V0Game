<?php

namespace App\Game\Core\Player;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Honor points: results of 1 vs 1 and ACS fights, ranking thresholds, and the boost/decrease effects on attack
 * costs. Collaborators: AdvancedStatsHandler (stored values and fight counters), Database.
 */
class HonorPoints
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Calculate and update honorpoint changes for 1 vs 1 fights
     *
     * honorable for both if:
     * Attacker points <= Target points AND (Attacker military points <= double Target military points OR Target is Bandit(Low 30%))
     * OR
     * Attacker points < Target points AND (Attacker military points < double Target military points OR < 10 military points difference)
     *
     * dishonorable for attacker if:
     * Attacker military points > double Target military points AND > 10 military points difference AND Target honor rank is better than Banditlord(Low 20%)
     *
     * neutral if:
     * Attacker is in the same alliance as the target
     * Attacker is a buddy of the target
     * Defender is inactive
     * any case not covered by the above
     *
     * @param int           $attacker               Id of attacker
     * @param int           $defender               ID of defender
     * @param int           $attackerUnitsLost      Units destroyed by the defender
     * @param int           $defenderUnitsLost      Units destroyed by the attacker
     * @return array<mixed>
     */
    public function calculate_honorpoints_single(int $attacker, int $defender, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        $db = $this->db;

        $honorpointsResult = [
            'attackersH' => [],
            'defendersH' => [],
        ];

        $sql = 'SELECT u.`id`, u.`username`,  s.`total_points`, (s.`fleet_points` + s.`defs_points`) as military, u.`ally_id`, u.`onlinetime`
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.id_owner = u.id
            WHERE id = :id AND s.stat_type = 1;';
        $attackerUser = $db->selectSingle($sql, [
            ':id' => $attacker,
        ]);
        $defenderUser = $db->selectSingle($sql, [
            ':id' => $defender,
        ]);

        if ($this->state->runtime->timestamp - INACTIVE > $defenderUser['onlinetime']
            || ($attackerUser['ally_id'] == $defenderUser['ally_id'] && $attackerUser['ally_id'] != 0)) {
            return $honorpointsResult;
        }

        $sql = "SELECT count(*) as anz 
            FROM %%BUDDY%% 
            WHERE (`sender` = :attacker AND `owner` = :defender) 
               OR (`sender` = :defender AND `owner` = :attacker);";
        $buddy = $db->selectSingle($sql, [
            ':attacker' => $attacker,
            ':defender' => $defender,
        ], 'anz');

        if ($buddy > 0) {
            return $honorpointsResult;
        }

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $attackerUser['honorpoints'] = $statsHandler->getValue($attacker, AdvancedStatsEnum::HONORPOINTS);
        $defenderUser['honorpoints'] = $statsHandler->getValue($defender, AdvancedStatsEnum::HONORPOINTS);

        $honorDataTop = $this->get_honor_percent("low");

        $defenderIsBandit = $defenderUser['honorpoints'] <= $honorDataTop['top30']['honorpoints']
            && $defenderUser['honorpoints'] <= -500;

        [$honorableForBoth, $dishonorableForAttacker] = $this->judgeFight($attackerUser, $defenderUser, $defenderIsBandit, $honorDataTop);

        $attackerHonorpointsChange = 0;
        $defenderHonorpointsChange = 0;

        if ($honorableForBoth && $this->pairCapReached($attacker, $defender)) {
            return $honorpointsResult;
        }

        if ($honorableForBoth) {
            $attackerHonorpointsChange = floor(pow($defenderUnitsLost, 0.9) / 1000);
            $defenderHonorpointsChange = floor(pow($attackerUnitsLost, 0.9) / 1000);

            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORPOINTS, $attackerHonorpointsChange);

            $statsHandler->increment($defender, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
            $statsHandler->increment($defender, AdvancedStatsEnum::HONORPOINTS, $defenderHonorpointsChange);
        } elseif ($dishonorableForAttacker) {
            $attackerHonorpointsChange = 0 - floor(pow($defenderUnitsLost, 0.9) / 1000);
            $statsHandler->increment($attacker, AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORPOINTS, $attackerHonorpointsChange);
        } else {
            return $honorpointsResult;
        }

        $honorpointsResult['attackersH'][$attackerUser['username']] = $attackerHonorpointsChange;
        $honorpointsResult['defendersH'][$defenderUser['username']] = $defenderHonorpointsChange;

        return $honorpointsResult;
    }

    /**
     * Universe setting honor_pair_cap (F-17): at most that many honorable fights between the same two players (either
     * direction) in 24 hours award honor; further fights count as neutral. 0 = unlimited (no bookkeeping at all).
     * When the cap is not reached the fight is recorded, so the caller may award honor.
     */
    private function pairCapReached(int $attacker, int $defender): bool
    {
        $cap = (int) $this->configs->get()->honor_pair_cap;
        if ($cap <= 0) {
            return false;
        }

        $now = $this->state->runtime->timestamp;
        // battles run inside the event transaction: locking both user rows (ascending id, one fixed order) serialises
        // count + insert per pair; the count below is a locking read so it sees fights committed by the other transaction
        $this->db->select('SELECT `id` FROM %%USERS%% WHERE `id` IN (:a, :d) ORDER BY `id` FOR UPDATE;', [
            ':a' => $attacker,
            ':d' => $defender,
        ]);
        $this->db->delete('DELETE FROM %%HONOR_FIGHTS%% WHERE `time` < :old;', [':old' => $now - ONE_DAY]);
        $count = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%HONOR_FIGHTS%%
            WHERE ((`attacker` = :a AND `defender` = :d) OR (`attacker` = :d AND `defender` = :a)) AND `time` >= :since FOR UPDATE;',
            [':a' => $attacker, ':d' => $defender, ':since' => $now - ONE_DAY],
            'anz',
        );
        if ($count >= $cap) {
            return true;
        }

        $this->db->insert('INSERT INTO %%HONOR_FIGHTS%% SET `attacker` = :a, `defender` = :d, `time` = :t;', [
            ':a' => $attacker,
            ':d' => $defender,
            ':t' => $now,
        ]);

        return false;
    }

    /**
     * Calculate and update the change in honorpoints for multiple attackers and defenders(ACS)
     * Points get distributed proportional to the ships send(points)
     * Player needs to add more than 1% of the total points to get honorpoints
     *
     * honorable for attackers if:
     * sum of attacker military points <= double of the sum of defender military points OR one of the defenders is Bandit(Low 30%)
     *
     * honorable for defenders if:
     * sum of defender military points <= double of the sum of attacker military points OR one of the attackers is Bandit(Low 30%)
     *
     * @param array<mixed> $attackers
     * @param array<mixed> $defenders
     * @param int $attackerUnitsLost
     * @param int $defenderUnitsLost
     * @return array<mixed>
     */
    public function calculate_honorpoints_multi(array $attackers, array $defenders, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $honorDataTop = $this->get_honor_percent("low");
        $playerData = [];

        $sql = 'SELECT u.id, (`fleet_points` + `defs_points`) as military
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.id_owner = u.id
            WHERE u.`id` = :id AND s.stat_type = 1;';

        // Gather data for attackers, then defenders (a player listed on both sides counts for the attackers)
        [$sumMilitaryPointsAttackers, $attackerHasBandit, $attackerFullpoints]
            = $this->collectSide($attackers, 'attacker', $playerData, $sql, $honorDataTop, $statsHandler);
        [$sumMilitaryPointsDefenders, $defenderHasBandit, $defenderFullpoints]
            = $this->collectSide($defenders, 'defender', $playerData, $sql, $honorDataTop, $statsHandler);

        // Calculate honorpoint changes for attackers
        if ($sumMilitaryPointsAttackers <= ($sumMilitaryPointsDefenders * 2) || $defenderHasBandit) {
            $attackerHonorpointsChange = floor(pow($defenderUnitsLost, 0.9) / 1000);
        } else {
            $attackerHonorpointsChange = 0 - floor(pow($defenderUnitsLost, 0.9) / 1000);
        }

        // Calculate honorpoint changes for defenders
        if ($sumMilitaryPointsDefenders <= ($sumMilitaryPointsAttackers * 2) || $attackerHasBandit) {
            $defenderHonorpointsChange = floor(pow($attackerUnitsLost, 0.9) / 1000);
        } else {
            $defenderHonorpointsChange = 0 - floor(pow($attackerUnitsLost, 0.9) / 1000);
        }

        $result = [
            'attackersH' => [],
            'defendersH' => [],
        ];
        // Players that are neutral against every opponent (ally, buddy, inactive defender, honor_pair_cap) get nothing
        $neutral = $this->neutralPlayers($playerData, $attackerHonorpointsChange > 0 || $defenderHonorpointsChange > 0);

        // Distribute honorpoints to players
        foreach ($playerData as $playerID => $data) {
            if (isset($neutral[$playerID])) {
                $result[$data['side'] . 'sH'][$data['username']] = 0;
                continue;
            }
            if ($data['side'] == 'attacker') {
                $fullpoints = $attackerFullpoints;
                $pointsChange = $attackerHonorpointsChange;
            } else {
                $fullpoints = $defenderFullpoints;
                $pointsChange = $defenderHonorpointsChange;
            }

            // a share of 1% or less gets no points and the fight does not count for the player
            if (!isset($data['fightpoints']) || $fullpoints == 0 || $data['fightpoints'] / $fullpoints <= 0.01) {
                $result[$data['side'] . 'sH'][$data['username']] = 0;
                continue;
            }

            if ($pointsChange > 0) {
                $statsHandler->increment($playerID, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
            } elseif ($pointsChange < 0) {
                $statsHandler->increment($playerID, AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
            }

            // rounded once: the report shows the value that is stored
            $change = (int) round($pointsChange * ($data['fightpoints'] / $fullpoints));
            $statsHandler->increment($playerID, AdvancedStatsEnum::HONORPOINTS, $change);
            $result[$data['side'] . 'sH'][$data['username']] = $change;
        }

        return $result;
    }

    /**
     * Multi fights: a player is neutral when each (attacker, defender) pair they are part of is neutral (same alliance, buddies,
     * inactive defender, or the honor_pair_cap reached; a pair is only counted towards the cap when honor is awarded).
     *
     * @param array<int|string, array<string, mixed>> $playerData per-player data of collectSide() (side)
     * @return array<int, true> neutral player ids
     */
    private function neutralPlayers(array $playerData, bool $awarded): array
    {
        $attackerIds = [];
        $defenderIds = [];
        foreach ($playerData as $playerID => $data) {
            if ($data['side'] == 'attacker') {
                $attackerIds[] = (int) $playerID;
            } else {
                $defenderIds[] = (int) $playerID;
            }
        }

        $honorablePlayers = [];
        foreach ($attackerIds as $attacker) {
            foreach ($defenderIds as $defender) {
                if ($this->pairIsNeutral($attacker, $defender) || ($awarded && $this->pairCapReached($attacker, $defender))) {
                    continue;
                }
                $honorablePlayers[$attacker] = true;
                $honorablePlayers[$defender] = true;
            }
        }

        $neutral = [];
        foreach (array_merge($attackerIds, $defenderIds) as $playerID) {
            if (!isset($honorablePlayers[$playerID])) {
                $neutral[$playerID] = true;
            }
        }

        return $neutral;
    }

    /** Same neutrality rules as calculate_honorpoints_single(): inactive defender, same alliance, buddies. */
    private function pairIsNeutral(int $attacker, int $defender): bool
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

    /**
     * Applies the honor rules of a 1 vs 1 fight (see calculate_honorpoints_single() for the rule text).
     *
     * @param array<mixed> $attackerUser total_points, military, honorpoints of the attacker
     * @param array<mixed> $defenderUser total_points, military, honorpoints of the defender
     * @param bool $defenderIsBandit defender is in the low 30% of the honor ranking and has <= -500 points
     * @param array<mixed> $honorDataTop result of get_honor_percent("low") (top20 honorpoints threshold)
     * @return array{0: bool, 1: bool} [honorable for both, dishonorable for the attacker]
     */
    private function judgeFight(array $attackerUser, array $defenderUser, bool $defenderIsBandit, array $honorDataTop): array
    {
        $honorableForBoth = (
            $attackerUser['total_points'] <= $defenderUser['total_points']
            && (
                $attackerUser['military'] <= ($defenderUser['military'] * 2)
                || $defenderIsBandit
            )
        ) || (
            $attackerUser['total_points'] < $defenderUser['total_points']
            && (
                $attackerUser['military'] <= ($defenderUser['military'] * 2)
                || ($attackerUser['military'] - $defenderUser['military']) <= 10
            )
        );

        $dishonorableForAttacker = (
            $attackerUser['military'] > ($defenderUser['military'] * 2)
            && ($attackerUser['military'] - $defenderUser['military']) > 10
            && $defenderUser['honorpoints'] >= $honorDataTop['top20']['honorpoints']
        );

        return [$honorableForBoth, $dishonorableForAttacker];
    }

    /**
     * Collects the data of one side of an ACS fight: military points, bandit status and fight points.
     *
     * Each player is registered once in $playerData (side, username, fightpoints = value of the units he sent,
     * i.e. amount * (metal + crystal + deuterium cost)). A bandit is a player in the low 30% of the honor ranking
     * with <= -500 honor points.
     *
     * @param array<mixed> $fleets fleets of this side: [fleetId => ['player' => [id, username], 'unit' => [shipId => amount]]]
     * @param string $side "attacker" or "defender"
     * @param array<mixed> $playerData shared per-player data, filled by reference
     * @param string $militarySql query selecting `military` for a user id
     * @param array<mixed> $honorDataTop result of get_honor_percent("low")
     * @param AdvancedStatsHandler $statsHandler
     * @return array{0: int|float, 1: bool, 2: int|float} summed military points of the side's players, side has a bandit, total fight points of the side
     */
    private function collectSide(array $fleets, string $side, array &$playerData, string $militarySql, array $honorDataTop, AdvancedStatsHandler $statsHandler): array
    {
        $elements = $this->elements();
        $sumMilitaryPoints = 0;
        $hasBandit = false;
        $fullPoints = 0;

        foreach ($fleets as $FleetID => $player) {
            $playerID = $player['player']['id'];

            if (!isset($playerData[$playerID])) {
                $playerData[$playerID] = [
                    'side' => $side,
                    'username' => $player['player']['username'],
                    'fightpoints' => 0,
                ];

                $sumMilitaryPoints += $this->db->selectSingle($militarySql, [
                    ':id' => $playerID,
                ], 'military');

                if (!$hasBandit) {
                    $honorpoints = $statsHandler->getValue($playerID, AdvancedStatsEnum::HONORPOINTS);
                    $hasBandit = $honorpoints <= $honorDataTop['top30']['honorpoints']
                        && $honorpoints <= -500;
                }
            }

            foreach ($player['unit'] as $ShipID => $Amount) {
                $fullCost = $elements->cost($ShipID)->metalCrystalDeuterium();
                $playerData[$playerID]['fightpoints'] += $Amount * $fullCost;
                $fullPoints += $Amount * $fullCost;
            }
        }

        return [$sumMilitaryPoints, $hasBandit, $fullPoints];
    }

    /**
     * Compare the number of honorable and dishonorable fights of a player with the reference data
     * true if the player has more honorable fights or the same number of honorable fights and less dishonorable fights
     * @param array<mixed> $userFights
     * @param array<mixed> $referenceFights
     * @return bool
     */
    public function compare_honor_fights(array $userFights, array $referenceFights): bool
    {
        if ($userFights['honorable_fights'] > $referenceFights['honorable_fights']) {
            return true;
        } elseif ($userFights['honorable_fights'] == $referenceFights['honorable_fights']) {
            if ($userFights['dishonorable_fights'] < $referenceFights['dishonorable_fights']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieve the honorpoints and the number of honorable and dishonorable fights of a player
     * @param int $userId
     * @return array<mixed> $honordata
     */
    public function get_honor_data(int $userId): array
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        return $statsHandler->getMultipleStats($userId, [
            AdvancedStatsEnum::HONORPOINTS,
            AdvancedStatsEnum::HONORABLE_FIGHTS,
            AdvancedStatsEnum::DISHONORABLE_FIGHTS,
        ]);
    }

    /**
     * Calculate good effect of honorpoints (decrease deut costs for attacks on players with high honorpoints)
     * can be 0.80(20%), 0.88(12%), 0.95(5%) or 1(0%) decrease
     * @param int $userId
    * @return float $factor
     */
    public function calculate_honorpoints_boost(int $userId): float
    {
        $factor = 1.0;
        $honordata = $this->get_honor_data($userId);

        if ($honordata['honorpoints'] >= 500 && $this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
            $honorDataTop = $this->get_honor_percent("top");
            if ($honordata['honorpoints'] > $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] >= 15000) {
                $factor = 0.80;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] >= 2500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top10']) && $honordata['honorpoints'] >= 15000 ? 0.80 : 0.88;
            } elseif ($honordata['honorpoints'] > $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] >= 2500) {
                $factor = 0.88;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top20']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top20']) && $honordata['honorpoints'] >= 2500 ? 0.88 : 0.95;
            } elseif ($honordata['honorpoints'] > $honorDataTop['top30']['honorpoints']) {
                $factor = 0.95;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top30']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top30']) ? 0.95 : 1;
            }
        }
        return $factor;
    }

    /**
     * Calculate bad effect of honorpoints (increase loot for attacks on players with low honorpoints)
     * can be 1(50%), 1.2(60%), 1.6(80%) or 2(100%) increase
     * @param int $userId
     * @return float $factor
     */
    public function calculate_honorpoints_decrease(int $userId): float
    {
        $factor = 1;
        $honordata = $this->get_honor_data($userId);

        if ($honordata['honorpoints'] <= -500 && $this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
            $honorDataTop = $this->get_honor_percent("low");
            if ($honordata['honorpoints'] < $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] <= -15000) {
                $factor = 2;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] <= -2500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top10']) && $honordata['honorpoints'] > -15000 ? 1.6 : 2;
            } elseif ($honordata['honorpoints'] < $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] <= -2500) {
                $factor = 1.6;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] <= -500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top20']) && $honordata['honorpoints'] > -2500 ? 1.2 : 1.6;
            } elseif ($honordata['honorpoints'] < $honorDataTop['top30']['honorpoints'] && $honordata['honorpoints'] <= -500) {
                $factor = 1.2;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top30']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top30']) && $honordata['honorpoints'] > -500 ? 1 : 1.2;
            }
        }
        return $factor;
    }

    /**
     * Calculate the border dataset for the top or low 10%, 20% and 30% of the universe
     *
     * @param string $kind("top" or "low")
     * @return array<mixed>
     */
    public function get_honor_percent(string $kind = "top"): array
    {
        $db = $this->db;
        $sql = 'SELECT count(*) as count FROM %%USERS%% WHERE universe = :universe;';
        $userAmount = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
        ], 'count');

        $limTop10 = ceil(($userAmount - 1) * (1 - 0.90));
        $limTop20 = ceil(($userAmount - 1) * (1 - 0.80));
        $limTop30 = ceil(($userAmount - 1) * (1 - 0.70));
        //top 10%   - 20% deut rabatt
        //top 20%   - 12% deut rabatt
        //top 30%   - 5% deut rabatt
        //mid 40%   - neutral (0% rabatt, 50% loot)
        //low 30%   - 60% loot
        //low 20%   - 80% loot
        //low 10%   - 100% loot

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $honorDataTop10 = $statsHandler->getHonorList($this->universe()->current(), $limTop10, $kind);
        $honorDataTop20 = $statsHandler->getHonorList($this->universe()->current(), $limTop20, $kind);
        $honorDataTop30 = $statsHandler->getHonorList($this->universe()->current(), $limTop30, $kind);

        return [
            "top10" => end($honorDataTop10),
            "top20" => end($honorDataTop20),
            "top30" => end($honorDataTop30),
        ];
    }
}
