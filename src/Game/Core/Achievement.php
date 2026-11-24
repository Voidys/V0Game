<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Core;

/**
 * Storage of unlocked achievements (tables achievements, users_to_achievements). The rules that unlock them are
 * in App\Game\Achievements\*; this class only reads the catalog and records unlocks.
 */
class Achievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Return all Achievements unlocked by a player.
     *
     * @param int     	$userID       		The id of the User
     *
     * @return array<mixed>
     */
    public function getUserAchievements(int $userID): array
    {
        $db = $this->db;
        $sql = "SELECT * FROM %%USERS_TO_ACHIEVEMENTS%% WHERE userID = :userID;";
        $achievementList = $db->select($sql, [
            ':userID'	=> $userID,
        ]);
        return $achievementList;
    }

    /**
     * Return all Achievements.
     *
     * @return array<mixed>
     */
    public function getAchievementList(): array
    {
        $db = $this->db;
        $sql = "SELECT * FROM %%ACHIEVEMENTS%%;";
        $achievementList = $db->select($sql);
        return $achievementList;
    }

    /**
     * Unlock a specific achievement for a User.
     *
     * @param int     		$userID       		The id of the User
     * @param int      		$achievementID     	The id of the Achievement
     *
     */
    public function setAchievement(int $userID, int $achievementID): void
    {
        $db = $this->db;
        $sql = "INSERT IGNORE INTO %%USERS_TO_ACHIEVEMENTS%% SET userID = :userID, achievementID = :achievementID, date = :date;";
        $db->insert($sql, [
            ':userID'		=> $userID,
            ':achievementID' => $achievementID,
            ':date'			=> $this->state->runtime->timestamp,
        ]);

        // Completionist(96): 50 other achievements; 96 itself does not count (and setting it again is a no-op insert)
        if ($achievementID !== 96 && $this->db->rowCount() > 0) {
            $this->achievementGuard()->run(function () use ($db, $userID): void {
                $held = (int) $db->selectSingle(
                    "SELECT COUNT(*) AS c FROM %%USERS_TO_ACHIEVEMENTS%% WHERE userID = :userID AND achievementID != 96;",
                    [':userID' => $userID],
                    'c',
                );
                if ($held >= 50) {
                    $this->setAchievement($userID, 96);
                }
            });
        }
    }

    /**
     * Take back an achievement from a User (admin revoke). Returns whether the user had it.
     *
     * @param int $userID        The id of the User
     * @param int $achievementID The id of the Achievement
     */
    public function removeAchievement(int $userID, int $achievementID): bool
    {
        $sql = "DELETE FROM %%USERS_TO_ACHIEVEMENTS%% WHERE userID = :userID AND achievementID = :achievementID;";
        $this->db->delete($sql, [
            ':userID'        => $userID,
            ':achievementID' => $achievementID,
        ]);

        return $this->db->rowCount() > 0;
    }

    /**
     * Check if a User has a specific Achievement unlocked.
     *
     * @param int     	$userID       		The id of the User
     * @param int      	$achievementID     	The id of the Achievement
     *
     * @return bool
     */
    public function checkAchievement(int $userID, int $achievementID): bool
    {
        $db = $this->db;
        $sql = "SELECT * FROM %%USERS_TO_ACHIEVEMENTS%% WHERE userID = :userID AND achievementID = :achievementID;";
        $achievementList = $db->selectSingle($sql, [
            ':userID'		=> $userID,
            ':achievementID' => $achievementID,
        ]);
        return !empty($achievementList);
    }
}
