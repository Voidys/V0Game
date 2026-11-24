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
 * Achievement rules for what a planet or player has built: research levels, built ships and moon storage levels;
 * called from ResourcePersistence::savePlanet() when queued elements finish.
 */
class PlanetRessAchievement
{
    use \App\Game\Core\GameUtilities;

    /** @var array<int, array<int, bool>> achievements known to be held, per user (this request) */
    private array $held = [];

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievements in relation to Technology researches
     *
     * @param int			$element       	The id of the element
     * @param int	 		$userId       	The id of the User
     * @param int			$level       	The level of the element
     * @param array<string, mixed>|null $user the user row before this research (for the all-researches checks 58/59)
     */
    public function checkPlanetRessAchievementsResearch($element, $userId, $level, ?array $user = null)
    {
        $this->checkResearchBreadth((int) $element, (int) $userId, $level, $user);
        $achievementId = null;
        if ($element == INTERGALACTIC_TECH && $level == 1) {
            $achievementId = 27;
        } elseif ($element == DEFENCE_TECH) {
            $defenceAchievements = [3 => 19, 6 => 20, 9 => 21, 12 => 22, 15 => 23];
            $achievementId = $defenceAchievements[$level] ?? null;
        } elseif ($element == GRAVITON_TECH && $level == 2) {
            $achievementId = 5;
        } elseif ($element == LASER_TECH && $level == 12) {
            $achievementId = 24;
        } elseif ($element == IONIC_TECH && $level == 10) {
            $achievementId = 4;
        } elseif ($element == HYPERSPACE_MOTOR_TECH && $level >= 15) {
            // Warp drive: hyperspace drive level 15 or more
            $achievementId = 56;
        } elseif ($element == EXPEDITION_TECH && $level >= 15) {
            // Star cartographer: astrophysics level 15 or more
            $achievementId = 57;
        }

        if ($achievementId !== null && !$this->achievement()->checkAchievement($userId, $achievementId)) {
            $this->achievement()->setAchievement($userId, $achievementId);
        }
    }

    /**
     * Check for achievements in relation to Build ships
     * Build your first Deathstar (Achievement 9)
     * Build your first Deathstar as your first battleship (Achievement 52)
     *
     * @param int			$element       	The id of the element
     * @param int	 		$userId       	The id of the User
     */
    public function checkPlanetRessAchievementsShips($element, $userId)
    {
        if ($element == SHIP_RIP && !$this->achievement()->checkAchievement($userId, 9)) {
            $this->achievement()->setAchievement($userId, 9);
        }
        if ($element == SHIP_RIP && !$this->achievement()->checkAchievement($userId, 52)) {
            $elements = $this->elements();
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

            $count = 0;
            foreach ($elements->shipTypeIds('military') as $elementId) {
                $count += $statsHandler->getValue($userId, AdvancedStatsEnum::getBuild($elementId));
            }

            if ($count == 0) {
                $this->achievement()->setAchievement($userId, 52);
            }
        }
    }

    /**
     * Check for achievement 28
     * Alternate gameplay: Build Metal, Crystal and Deut storages to Level 3 each on a Moon
     *
     * @param array<string, mixed>			$PLANET       	The planet array
     * @param array<mixed>			$resource       The resource array
     * @param int	 		$userId       	The id of the User
     */
    public function checkPlanetRessAchievements28($PLANET, $resource, $userId)
    {
        if (!$this->achievement()->checkAchievement($userId, 28) && $PLANET[$resource[METAL_STORE]] >= 3 && $PLANET[$resource[CRYSTAL_STORE]] >= 3 && $PLANET[$resource[DEUTERIUM_STORE]] >= 3) {
            $this->achievement()->setAchievement($userId, 28);
        }
    }

    /**
     * Holds the user the achievement? Positive answers are remembered, so a held achievement costs one query per request.
     */
    private function holds(int $userId, int $achievementId): bool
    {
        if (isset($this->held[$userId][$achievementId])) {
            return true;
        }
        if ($this->achievement()->checkAchievement($userId, $achievementId)) {
            return $this->held[$userId][$achievementId] = true;
        }

        return false;
    }

