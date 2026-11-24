<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Achievements;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\Achievement;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleSide;
use App\Game\Core\Database;
use App\Game\Enum\Mission;

/**
 * Achievement rules triggered by fights (checkBattleAchievements() for real fights, checkExpoBattleAchievements()
 * for expedition fights); called from BattleHandler::GenerateReport(). Each checkBattleAchievementN() unlocks
 * achievement N (see the achievements table) when the fight meets its condition.
 */
class BattleAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievements related to pvp battles
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param array<mixed>			$DATA       		The report data array
     * @param array<int|string, BattleFleet> $attackers attacking fleets that survived the fight
     * @param array<int|string, BattleFleet> $defenders defending fleets that survived the fight (fleet 0 = the planet)
     */
    public function checkBattleAchievements($reportInfo, BattleResult $combatResult, $DATA, array $attackers = [], array $defenders = [])
    {
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $mainAttacker = $reportInfo['thisFleet']['owner'];
        $planetDefender = $combatResult->startDefenders()[0]->userId();
        $this->checkBattleAchievement6($reportInfo, $combatResult);
        $this->checkBattleAchievement39($reportInfo, $combatResult, $mainAttacker);
        $this->checkBattleAchievement10($reportInfo, $mainAttacker);
        $this->checkBattleAchievement34($planetDefender);
        $this->checkBattleAchievement47($DATA, $mainAttacker, $statsHandler);
        $this->checkBattleAchievement48($DATA, $mainAttacker, $statsHandler, $reportInfo);
        $this->checkBattleAchievement37($planetDefender, $combatResult);
        $this->checkBattleAchievement35($mainAttacker, $combatResult, $db, $planetDefender);
        $this->checkBattleAchievement38($mainAttacker, $combatResult, $db, $planetDefender);
        $this->checkBattleAchievement32($mainAttacker, $combatResult, $db, $statsHandler);
        $this->checkBattleAchievement3($combatResult, $planetDefender, $db);
        $this->checkBattleAchievement45($combatResult, $db, $planetDefender);
        $this->checkBattleAchievement43($combatResult, $planetDefender, $db, $DATA);
        $this->checkNightAndDateAchievements($reportInfo, $combatResult, $attackers);
        $this->checkAcsAchievements($combatResult, $reportInfo);
        $this->checkOutcomeAchievements($reportInfo, $combatResult, $attackers, $defenders);
    }

    /**
     * Achievements tied to the landing time of the attack: 1 (sleepless), 54 (new year), 86 (Friday the 13th), 87 (high noon).
     *
     * @param array<string, mixed> $reportInfo report array (thisFleet = the arriving fleet row)
     * @param array<int|string, BattleFleet> $attackers surviving attacking fleets
     */
    private function checkNightAndDateAchievements(array $reportInfo, BattleResult $combatResult, array $attackers): void
    {
        $fleet = $reportInfo['thisFleet'];
        $arrival = (int) $fleet['arrival_time'];
        $ids = $this->attackerIds($combatResult);

        $this->checkBattleAchievement1($fleet, $combatResult, $ids);

        if (date('m-d H:i:s', $arrival) === '01-01 00:00:00') {
            $defender = $combatResult->startDefenders()[0]->player ?? [];
            if (($defender['onlinetime'] ?? 0) > $arrival - INACTIVE) {
                $this->grantAll($ids, 54);
            }
        }

        if (date('j N', $arrival) === '13 5') {
            $lost = [];
            foreach ($combatResult->startAttackers() as $fleetId => $startFleet) {
                if (!isset($attackers[$fleetId]) || $attackers[$fleetId]->unitCount() <= 0) {
                    $lost[] = (int) $startFleet->userId();
                }
            }
            $this->grantAll($lost, 86);
        }

        if ($fleet['group'] == 0 && in_array((int) $fleet['mission'], [Mission::Attack->value, Mission::Destruction->value], true)) {
            $this->checkBattleAchievement87($fleet, $ids);
        }
    }

    /**
     * Achievements of ACS attacks and defending against them: 29 (communist manifesto), 30 (this is Sparta), 79 (team player).
     *
     * @param array<string, mixed> $reportInfo report array
     */
    private function checkAcsAchievements(BattleResult $combatResult, array $reportInfo): void
    {
        $ids = $this->attackerIds($combatResult);
        $won = $combatResult->won;

        if ($reportInfo['thisFleet']['group'] != 0 && $won == ATTACKER) {
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
            foreach ($ids as $userId) {
                $statsHandler->increment($userId, AdvancedStatsEnum::ACS_WINS, 1);
                if (!$this->achievement()->checkAchievement($userId, 79)
                    && $statsHandler->getValue($userId, AdvancedStatsEnum::ACS_WINS) >= 25) {
                    $this->achievement()->setAchievement($userId, 79);
                }
            }
        }

        if (count($ids) >= 5 && $won == ATTACKER) {
            $this->checkBattleAchievement29($combatResult, $ids);
        }

        if (count($ids) >= 5 && $won == DEFENDER) {
            $defenders = [];
            foreach ($combatResult->startDefenders() as $fleet) {
                $defenders[(int) $fleet->userId()] = true;
            }
            if (count($defenders) == 1) {
                $this->grantAll(array_keys($defenders), 30);
            }
        }
    }

    /**
     * Achievements read from the outcome of the fight: 67, 68, 69, 70, 73 and 50.
     *
     * @param array<string, mixed> $reportInfo report array
     * @param array<int|string, BattleFleet> $attackers surviving attacking fleets
     * @param array<int|string, BattleFleet> $defenders surviving defending fleets
     */
    private function checkOutcomeAchievements(array $reportInfo, BattleResult $combatResult, array $attackers, array $defenders): void
    {
        $startAttackers = $combatResult->startAttackers();
        $startDefenders = $combatResult->startDefenders();
        $planet = $startDefenders[0] ?? null;
        $owner = $planet === null ? 0 : (int) $planet->userId();
        $repaired = 0;
        foreach ($combatResult->repaired as $row) {
            $repaired += $row['units'];
        }
        $attackerLost = $this->unitTotal($startAttackers) - $this->unitTotal($attackers);
        $defenderLost = $this->unitTotal($startDefenders) - ($this->unitTotal($defenders) - $repaired);

        if ($combatResult->won == DEFENDER) {
            $this->checkBattleAchievement67($startAttackers, $startDefenders);
        }

        if ($combatResult->won == ATTACKER && $defenderLost >= 1000) {
            $losses = $combatResult->losses;
            foreach ($this->attackerIds($combatResult) as $userId) {
                if ($losses->userValue(BattleSide::Attacker, $userId) == 0) {
                    $this->grantAll([$userId], 68);
                }
            }
        }

        if ($combatResult->won == DRAW && $attackerLost >= 10000 && $defenderLost >= 10000
            && $this->unitTotal($attackers) > 0 && $this->unitTotal($defenders) - $repaired > 0) {
            $this->grantAll(array_merge($this->attackerIds($combatResult), $this->defenderIds($combatResult)), 69);
        }

        if ($owner > 0) {
            $this->checkDefenderLossAchievements($reportInfo, $combatResult, $planet, $defenders[0] ?? null, $attackers);
        }
    }

    /**
     * Check for achievements related to expedition battles
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     */
    public function checkExpoBattleAchievements($reportInfo, BattleResult $combatResult)
    {
        $mainAttacker = $reportInfo['thisFleet']['owner'];
        $this->checkBattleAchievement7($reportInfo, $combatResult, $mainAttacker);
    }

    /**
     * Check for achievement 6
     * Low performer: Have an attack come back with exqactly 1 metal, 1 crystal and 1 deuterium
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     */
    public function checkBattleAchievement6($reportInfo, BattleResult $combatResult)
    {
        if ($reportInfo['stealResource'][RESOURCE_METAL] == 1 && $reportInfo['stealResource'][RESOURCE_CRYSTAL] == 1 && $reportInfo['stealResource'][RESOURCE_CRYSTAL] == 1) {
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!$this->achievement()->checkAchievement($fleet->userId(), 6)) {
                    $this->achievement()->setAchievement($fleet->userId(), 6);
                }
            }
        }
    }

    /**
     * Check for achievement 39
     * The meaning of life: Attack someone with 42 ships
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement39($reportInfo, BattleResult $combatResult, $mainAttacker)
    {
        if ($reportInfo['thisFleet']['ship_amount'] == 42 && !$this->achievement()->checkAchievement($mainAttacker, 39)
            && count($combatResult->startAttackers()) == 1) {
            $this->achievement()->setAchievement($mainAttacker, 39);
        }
    }

    /**
     * Check for achievement 10
     * Marble game: Destroy one moon
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement10($reportInfo, $mainAttacker)
    {
        if ($reportInfo['moonDestroySuccess'] == 1 && !$this->achievement()->checkAchievement($mainAttacker, 10)) {
            $this->achievement()->setAchievement($mainAttacker, 10);
        }
    }

    /**
     * Check for achievement 34
     * Noble farm: Get attacked once
     *
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement34($planetDefender)
    {
        if (!$this->achievement()->checkAchievement($planetDefender, 34)) {
            $this->achievement()->setAchievement($planetDefender, 34);
        }
    }

    /**
     * Check for achievement 47
     * Jack the Ripper: Destroy 5 moons
    *
    * @param array<mixed>						$DATA       		The report data array
    * @param int	 					$mainAttacker       The id of the User
    * @param AdvancedStatsHandler	 	$statsHandler		The stats handler object
     */
    public function checkBattleAchievement47($DATA, $mainAttacker, AdvancedStatsHandler $statsHandler)
    {
        if ($DATA['moon']['moonDestroySuccess'] == 1) {
            $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::MOONS_DESTROYED);
            if (!$this->achievement()->checkAchievement($mainAttacker, 47) && $count >= 5) {
                $this->achievement()->setAchievement($mainAttacker, 47);
            }
        }
    }

    /**
     * Check for achievement 48
     * Rip the Jacker: Lose 5 rips while trying to destroy moons
     *
     * @param array<mixed>						$DATA       		The report data array
     * @param int	 					$mainAttacker       The id of the User
     * @param AdvancedStatsHandler	 	$statsHandler       The stats handler object
     * @param array<string, mixed>						$reportInfo       	The report array
     */
    public function checkBattleAchievement48($DATA, $mainAttacker, AdvancedStatsHandler $statsHandler, $reportInfo)
    {
        if ($DATA['moon']['fleetDestroySuccess'] == 1) {
            $shiptypes = explode(";", $reportInfo['thisFleet']["ships"]);
            foreach ($shiptypes as $shiptype) {
                $ship = explode(",", $shiptype);
                if ($ship[0] == SHIP_RIP || $ship[0] == VOID_TITAN) {
                    $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::DESTROY_MOON_RIPS_LOST);

                    if (!$this->achievement()->checkAchievement($mainAttacker, 48) && $count >= 5) {
                        $this->achievement()->setAchievement($mainAttacker, 48);
                    }
                }
            }
        }
    }

    /**
     * Check for achievement 37
     * I am not one of those: Get attacked by a 5 man ACS and lose
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement37($planetDefender, BattleResult $combatResult)
    {
        if (!$this->achievement()->checkAchievement($planetDefender, 37) && $combatResult->won == ATTACKER) {
            $countAttacker = 0;
            $attackers = [];
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!in_array($fleet->userId(), $attackers)) {
                    $countAttacker++;
                    array_push($attackers, $fleet->userId());
                }
            }
            //max aks participants var?
            if ($countAttacker == 5) {
                $this->achievement()->setAchievement($planetDefender, 37);
            }
        }
    }

    /**
     * Check for achievement 35
     * That's not nice!: Attack an active player with less than 10% of your points
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker     	The id of the User
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement35($mainAttacker, BattleResult $combatResult, $db, $planetDefender)
    {
        if (!$this->achievement()->checkAchievement($mainAttacker, 35) && $combatResult->won == ATTACKER && count($combatResult->startAttackers()) == 1) {
            $sql = "SELECT total_points FROM %%STATPOINTS%% a WHERE id_owner = :attacker AND stat_type = 1;";
            $attackerPoints = $db->selectSingle($sql, [
                ':attacker' => $mainAttacker,
            ], 'total_points');

            $sql = "SELECT total_points FROM %%STATPOINTS%% a WHERE id_owner = :defender AND stat_type = 1;";
            $defenderPoints = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'total_points');

            $sql = "SELECT onlinetime FROM %%USERS%% WHERE id = :defender;";
            $defenderOnlinetime = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'onlinetime');

            if ($attackerPoints / 10 > $defenderPoints && $defenderOnlinetime > $this->state->runtime->timestamp - INACTIVE) {
                $this->achievement()->setAchievement($mainAttacker, 35);
            }
        }
    }

    /**
     * Check for achievement 38
     * Cornag and Offz: Attack a player with the same alliance
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker     	The id of the User
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement38($mainAttacker, BattleResult $combatResult, $db, $planetDefender)
    {
        if (!$this->achievement()->checkAchievement($mainAttacker, 38) && count($combatResult->startAttackers()) == 1) {
            $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :attacker;";
            $attackerAlly = $db->selectSingle($sql, [
                ':attacker' => $mainAttacker,
            ], 'ally_id');
            $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :defender;";
            $defenderAlly = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'ally_id');
            if ($attackerAlly != 0 && $attackerAlly == $defenderAlly) {
                $this->achievement()->setAchievement($mainAttacker, 38);
            }
        }
    }

    /**
     * Check for achievement 32
     * Privateer: Win 1000 battles with the SetSail theme active
     *
     * @param array						$combatResult       The combat result array
     * @param int	 					$mainAttacker     	The id of the User
     * @param Database	 				$db					The database object
     * @param AdvancedStatsHandler	 	$statsHandler       The stats handler object
     */
    public function checkBattleAchievement32($mainAttacker, BattleResult $combatResult, $db, AdvancedStatsHandler $statsHandler)
    {
        if (($combatResult->won == ATTACKER)) {
            $count = $statsHandler->getValue($mainAttacker, AdvancedStatsEnum::SET_SAIL_WINS);

            if (!$this->achievement()->checkAchievement($mainAttacker, 32) && $count >= 1000) {
                $this->achievement()->setAchievement($mainAttacker, 32);
            }
        }
    }

    /**
     * Check for achievement 3
     * Deserved: Lose atleast 1000 fleet points and atleast 50% of your fleet while your language is set to french
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     * @param Database	 	$db       			The database object
     */
    public function checkBattleAchievement3(BattleResult $combatResult, $planetDefender, $db)
    {
        if (($combatResult->won == ATTACKER && !$this->achievement()->checkAchievement($planetDefender, 3))) {
            //check for loose condition mindestens 1k fleet punkte und mindestens 50%der flotte
            $sql = "SELECT fleet_points FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;";
            $fleet_points = $db->selectSingle($sql, [
                ':id' => $planetDefender,
            ], 'fleet_points');
            $elements = $this->elements();
            $lostFleetPoints = 0;

            foreach ($elements->ids('fleet') as $Fleet) {

                if ($combatResult->startDefenders()[0]->units == 0) {
                    continue;
                }

                $Units = $elements->cost($Fleet)->metalCrystal();

                $lostFleetPoints += $Units / 1000 ;
            }
            $sql = "SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;";
            $lang = $db->selectSingle($sql, [
                ':id' => $planetDefender,
            ], 'lang');
            if ($lostFleetPoints > 1000 && $lostFleetPoints > $fleet_points / 2 && $lang == "fr") {
                $this->achievement()->setAchievement($planetDefender, 3);
            }
        }
    }

    /**
     * Check for achievement 45
     * Locksmith: Win agaisnt a top 5 defense rank player
     *
     * @param int	 		$mainAttacker       The id of the User
     * @param BattleResult		$combatResult       The combat result
     * @param Database	 	$db       			The database object
     * @param int	 		$planetDefender     The id of the User
     */
    public function checkBattleAchievement45(BattleResult $combatResult, $db, $planetDefender)
    {
        $sql = "SELECT defs_rank FROM %%STATPOINTS%% WHERE id_owner = :id AND stat_type = 1;";
        $defs_rank = $db->selectSingle($sql, [
            ':id' => $planetDefender,
        ], 'defs_rank');
        if ($defs_rank <= 5 && $combatResult->won == ATTACKER) {
            $countAttacker = 0;
            $attackers = [];
            foreach ($combatResult->startAttackers() as $fleet) {
                if (!in_array($fleet->userId(), $attackers) && !$this->achievement()->checkAchievement($fleet->userId(), 45)) {
                    $countAttacker++;
                    array_push($attackers, $fleet->userId());
                }
            }
            foreach ($attackers as $attacker) {
                $this->achievement()->setAchievement($attacker, 45);
            }
        }
    }

    /**
     * Check for achievement 43
     * With my Eyes closed: Win as a definder while your account is flagged as inactive, with a fleet that was away for 7 days
     *
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$planetDefender     The id of the User
     * @param Database	 	$db       			The database object
     * @param array<mixed>			$DATA       		The report data array
     */
    public function checkBattleAchievement43(BattleResult $combatResult, $planetDefender, $db, $DATA)
    {
        if ($combatResult->won == DEFENDER) {
            $sql = "SELECT onlinetime FROM %%USERS%% WHERE id = :defender;";
            $onlinetime = $db->selectSingle($sql, [
                ':defender' => $planetDefender,
            ], 'onlinetime');
            if ($onlinetime < $this->state->runtime->timestamp - INACTIVE) {
                $sql = "SELECT count(*) as count FROM %%LOG_FLEETS%% WHERE owner = :defender AND return_time - start_time >= :inactive AND return_time < :timestamp AND
					start_galaxy = :start_galaxy AND start_system = :start_system AND start_planet = :start_planet AND start_type = :start_type;";
                $count = $db->selectSingle($sql, [
                    ':defender' => $planetDefender,
                    ':inactive' => INACTIVE,
                    ':timestamp' => $this->state->runtime->timestamp,
                    ':start_galaxy' => $DATA['koords'][0],
                    ':start_system' => $DATA['koords'][1],
                    ':start_planet' => $DATA['koords'][2],
                    ':start_type' => $DATA['koords'][3],
                ], 'count');

                if ($count > 0 && !$this->achievement()->checkAchievement($planetDefender, 43)) {
                    $this->achievement()->setAchievement($planetDefender, 43);
                }
            }
        }
    }

    /**
     * Check for achievement 7
     * French revolution: Win against pirates or aliens with atleast 10.000 ships
     *
     * @param array<string, mixed>			$reportInfo       	The report array
     * @param BattleResult		$combatResult       The combat result
     * @param int	 		$mainAttacker       The id of the User
     */
    public function checkBattleAchievement7($reportInfo, BattleResult $combatResult, $mainAttacker)
    {
        if ($reportInfo['thisFleet']['ship_amount'] >= 10000 && !$this->achievement()->checkAchievement($mainAttacker, 7)
            && $combatResult->won == ATTACKER) {
            $this->achievement()->setAchievement($mainAttacker, 7);
        }
    }

    /**
     * Achievements of the planet owner: 70 (ghost town), 73 (counterintelligence) and 50 (satellites).
     *
     * @param array<string, mixed> $reportInfo report array
     * @param BattleFleet $planet the planet as it was before the fight
     * @param BattleFleet|null $planetAfter the planet after the fight (null when gone)
     * @param array<int|string, BattleFleet> $attackers surviving attacking fleets
     */
    private function checkDefenderLossAchievements(array $reportInfo, BattleResult $combatResult, BattleFleet $planet, ?BattleFleet $planetAfter, array $attackers): void
    {
        $owner = (int) $planet->userId();

        if ($planet->unitCount() == 0 && array_sum($reportInfo['stealResource']) == 0) {
            $this->grantAll([$owner], 70);
        }

        $probes = 0;
        foreach ($combatResult->startAttackers() as $fleet) {
            $probes += $fleet->units[SHIP_PROBE] ?? 0;
        }
        foreach ($attackers as $fleet) {
            $probes -= $fleet->units[SHIP_PROBE] ?? 0;
        }
        if ($probes >= 100) {
            $this->grantAll([$owner], 73);
        }

        $satellites = $planet->units[SHIP_SOLSAT] ?? 0;
        if ($satellites > 0 && ($planetAfter === null || ($planetAfter->units[SHIP_SOLSAT] ?? 0) == 0)
            && ($planet->player['graviton_tech'] ?? 0) == 0) {
            $this->checkBattleAchievement50($owner, $reportInfo['thisFleet']['end_id']);
        }
    }

    /**
     * Sleepless and in a bad mood: 5 of your attacks land between 00:00 and 06:00 in the same night and none of them is won.
     * The per-night state is kept on the user (night_attacks_date = yyyymmdd of the night, night_attacks = attacks
     * without a win, -1 once an attack of that night was won).
     *
     * @param array<string, mixed> $fleet arriving fleet row
     * @param list<int> $ids attacking user ids
     */
    public function checkBattleAchievement1(array $fleet, BattleResult $combatResult, array $ids): void
    {
        $arrival = (int) $fleet['arrival_time'];
        if ((int) date('G', $arrival) >= 6) {
            return;
        }

        $night = (int) date('Ymd', $arrival);
        $won = $combatResult->won == ATTACKER;
        foreach ($ids as $userId) {
            if ($this->achievement()->checkAchievement($userId, 1)) {
                continue;
            }
            $row = $this->db->selectSingle('SELECT `night_attacks_date`, `night_attacks` FROM %%USERS%% WHERE `id` = :id;', [':id' => $userId]);
            $count = (int) $row['night_attacks_date'] == $night ? (int) $row['night_attacks'] : 0;
            $count = ($won || $count < 0) ? -1 : $count + 1;
            $this->db->update('UPDATE %%USERS%% SET `night_attacks_date` = :night, `night_attacks` = :count WHERE `id` = :id;', [
                ':night' => $night,
                ':count' => $count,
                ':id' => $userId,
            ]);
            if ($count >= 5) {
                $this->achievement()->setAchievement($userId, 1);
            }
        }
    }

    /**
     * Communist Manifesto: an ACS attack with 5 different players wins against a defender (the planet owner) with more
     * total points than each attacker. All attackers get it.
     *
     * @param list<int> $ids attacking user ids
     */
    public function checkBattleAchievement29(BattleResult $combatResult, array $ids): void
    {
        $open = array_filter($ids, fn($id) => !$this->achievement()->checkAchievement($id, 29));
        if ($open === []) {
            return;
        }

        $defender = (int) $combatResult->startDefenders()[0]->userId();
        $list = implode(',', array_map('intval', array_merge($ids, [$defender])));
        $rows = $this->db->select("SELECT id_owner, total_points FROM %%STATPOINTS%% WHERE stat_type = 1 AND id_owner IN ($list);");
        $points = [];
        foreach ($rows as $row) {
            $points[(int) $row['id_owner']] = (float) $row['total_points'];
        }
        $defenderPoints = $points[$defender] ?? 0;
        foreach ($ids as $id) {
            if (($points[$id] ?? 0) >= $defenderPoints) {
                return;
            }
        }
        $this->grantAll($open, 29);
    }

    /**
     * Satellites: you lose all solar satellites on a planet in a fight, the planet's energy before the fight was at
     * least the energy cost of the graviton technology and you have no graviton technology (checked by the caller).
     *
     * @param int $owner planet owner
     * @param int|string $planetId id of the planet that was attacked
     */
    public function checkBattleAchievement50(int $owner, int|string $planetId): void
    {
        $energy = $this->db->selectSingle('SELECT `energy` FROM %%PLANETS%% WHERE `id` = :id;', [':id' => $planetId], 'energy');
        if ($energy >= $this->elements()->cost(GRAVITON_TECH)->energy) {
            $this->grantAll([$owner], 50);
        }
    }

    /**
     * David vs Goliath: win a defense against attackers whose combined fleet value is at least 10x your own fleet and
     * defense value in the fight (minimum attacker value 1,000,000; value = metal + crystal).
     *
     * @param array<int|string, BattleFleet> $startAttackers attackers before the fight
     * @param array<int|string, BattleFleet> $startDefenders defenders before the fight
     */
    public function checkBattleAchievement67(array $startAttackers, array $startDefenders): void
    {
        $attackerValue = $this->fleetValue($startAttackers);
        if ($attackerValue < 1000000) {
            return;
        }

        $own = [];
        foreach ($startDefenders as $fleet) {
            $own[(int) $fleet->userId()] = ($own[(int) $fleet->userId()] ?? 0) + $this->fleetValue([$fleet]);
        }
        foreach ($own as $userId => $value) {
            if ($userId > 0 && $attackerValue >= 10 * $value) {
                $this->grantAll([$userId], 67);
            }
        }
    }

    /**
     * High noon: two of your attacks (not ACS) land on the same target planet in the same second. Checked when one of
     * them lands: the other one is still in the fleet log with the same arrival time.
     *
     * @param array<string, mixed> $fleet arriving fleet row
     * @param list<int> $ids attacking user ids
     */
    public function checkBattleAchievement87(array $fleet, array $ids): void
    {
        $owner = (int) $fleet['owner'];
        if ($this->achievement()->checkAchievement($owner, 87)) {
            return;
        }
        $count = $this->db->selectSingle("SELECT COUNT(*) AS amount FROM %%LOG_FLEETS%%
            WHERE `owner` = :owner AND `end_id` = :target AND `arrival_time` = :arrival AND `group` = '0'
            AND `mission` IN (:attack, :destruction) AND `hasCanceled` = 0;", [
            ':owner' => $owner,
            ':target' => $fleet['end_id'],
            ':arrival' => $fleet['arrival_time'],
            ':attack' => Mission::Attack->value,
            ':destruction' => Mission::Destruction->value,
        ], 'amount');
        if ($count >= 2) {
            $this->achievement()->setAchievement($owner, 87);
        }
    }

    /**
     * Achievements of the battle hall: Atleast Battlehall (42), 4 battle hall entries where you were on the losing
     * side. Called after the fight was entered into the battle hall.
     */
    public function checkBattleHallAchievements(BattleResult $combatResult): void
    {
        if ($combatResult->won == DRAW) {
            return;
        }
        $losers = $combatResult->won == ATTACKER ? $this->defenderIds($combatResult) : $this->attackerIds($combatResult);
        foreach ($losers as $userId) {
            if ($this->achievement()->checkAchievement($userId, 42)) {
                continue;
            }
            $count = $this->db->selectSingle("SELECT COUNT(DISTINCT t.rid) AS amount FROM %%TOPKB_USERS%% u
                INNER JOIN %%TOPKB%% t ON t.rid = u.rid
                WHERE u.uid = :uid AND ((u.role = 1 AND t.result = :defender) OR (u.role = 2 AND t.result = :attacker));", [
                ':uid' => $userId,
                ':defender' => DEFENDER,
                ':attacker' => ATTACKER,
            ], 'amount');
            if ($count >= 4) {
                $this->achievement()->setAchievement($userId, 42);
            }
        }
    }

    /**
     * Pure hatred (46) and Fireworks (72) for an interplanetary missile strike.
     * 46: the strike pushes the bash counter of the attacker on the target (user or planet limit, interplanetary
     * missiles counted by the universe) exactly to the limit while the target is active.
     * 72: the strike destroys at least 100 defense units.
     *
     * @param array<string, mixed> $fleet missile fleet row
     * @param int $destroyed defense units destroyed by the strike
     */
    public function checkMissileAchievements(array $fleet, int $destroyed): void
    {
        $owner = (int) $fleet['owner'];
        if ($destroyed >= 100 && !$this->achievement()->checkAchievement($owner, 72)) {
            $this->achievement()->setAchievement($owner, 72);
        }

        $config = $this->configs->get($fleet['universe']);
        if ($this->achievement()->checkAchievement($owner, 46) || !$config->bash_count_missiles
            || ($config->bash_per_user == 0 && $config->bash_per_planet == 0)) {
            return;
        }

        $reached = false;
        if ($config->bash_per_user != 0) {
            $reached = $this->bashAttackCount($fleet, 'target_owner', $fleet['target_owner']) == $config->bash_per_user;
        }
        if (!$reached && $config->bash_per_planet != 0) {
            $reached = $this->bashAttackCount($fleet, 'end_id', $fleet['end_id']) == $config->bash_per_planet;
        }
        if (!$reached) {
            return;
        }

        $online = $this->db->selectSingle('SELECT `onlinetime` FROM %%USERS%% WHERE `id` = :id;', [':id' => $fleet['target_owner']], 'onlinetime');
        if ($online > $fleet['arrival_time'] - INACTIVE) {
            $this->achievement()->setAchievement($owner, 46);
        }
    }

    /**
     * Attacks of the fleet owner counted by the bash rules (solo flights plus distinct ACS groups, espionage-probe-only
     * fleets excluded) within the bash time before the landing of the fleet, the fleet itself included.
     *
     * @param array<string, mixed> $fleet fleet row
     * @param string $column log column of the target, `target_owner` or `end_id`
     */
    private function bashAttackCount(array $fleet, string $column, int|string $value): int
    {
        $missions = implode(',', [Mission::Attack->value, Mission::Acs->value, Mission::Destruction->value, Mission::Missile->value]);
        $sql = "SELECT COUNT(CASE WHEN `group` = '0' THEN 1 END) + COUNT(DISTINCT CASE WHEN `group` <> '0' THEN `group` END) AS amount
            FROM %%LOG_FLEETS%%
            WHERE `owner` = :owner AND `$column` = :value AND `arrival_time` > :from AND `arrival_time` <= :until
            AND `mission` IN ($missions) AND `hasCanceled` = 0 AND NOT (`ships` REGEXP '^210,[0-9]+;?$');";

        return (int) $this->db->selectSingle($sql, [
            ':owner' => $fleet['owner'],
            ':value' => $value,
            ':from' => $fleet['arrival_time'] - BASH_TIME,
            ':until' => $fleet['arrival_time'],
        ], 'amount');
    }

    /**
     * Lucky shot (71): a moon is created from a fight whose moon chance was 1 %. Granted to the attackers.
     *
     * @param list<int|string> $attackerIds attacking user ids
     * @param int|float $chance moon chance of the fight in percent (incl. the cascading bonus)
     */
    public function checkMoonAchievements(array $attackerIds, int|float $chance): void
    {
        if ($chance == 1) {
            $this->grantAll(array_map('intval', $attackerIds), 71);
        }
    }

    /**
     * Alien hunter (82): win an expedition fight against a large alien fleet (the caller selects the encounter).
     */
    public function checkAlienHunter(int $userId, BattleResult $combatResult): void
    {
        if ($combatResult->won == ATTACKER) {
            $this->grantAll([$userId], 82);
        }
    }

    /**
     * Unlocks an achievement for every user that does not have it yet.
     *
     * @param array<int|string> $userIds user ids (0 = NPC is skipped)
     */
    private function grantAll(array $userIds, int $achievementId, bool $condition = true): void
    {
        if (!$condition) {
            return;
        }
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0 && !$this->achievement()->checkAchievement($userId, $achievementId)) {
                $this->achievement()->setAchievement($userId, $achievementId);
            }
        }
    }

    /**
     * Distinct ids of the attacking users.
     *
     * @return list<int>
     */
    private function attackerIds(BattleResult $combatResult): array
    {
        return $this->userIdsOf($combatResult->startAttackers());
    }

    /**
     * Distinct ids of the defending users (planet owner and fleets holding at the planet).
     *
     * @return list<int>
     */
    private function defenderIds(BattleResult $combatResult): array
    {
        return $this->userIdsOf($combatResult->startDefenders());
    }

    /**
     * @param array<int|string, BattleFleet> $fleets
     * @return list<int>
     */
    private function userIdsOf(array $fleets): array
    {
        $ids = [];
        foreach ($fleets as $fleet) {
            if ((int) $fleet->userId() > 0) {
                $ids[(int) $fleet->userId()] = (int) $fleet->userId();
            }
        }

        return array_values($ids);
    }

    /**
     * Number of units (ships and defense) in the fleets.
     *
     * @param array<int|string, BattleFleet> $fleets
     */
    private function unitTotal(array $fleets): int|float
    {
        $total = 0;
        foreach ($fleets as $fleet) {
            $total += $fleet->unitCount();
        }

        return $total;
    }

    /**
     * Value (metal + crystal) of the units of the fleets.
     *
     * @param array<int|string, BattleFleet> $fleets
     */
    private function fleetValue(array $fleets): int|float
    {
        $elements = $this->elements();
        $value = 0;
        foreach ($fleets as $fleet) {
            foreach ($fleet->units as $unitId => $amount) {
                $value += $amount * $elements->cost((int) $unitId)->metalCrystal();
            }
        }

        return $value;
    }
}
