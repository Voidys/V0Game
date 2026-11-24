<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Achievements;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Achievement;

/**
 * Achievement rules not tied to fights, queues or planets: expedition counts and black holes, recycling,
 * galaxy view, settings and shipyard milestones. Called from the missions/pages that produce the event.
 */
class MiscAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievement in relation to Expeditions
     * Slippy's blessing(12): Lose your first expedition to a black hole
     * Slippy's curse(14): Lose 10 expeditions to black holes
     *
     * @param int	 		$userId       	The id of the User
     */
    public function checkExpoAchievements($userId)
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $expo_count = $statsHandler->getValue($userId, AdvancedStatsEnum::EXPO_COUNT);
        $black_hole_count = $statsHandler->getValue($userId, AdvancedStatsEnum::EXPO_BLACK_HOLE);

        if ($expo_count == 0 && $black_hole_count == 1 && !$this->achievement()->checkAchievement($userId, 12)) {
            $this->achievement()->setAchievement($userId, 12);
        } elseif ($black_hole_count == 10 && !$this->achievement()->checkAchievement($userId, 14)) {
            $this->achievement()->setAchievement($userId, 14);
        }
    }

    /**
     * Check for achievement 16
     * Dude, Where's My Debris?: Have 250+ Recyclers return with no debris
     *
     * @param int	 		$userId       		The id of the User
     * @param array<mixed>			$collectedGoods     The resource array
     * @param int	 		$recCount       	The number of recyclers
     */
    public function checkRecAchievements($userId, $collectedGoods, $recCount)
    {
        if (!$this->achievement()->checkAchievement($userId, 16) && $collectedGoods[RESOURCE_METAL] == 0 && $collectedGoods[RESOURCE_CRYSTAL] == 0 && $recCount >= 250) {
            $this->achievement()->setAchievement($userId, 16);
        }
    }

    /**
     * Check for achievement 51
     * No clue, it's just there?: Drain your deuterium with the galaxy view
     *
     * @param int	 		$userId       		The id of the User
     */
    public function checkGalaxyAchievements($userId)
    {
        if (!$this->achievement()->checkAchievement($userId, 51)) {
            $this->achievement()->setAchievement($userId, 51);
        }
    }

    /**
     * Check for achievement 25
     * GDPR breach: Opt in to records sharing
     *
     * @param int	 		$userId       		The id of the User
     * @param int	 		$recordsOptIn       The records opt in
     */
    public function checkSettingsAchievements($userId, $recordsOptIn)
    {
        if (!$this->achievement()->checkAchievement($userId, 25) && $recordsOptIn == 1) {
            $this->achievement()->setAchievement($userId, 25);
        }
    }

    /**
     * Check for achievement 17
     * Overlord Defense: Build 5000 missile launchers in one go
     *
     * @param int	 		$userId       		The id of the User
     * @param int	 		$count       		The number of missile launchers
     * @param int	 		$element       		The id of the element
     */
    public function checkShipyardAchievements($userId, $count, $element)
    {
        if (!$this->achievement()->checkAchievement($userId, 17) && $count >= 5000 && $element == MISSILE_LAUNCHER) {
            $this->achievement()->setAchievement($userId, 17);
        }
    }

    /**
     * Whether a counter write can change one of the counter achievements (74-78, 80, 81, 83); lets the stats handler
     * skip every other key without any query.
     */
    public static function watchesStat(AdvancedStatsEnum $key): bool
    {
        return self::statRule($key) !== null;
    }

    /**
     * Achievement rule of a counter: [achievement id, counters summed, threshold], null when no achievement uses it.
     *
     * @return array{0: int, 1: list<AdvancedStatsEnum>, 2: int}|null
     */
    private static function statRule(AdvancedStatsEnum $key): ?array
    {
        $name = $key->name;
        return match (true) {
            str_starts_with($name, 'RAIDED_') => [74, [AdvancedStatsEnum::RAIDED_METAL, AdvancedStatsEnum::RAIDED_CRYSTAL, AdvancedStatsEnum::RAIDED_DEUTERIUM], 1000000000],
            str_starts_with($name, 'RECYCLED_') => [75, [AdvancedStatsEnum::RECYCLED_METAL, AdvancedStatsEnum::RECYCLED_CRYSTAL], 1000000000],
            str_starts_with($name, 'REPAIRED_2') => [76, array_values(array_filter(AdvancedStatsEnum::cases(), static fn(AdvancedStatsEnum $case): bool => str_starts_with($case->name, 'REPAIRED_2'))), 1000],
            $key === AdvancedStatsEnum::HONORPOINTS => [77, [AdvancedStatsEnum::HONORPOINTS], 1000],
            $key === AdvancedStatsEnum::OUTLAW_ACTIVATIONS => [78, [AdvancedStatsEnum::OUTLAW_ACTIVATIONS], 1],
            str_starts_with($name, 'FIRST_EXPO_') => [80, self::firstExpoKeys(), count(self::firstExpoKeys())],
            $key === AdvancedStatsEnum::EXPO_COUNT => [81, [AdvancedStatsEnum::EXPO_COUNT], 1000],
            $key === AdvancedStatsEnum::EXPO_RES_LARGE => [83, [AdvancedStatsEnum::EXPO_RES_LARGE], 50],
            default => null,
        };
    }

    /**
     * The "first time" markers of every expedition outcome.
     *
     * @return list<AdvancedStatsEnum>
     */
    private static function firstExpoKeys(): array
    {
        return array_values(array_filter(AdvancedStatsEnum::cases(), static fn(AdvancedStatsEnum $case): bool => str_starts_with($case->name, 'FIRST_EXPO_')));
    }

    /**
     * Counter achievements, called by AdvancedStatsHandler after a counter changed:
     * Pirate king(74): 1,000,000,000 resources raided in total
     * Space janitor(75): 1,000,000,000 resources recycled in total
     * Wreck diver(76): 1000 ships (REPAIRED_2xx, not rebuilt defense) repaired from wreck fields in total
     * Honourable(77): 1000 honour points
     * Wanted(78): become an outlaw for the first time
     * Seen it all(80): every expedition outcome at least once
     * Old salt(81): 1000 expeditions
     * Treasure hunter(83): 50 large resource finds on expeditions
     * Cost: nothing for other counters, one lookup when held, else one summed query.
     *
     * @param int $userId the player whose counter changed
     */
    public function checkStatAchievements(int $userId, AdvancedStatsEnum $key): void
    {
        $rule = self::statRule($key);
        if ($rule === null || $this->achievement()->checkAchievement($userId, $rule[0])) {
            return;
        }

        if ($rule[0] === 80) {
            // the first-time markers hold a timestamp: count the markers that exist instead of summing them
            $sum = (int) $this->db->selectSingle(
                'SELECT COUNT(*) AS c FROM %%ADVANCED_STATS%% WHERE userId = :userId AND statkey LIKE \'FIRST\\_EXPO\\_%\';',
                [':userId' => $userId],
                'c',
            );
        } else {
            $sum = array_sum((new AdvancedStatsHandler($this->db, $this->configs, $this->state))->getSummedStats([$userId], $rule[1]));
        }
        if ($sum >= $rule[2]) {
            $this->achievement()->setAchievement($userId, $rule[0]);
        }
    }

    /**
     * Einstein(53): top level (at least level 10) in the records for at least 5 researches at the same time. Called after the records build.
     */
    public function checkRecordAchievements(): void
    {
        $techIds = $this->elements()->ids('tech');
        if ($techIds === []) {
            return;
        }

        $holders = $this->db->fetchAll(
            $this->db->qb()
                ->select('r.userID')
                ->from($this->db->table('RECORDS'), 'r')
                ->leftJoin('r', $this->db->table('USERS_TO_ACHIEVEMENTS'), 'a', 'a.userID = r.userID AND a.achievementID = 53')
                ->where('r.elementID IN (:ids)')
                ->andWhere('r.level >= 10')
                ->andWhere('a.userID IS NULL')
                ->groupBy('r.userID')
                ->having('COUNT(DISTINCT r.elementID) >= 5')
                ->setParameter('ids', $techIds, \Doctrine\DBAL\ArrayParameterType::INTEGER),
        );
        foreach ($holders as $row) {
            $this->achievement()->setAchievement((int) $row['userID'], 53);
        }
    }

    /**
     * Galactic emperor(93): rank 1 in total points in every statistics snapshot of the last 7 days (at least 7 distinct
     * days covered). Called by the daily cronjob; only the current rank-1 players are examined (indexed by owner).
     */
    public function checkEmperorAchievements(): void
    {
        $leaders = $this->db->select('SELECT id_owner FROM %%STATPOINTS%% WHERE stat_type = 1 AND total_rank = 1;');
        $now = $this->state->runtime->timestamp;
        foreach ($leaders as $leader) {
            $userId = (int) $leader['id_owner'];
            if ($this->achievement()->checkAchievement($userId, 93)) {
                continue;
            }

            $row = $this->db->selectSingle(
                'SELECT MAX(total_rank) AS worst, COUNT(DISTINCT FLOOR(`timestamp` / 86400)) AS days FROM %%STATPOINTS_HISTORY%%
                    WHERE id_owner = :userId AND stat_type = 1 AND `timestamp` >= :since;',
                [':userId' => $userId, ':since' => $now - 7 * 86400],
            );
            if (!empty($row) && (int) $row['worst'] === 1 && (int) $row['days'] >= 7) {
                $this->achievement()->setAchievement($userId, 93);
            }
        }
    }

    /**
     * Graduate(89): finish the tutorial (the last step is claimed).
     */
    public function checkTutorialAchievement(int $userId): void
    {
        if (!$this->achievement()->checkAchievement($userId, 89)) {
            $this->achievement()->setAchievement($userId, 89);
        }
    }

    /**
     * Diplomat(90): active pacts (accepted treaties that are not war or peace, not expired) with 3 alliances of at least 5 members at once,
     * granted to all members of the alliance.
     *
     * @param int $allianceId alliance whose pacts changed
     */
    public function checkDiplomacyAchievements(int $allianceId): void
    {
        $pacts = (int) $this->db->selectSingle(
            'SELECT COUNT(DISTINCT IF(owner_1 = :alliance, owner_2, owner_1)) AS c FROM %%DIPLO%% d
                WHERE (owner_1 = :alliance OR owner_2 = :alliance) AND accept = 1 AND `level` NOT IN (:war, :peace)
                AND (expires_at IS NULL OR expires_at > :now)
                AND (SELECT COUNT(*) FROM %%USERS%% m WHERE m.ally_id = IF(d.owner_1 = :alliance, d.owner_2, d.owner_1)) >= 5;',
            [
                ':alliance' => $allianceId,
                ':war' => \App\Game\Enum\DiploType::War->value,
                ':peace' => \App\Game\Enum\DiploType::Peace->value,
                ':now' => $this->state->runtime->timestamp,
            ],
            'c',
        );
        if ($pacts < 3) {
            return;
        }

        foreach ($this->db->select('SELECT id FROM %%USERS%% WHERE ally_id = :alliance;', [':alliance' => $allianceId]) as $member) {
            if (!$this->achievement()->checkAchievement((int) $member['id'], 90)) {
                $this->achievement()->setAchievement((int) $member['id'], 90);
            }
        }
    }

    /**
     * Chronicler(91): created 5 alliance wiki pages (counter ALLIANCE_PAGES_CREATED, deleting pages does not reduce it).
     */
    public function checkWikiAchievements(int $userId): void
    {
        if ($this->achievement()->checkAchievement($userId, 91)) {
            return;
        }

        $handler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        if ($handler->getValue($userId, AdvancedStatsEnum::ALLIANCE_PAGES_CREATED) >= 5) {
            $this->achievement()->setAchievement($userId, 91);
        }
    }

    /**
     * Pen pal(92): member of 10 accepted buddy pairs (a BUDDY row without a pending request) with partners that are not linked accounts.
     */
    public function checkBuddyAchievements(int $userId): void
    {
        if ($this->achievement()->checkAchievement($userId, 92)) {
            return;
        }

        $partners = $this->db->select(
            'SELECT IF(b.sender = :userId, b.owner, b.sender) AS partner FROM %%BUDDY%% b LEFT JOIN %%BUDDY_REQUEST%% r ON r.id = b.id
                WHERE r.id IS NULL AND (b.sender = :userId OR b.owner = :userId);',
            [':userId' => $userId],
        );
        $pairs = 0;
        $links = new \App\Game\Core\AccountLinks($this->db);
        foreach ($partners as $row) {
            if (!$links->linked($userId, (int) $row['partner'], false) && ++$pairs >= 10) {
                break;
            }
        }
        if ($pairs >= 10) {
            $this->achievement()->setAchievement($userId, 92);
        }
    }
}