    /**
     * Grants an achievement unless it is already held.
     */
    private function grant(int $userId, int $achievementId): void
    {
        if (!$this->holds($userId, $achievementId)) {
            // not cached as held: an enclosing transaction may still roll the grant back
            $this->achievement()->setAchievement($userId, $achievementId);
        }
    }

    /**
     * Polymath (58): every research at least level 1; Engineer of everything (59): every research at least level 10.
     * Graviton research and disabled researches are not counted. Evaluated whenever a research completes (so players
     * who already fulfil it get it with their next research); held achievements cost one cached lookup.
     *
     * @param array<string, mixed>|null $user user row before the research finished
     */
    private function checkResearchBreadth(int $element, int $userId, int|float $level, ?array $user): void
    {
        if ($user === null || $element === GRAVITON_TECH) {
            return;
        }
        $elements = $this->elements();
        $user[$elements->column($element)] = $level;
        foreach ([58 => 1, 59 => 10] as $id => $minLevel) {
            if ($this->holds($userId, $id)) {
                continue;
            }
            $all = true;
            foreach ($elements->ids('tech') as $tech) {
                if ($tech === GRAVITON_TECH || !$this->buildFunctions()->isEnabled($tech)) {
                    continue;
                }
                if (($user[$elements->column($tech)] ?? 0) < $minLevel) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $this->grant($userId, $id);
            }
        }
    }

