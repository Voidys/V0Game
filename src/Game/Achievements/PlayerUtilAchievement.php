<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Achievements;

use App\Game\Core\Achievement;
use App\Game\Core\StoredData;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Statbuilder;

/**
 * Achievement rules checked while players and planets are created, deleted or renamed (galaxy position of the
 * home planet, destroyed colonies/moons, names, ...); called from the Core\Player services.
 */
class PlayerUtilAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievements in relation to Planet deletion
     *
     * @param array<mixed>			$planetData       		The data of the planet
     * @param int	 		$planetId       		The id of the planet
     */
    public function checkPlayerUtilAchievementsDeletion($planetData, $planetId)
    {
        $db = $this->db;
        $this->checkPlayerUtilAchievements26($planetData, $db, $planetId);
        $this->checkPlayerUtilAchievements49($planetData, $db, $planetId);
    }

    /**
     * Check if a player completed the prerequisites for the achievement 40
     * Columbus: Colonize half a Universe away from your Home Planet
     *
     * @param int     		$userID       		The id of the User
     * @param int     		$hpGala       		The home planet galaxy
     * @param int     		$galaxy       		The galaxy of the planet
     * @param Config     	$config       		The config of the game
     * @param boolean    	$isHome       		Is the planet a home planet
     */
    public function checkPlayerUtilAchievements40($isHome, $hpGala, $userId, $galaxy, $config)
    {
        if (!$isHome && !$this->achievement()->checkAchievement($userId, 40) && $hpGala != $galaxy) {
            $max_gala_distance = floor($config->max_galaxy / 2);
            $uniType = $config->uni_type;
            if ($uniType == UNIVERSE_LINEAR) {
                if (($hpGala > $galaxy && $hpGala - $galaxy > $max_gala_distance) || ($hpGala < $galaxy && $galaxy - $hpGala > $max_gala_distance)) {
                    $this->achievement()->setAchievement($userId, 40);
                }
            } elseif ($uniType == UNIVERSE_CIRCULAR) {
                $max_distance = $this->fleetFunctions()->getTargetDistance(
                    [1,1,1],
                    [1 + $max_gala_distance,1,1],
                );
                $distance = $this->fleetFunctions()->getTargetDistance(
                    [$hpGala,1,1],
                    [$galaxy,1,1],
                );
                if ($max_distance <= $distance) {
                    $this->achievement()->setAchievement($userId, 40);
                }
            } elseif ($uniType == UNIVERSE_BALL) {
                $this->achievement()->setAchievement($userId, 40);
            }
        }
    }

    /**
     * Check if a player completed the prerequisites for the achievement 26
     * Everything for the achievements: Delete your biggest planet pointwise
     *
     * @param array<mixed>    		$planetData       		The data of the planet
     * @param Database    	$db       				The database object
     * @param int     		$planetId       		The id of the planet
     */
    public function checkPlayerUtilAchievements26($planetData, $db, $planetId)
    {
        if (!$this->achievement()->checkAchievement($planetData['id_owner'], 26)) {
            $statbuidler = new Statbuilder($this->db, $this->configs, $this->state);
            $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :userId and destruyed = 0;";
            $planets = $db->select($sql, [
                ':userId'   => $planetData['id_owner'],
            ]);
            $biggestPlanet = 0;
            $biggestPlanetPoints = 0;
            foreach ($planets as $planet) {
                $points = $statbuidler->getBuildingScoreByPlanet($planet);
                if ($points['points'] > $biggestPlanetPoints) {
                    $biggestPlanet = $planet['id'];
                    $biggestPlanetPoints = $points['points'];
                }
            }
            if ($biggestPlanet == $planetId) {
                $this->achievement()->setAchievement($planetData['id_owner'], 26);
            }
        }
    }

    /**
     * Check if a player completed the prerequisites for the achievement 49
     * We're blasting off again: Delete a planet within 60 seconds
     *
     * @param array<mixed>    		$planetData       		The data of the planet
     * @param Database    	$db       				The database object
     * @param int     		$planetId       		The id of the planet
     */
    public function checkPlayerUtilAchievements49($planetData, $db, $planetId)
    {
        if (!$this->achievement()->checkAchievement($planetData['id_owner'], 49)) {
            $sql = "SELECT creation_time FROM %%PLANETS%% WHERE id = :planetID;";
            $planet_creation_time = $db->selectSingle($sql, [
                ':planetID'   => $planetId,
            ], 'creation_time');
            if ($this->state->runtime->timestamp - $planet_creation_time <= 60) {
                $this->achievement()->setAchievement($planetData['id_owner'], 49);
            }
        }
    }

    /**
     * Check if a player completed the prerequisites for the achievement 36
     * Miesernachtsmann: Get an insulting message
     *
     * @param int	 		$userId       		The id of the User
     * @param string    	$text       		The text of the message
     */
    public function checkPlayerUtilAchievements36($userId, $text)
    {
        if (!$this->achievement()->checkAchievement($userId, 36)) {
            $targets = ['hurensohn', 'gleichstarke ziele','gegen schwächere', 'ehrenlos'];
            $find = false;
            foreach ($targets as $t) {
                if (str_contains(strtolower($text), $t) !== false) {
                    $find = true;
                    break;
                }
            }
            if ($find) {
                $this->achievement()->setAchievement($userId, 36);
            }
        }
    }

    /**
     * Check if a player completed the prerequisites for the achievement 33
     * I can stop whenever I want: Return from vacation mode
     *
     * @param int	 		$userId       		The id of the User
     */
    public function checkPlayerUtilAchievements33($userId)
    {
        if (!$this->achievement()->checkAchievement($userId, 33)) {
            $this->achievement()->setAchievement($userId, 33);
        }
    }

    /**
     * Check if a player completed the prerequisites for the achievement 31
     * Off on vacation: Shoot someone into vacation mode
     *
     * @param int	 		$userId       		The id of the User
     */
    public function checkPlayerUtilAchievements31($userID)
    {
        $db = $this->db;
        $sql = "SELECT raport FROM %%RW%% 
			WHERE (defender LIKE CONCAT(:user, ',%') OR defender LIKE CONCAT('%,', :user, ',%')
				OR defender LIKE CONCAT('%,', :user) OR defender = :user)
			AND `time` > :sixHours;";
        $attacks = $db->select($sql, [
            ':user'			=> $userID,
            ':sixHours'		=> $this->state->runtime->timestamp - TIME_6_HOURS,
        ]);
        if ($attacks) {
            foreach ($attacks as $attack) {
                $combatReport = StoredData::tryDecode($attack['raport']);

                if (($combatReport['result'] ?? null) != 'a') {
                    continue;
                }
                foreach ($combatReport['rounds'][0]['attacker'] as $attacker) {
                    if (!$this->achievement()->checkAchievement($attacker['userID'], 31)) {
                        $this->achievement()->setAchievement($attacker['userID'], 31);
                    }
                }
            }
        }
        $sql = "SELECT raport FROM %%RW%% 
			WHERE (attacker LIKE CONCAT(:user, ',%') OR attacker LIKE CONCAT('%,', :user, ',%')
				OR attacker LIKE CONCAT('%,', :user) OR attacker = :user) 
			AND `time` > :sixHours;";
        $attacks = $db->select($sql, [
            ':user'			=> $userID,
            ':sixHours'		=> $this->state->runtime->timestamp - TIME_6_HOURS,
        ]);
        if ($attacks) {
            foreach ($attacks as $attack) {
                $combatReport = StoredData::tryDecode($attack['raport']);
                if (($combatReport['result'] ?? null) != 'r') {
                    continue;
                }
                foreach ($combatReport['rounds'][0]['defender'] as $defender) {
                    if (!$this->achievement()->checkAchievement($defender['userID'], 31)) {
                        $this->achievement()->setAchievement($defender['userID'], 31);
                    }
                }
            }
        }
    }
}