    /**
     * Stock based achievements, in memory only until one condition is met (called after every saved resource update):
     * Hoarder (60) 1,000,000,000 of one resource, Sun worshipper (61) 1000 solar satellites, Full house (62) every field
     * of a planet with at least 250 fields used, Silo farmer (63) metal, crystal and deuterium storage level 15,
     * Armada (94) 100 Death Stars.
     *
     * @param array<string, mixed> $PLANET planet or moon row after the update
     */
    public function checkPlanetStockAchievements(array $PLANET, int $userId): void
    {
        $elements = $this->elements();
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            if (($PLANET[$elements->column($resourceId)] ?? 0) >= 1000000000) {
                $this->grant($userId, 60);
                break;
            }
        }
        if (($PLANET[$elements->column(SHIP_SOLSAT)] ?? 0) >= 1000) {
            $this->grant($userId, 61);
        }
        if (($PLANET[$elements->column(SHIP_RIP)] ?? 0) >= 100) {
            $this->grant($userId, 94);
        }
        if (($PLANET[$elements->column(METAL_STORE)] ?? 0) >= 15
            && ($PLANET[$elements->column(CRYSTAL_STORE)] ?? 0) >= 15
            && ($PLANET[$elements->column(DEUTERIUM_STORE)] ?? 0) >= 15) {
            $this->grant($userId, 63);
        }
        if (($PLANET['planet_type'] ?? 1) == 1 && ($PLANET['field_current'] ?? 0) >= 250 && !$this->holds($userId, 62)) {
            $maxFields = $this->planetQueries()->calculateMaxPlanetFields($PLANET);
            if ($PLANET['field_current'] >= $maxFields) {
                $this->grant($userId, 62);
            }
        }
    }

    /**
     * Whether the planet works with an energy deficit: producers draw more energy than the planet makes (energy_used is
     * stored negative). Moons and planets without consumers never are.
     *
     * @param array<string, mixed> $PLANET
     */
    public static function hasEnergyDeficit(array $PLANET): bool
    {
        $used = abs((float) ($PLANET['energy_used'] ?? 0));

        return ($PLANET['planet_type'] ?? 1) != 3 && $used > 0 && (float) ($PLANET['energy'] ?? 0) < $used;
    }

    /**
     * New value of planets.low_energy_since: 0 without deficit, the old start while the deficit continues, otherwise
     * the time the deficit was first observed (the state before is unknown, so a long gap is not counted).
     */
    public static function nextLowEnergySince(int $since, bool $deficit, int $observedAt): int
    {
        if (!$deficit) {
            return 0;
        }

        return $since > 0 ? $since : $observedAt;
    }

    /**
     * Whether a deficit that started at $since lasted more than 7 days at $now.
     */
    public static function lowEnergyLongEnough(int $since, int $now): bool
    {
        return $since > 0 && $now - $since > 7 * 86400;
    }

    /**
     * Happens to everyone accidentally (44): produced for more than 7 days in a row with an energy deficit. Returns the
     * new low_energy_since value (the caller writes it only when it differs from the stored one); grants the achievement
     * once the deficit lasted long enough.
     *
     * @param array<string, mixed> $PLANET planet row after the update (with low_energy_since)
     */
    public function trackLowEnergy(array $PLANET, int $userId, int $now): int
    {
        $since = self::nextLowEnergySince((int) ($PLANET['low_energy_since'] ?? 0), self::hasEnergyDeficit($PLANET), $now);
        if (self::lowEnergyLongEnough($since, $now)) {
            $this->grant($userId, 44);
        }

        return $since;
    }

    /**
     * Overachiever (65): a single building queue entry takes more than 30 days.
     *
     * @param int|float $seconds build time of the queued entry
     */
    public function checkBuildTime(int $userId, int|float $seconds): void
    {
        if ($seconds > 30 * 86400) {
            $this->grant($userId, 65);
        }
    }

    /**
     * Mining empire (64): nine planets with a metal mine of at least level 30. Called when a metal mine reaches level 30;
     * the finishing planet is not yet written, so it counts from memory.
     *
     * @param int $planetId planet that just finished its level 30 mine
     */
    public function checkMiningEmpire(int $userId, int $planetId): void
    {
        if ($this->holds($userId, 64)) {
            return;
        }
        $column = $this->elements()->column(metal_mine);
        $others = $this->db->selectSingle(
            'SELECT COUNT(*) AS cnt FROM %%PLANETS%% WHERE id_owner = :userId AND planet_type = 1 AND destruyed = 0 AND id != :planetId AND `' . $column . '` >= 30;',
            [':userId' => $userId, ':planetId' => $planetId],
            'cnt',
        );
        if ($others + 1 >= 9) {
            $this->grant($userId, 64);
        }
    }

    /**
     * Colonial overlord (95): the user owns as many planets as astrophysics allows (at least 9) and each has at least 200
     * fields (field_max plus terraformer). Called when a building finishes on a planet or a planet is colonised.
     * The cheap checks come first: held, the changed planet itself reaching 200 fields.
     *
     * @param array<string, mixed>|null $USER user row (loaded when null)
     * @param array<string, mixed>|null $PLANET planet row after the change that is not written yet (counted from memory)
     */
    public function checkColonialOverlord(int $userId, ?array $USER = null, ?array $PLANET = null): void
    {
        if ($this->holds($userId, 95)) {
            return;
        }
        if ($PLANET !== null && ($PLANET['planet_type'] != 1 || $this->planetQueries()->calculateMaxPlanetFields($PLANET) < 200)) {
            return;
        }
        $USER ??= $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE id = :userId;', [':userId' => $userId]);
        if (!is_array($USER)) {
            return;
        }
        $slots = $this->planetPositions()->maxPlanetCount($USER);
        if ($slots < 9) {
            return;
        }
        $terraformer = $this->elements()->column(TERRAFORMER);
        $skip = $PLANET === null ? 0 : (int) $PLANET['id'];
        $row = $this->db->selectSingle(
            'SELECT COUNT(*) AS total, SUM(field_max + FLOOR(`' . $terraformer . '` * ' . FIELDS_BY_TERRAFORMER . ') >= 200) AS big
            FROM %%PLANETS%% WHERE id_owner = :userId AND planet_type = 1 AND destruyed = 0 AND id != :skip;',
            [':userId' => $userId, ':skip' => $skip],
        );
        $extra = $PLANET === null ? 0 : 1;
        if ($row['total'] + $extra >= $slots && (int) $row['big'] === (int) $row['total']) {
            $this->grant($userId, 95);
        }
    }
}
