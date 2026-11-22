<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use Exception;
use App\Game\Models\StatPoints;
use App\Game\Statistics\StatArchive;
use App\Game\Statistics\StatDataLoader;
use App\Game\Statistics\StatJsonExport;
use App\Game\Statistics\StatPointsWriter;
use App\Game\Statistics\StatRanker;
use App\Game\Statistics\StatRecords;
use App\Game\Statistics\StatScores;

/**
 * The statistics run (cronjob "statistics", admin "update statistics"): computes the points of every player and
 * alliance, writes them to statpoints, ranks them, and also exports the JSON files, archives the history and rebuilds
 * the records. Facade over the services in App\Game\Statistics:
 * StatDataLoader (input), StatScores (formulas), StatPointsWriter (batched upserts), StatRanker (ranks and honor titles),
 * StatRecords, StatArchive, StatJsonExport.
 *
 * Hidden players (stat setting 1/2 with authlevel at or above stat_level, and banned players) get a row without points.
 * Alliance points count only members that were online within INACTIVE.
 */
class Statbuilder
{
    use \App\Game\Core\GameUtilities;

    public const STAT_TYPE_USER = 1;
    public const STAT_TYPE_ALLIANCE = 2;

    private float $startTime;
    /** @var array<mixed> */
    private array $memory;
    private int $time;
    /** @var array<mixed> */
    private array $universes;
    /** @var array<int, \App\Game\Model\ElementCatalog> universe => game data of that universe */
    private array $catalogs = [];
    private StatScores $scores;
    private StatPointsWriter $writer;
    /** @var array<int, array<string, mixed>> alliance id => its previous ranks (old_*_rank) */
    private array $allianceOldRanks = [];

    /**
     * Loads the available universes and their game data (resource names, element lists, price lists from the "vars" cache) and prepares the scorer and the statpoints writer; also notes start time and memory for the run info.
     */
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
        $this->startTime = microtime(true);
        $this->memory = [round(memory_get_usage() / 1024, 1), round(memory_get_usage(1) / 1024, 1)];
        $this->time = $this->state->runtime->timestamp;

        $this->getUniverses();

        $cache  = new Cache($this->db, $this->state);
        $vars = $cache->getData('vars');
        foreach ($this->universes as $uni) {
            $this->catalogs[$uni] = \App\Game\Model\ElementCatalog::fromVarsData($vars, $uni);
        }
        $this->scores = new StatScores($this->configs, $this->catalogs);
        $this->writer = new StatPointsWriter($this->db);
    }

    /**
     * Runs the statistics: scores all players and alliances, writes statpoints, ranks (categories, honor ranks, honor
     * titles) and stores the player count per universe in the config.
     *
     * @return array<string, mixed> run info (time, memory, query count)
     * @throws Exception
     */
    final public function generateStats(): array
    {
        $allianceScores = [];
        $userStats = (new StatDataLoader($this->db, $this->universes, $this->catalogs))->load();
        $this->allianceOldRanks = array_column($userStats['Alliance'], null, 'id');
        $userScores = $this->setUserScoresByPlanets($userStats);
        $universeData = [];

        foreach ($userStats['Users'] as $userData) {
            $statPoints = new StatPoints(
                $userData['id'],
                $userData['ally_id'],
                self::STAT_TYPE_USER,
                $userData['universe'],
            );

            if (!isset($universeData[$userData['universe']])) {
                $universeData[$userData['universe']] = 0;
            }

            $universeData[$userData['universe']]++;

            if ($this->isHidden($userData)) {
                // no points, but keep the previous ranks (the rank change of a player shown again stays right)
                $this->copyOldRanks($statPoints, $userData);
                $this->writer->queue($statPoints);
                continue;
            }

            $elements = $this->catalogs[$userData['universe']];
            if (isset($userStats['Fleets'][$userData['id']])) {
                foreach ($userStats['Fleets'][$userData['id']] as $ID => $Amount) {
                    $column = $elements->find((int) $ID)?->column;
                    if ($column !== null) {
                        $userData[$column] += $Amount;
                    }
                }
            }

            $techPoints = $this->scores->researchByUser($userData);
            $fleetPoints = $this->scores->fleetByUser($userData);
            $defensePoints = $this->scores->defenseByUser($userData);
            $buildScore = $userScores[$userData['id']]['build'];
            $honorData = $userStats['Honor'][$userData['id']] ?? [
                'honor_points'        => 0,
                'honorable_fights'    => 0,
                'dishonorable_fights' => 0,
                'units_points'        => 0,
            ];

            $this->fillUserPoints($statPoints, $userData, $buildScore, $fleetPoints, $defensePoints, $techPoints, $honorData);
            $statPoints->setTotalStats();
            $this->writer->queue($statPoints);

            if ($userData['ally_id'] != 0) {
                $this->addToAlliance($allianceScores, $userData, $buildScore, $fleetPoints, $defensePoints, $techPoints, $honorData);
            }
        }

        if (count($allianceScores) != 0) {
            foreach ($allianceScores as $allianceData) {
                $this->writer->queue($allianceData);
            }
        }

        $this->writer->flush();
        $ranker = new StatRanker($this->db, $this->configs, $this->universes);
        $ranker->setNewRanks();
        $ranker->setHonorRanks();
        $ranker->setHonorTitles();
        $this->checkUniverseAccounts($universeData);

        return $this->getStatsArray();
    }

    /**
     * Whether the player is hidden from the statistics: an admin at or above stat_level while the admin-hiding stat
     * mode (1 or 2) is on, or a banned player.
     *
     * @param array<string, mixed> $userData user (or planet) row with `authlevel` and `bana`
     */
    private function isHidden(array $userData): bool
    {
        return (in_array($this->configs->get((int) $userData["universe"])->stat, [1, 2]) && $userData['authlevel'] >= $this->configs->get((int) $userData["universe"])->stat_level) || !empty($userData['bana']);
    }

    /**
     * Copies the computed scores, honor figures and the previous ranks into the player's StatPoints row.
     *
     * @param array<string, mixed> $userData  user row (with the old_*_rank columns)
     * @param array<string, int|float> $buildScore count/points of all planets' buildings
     * @param array<mixed> $defensePoints
     * @param array<mixed> $fleetPoints
     * @param array<mixed> $honorData
     * @param array<mixed> $techPoints
     */
    private function fillUserPoints(StatPoints $statPoints, array $userData, array $buildScore, array $fleetPoints, array $defensePoints, array $techPoints, array $honorData): void
    {
        $statPoints->build_count = (int) $buildScore['count'];
        $statPoints->build_points = (int) $buildScore['points'];
        $statPoints->fleet_count = (int) $fleetPoints['count'];
        $statPoints->fleet_points = (int) $fleetPoints['points'];
        $statPoints->defs_count = (int) $defensePoints['count'];
        $statPoints->defs_points = (int) $defensePoints['points'];
        $statPoints->tech_count = (int) $techPoints['count'];
        $statPoints->tech_points = (int) $techPoints['points'];

        $statPoints->honor_points = $honorData['honor_points'];
        $statPoints->honorable_fights = $honorData['honorable_fights'];
        $statPoints->dishonorable_fights = $honorData['dishonorable_fights'];
        $statPoints->units_points = $honorData['units_points'];

        $this->copyOldRanks($statPoints, $userData);
    }

    /**
     * Copies the player's previous ranks (old_*_rank columns of the user row) into the StatPoints row.
     *
     * @param array<string, mixed> $userData user row with the old_*_rank columns
     */
    private function copyOldRanks(StatPoints $statPoints, array $userData): void
    {
        $statPoints->build_old_rank = (int) $userData['old_build_rank'];
        $statPoints->fleet_old_rank = (int) $userData['old_fleet_rank'];
        $statPoints->defs_old_rank = (int) $userData['old_defs_rank'];
        $statPoints->tech_old_rank = (int) $userData['old_tech_rank'];
        $statPoints->total_old_rank = (int) $userData['old_total_rank'];
        $statPoints->honor_old_rank = (int) $userData['old_honor_rank'];
        $statPoints->units_old_rank = (int) $userData['old_units_rank'];
    }

    /**
     * Adds a member's scores to the alliance total (created on first use); members offline longer than INACTIVE do not
     * count.
     *
     * @param array<int, StatPoints> $allianceScores alliance id => totals (modified)
     * @param array<mixed> $buildScore
     * @param array<mixed> $defensePoints
     * @param array<mixed> $fleetPoints
     * @param array<mixed> $honorData
     * @param array<mixed> $techPoints
     * @param array<mixed> $userData
     */
    private function addToAlliance(array &$allianceScores, array $userData, array $buildScore, array $fleetPoints, array $defensePoints, array $techPoints, array $honorData): void
    {
        if (!isset($allianceScores[$userData['ally_id']])) {
            $allianceScores[$userData['ally_id']] = new StatPoints(
                $userData['ally_id'],
                0,
                self::STAT_TYPE_ALLIANCE,
                $userData['universe'],
            );
            // previous ranks, like the players' (without them every alliance showed a drop by its own rank)
            $old = $this->allianceOldRanks[$userData['ally_id']] ?? [];
            foreach (['build', 'fleet', 'defs', 'tech', 'total', 'honor', 'units'] as $category) {
                $allianceScores[$userData['ally_id']]->{$category . '_old_rank'} = (int) ($old['old_' . $category . '_rank'] ?? 0);
            }
        }

        if ($userData['onlinetime'] >= $this->state->runtime->timestamp - INACTIVE) {
            $alliance = $allianceScores[$userData['ally_id']];
            $alliance->build_count += (int) $buildScore['count'];
            $alliance->build_points += (int) $buildScore['points'] ?? 0;
            $alliance->fleet_count += (int) $fleetPoints['count'];
            $alliance->fleet_points += (int) $fleetPoints['points'];
            $alliance->defs_count += (int) $defensePoints['count'];
            $alliance->defs_points += (int) $defensePoints['points'];
            $alliance->tech_count += (int) $techPoints['count'];
            $alliance->tech_points += (int) $techPoints['points'];
            $alliance->honor_points += $honorData['honor_points'];
            $alliance->honorable_fights += $honorData['honorable_fights'];
            $alliance->dishonorable_fights += $honorData['dishonorable_fights'];
            $alliance->units_points += $honorData['units_points'];
            $alliance->setTotalStats();
        }
    }

    /**
     * @throws Exception
     */
    private function getUniverses(): void
    {
        $this->universes = [];

        $uniResult = $this->db->select("SELECT DISTINCT uni FROM %%CONFIG_UNIVERSE%% ORDER BY uni ASC;");
        foreach ($uniResult as $uni) {
            $this->universes[] = $uni['uni'];
        }
    }

    /**
     * @return array<mixed>
     */
    private function getStatsArray(): array
    {
        return [
            'stats_time' => $this->time,
            'totaltime' => round(microtime(true) - $this->startTime, 7),
            'memory_peak' => [round(memory_get_peak_usage() / 1024, 1), round(memory_get_peak_usage(1) / 1024, 1)],
            'initial_memory' => $this->memory,
            'end_memory' => [round(memory_get_usage() / 1024, 1), round(memory_get_usage(1) / 1024, 1)],
            'sql_count' => $this->db->getQueryCounter(),
        ];
    }

    /**
     * @param array<mixed> $UniData
     */
    private function checkUniverseAccounts(array $UniData): void
    {
        $UniData = $UniData + array_combine($this->universes, array_fill(1, count($this->universes), 0));
        foreach ($UniData as $Uni => $Amount) {
            $config = $this->configs->get($Uni);
            $config->users_amount = $Amount;
            $config->save();
        }
    }

    /**
     * Building score of a planet (see StatScores::buildingByPlanet()).
     *
     * @param array<string, mixed> $PLANET planet row with the building columns and `universe`
     * @return array{count: int|float, points: int|float}
     */
    public function getBuildingScoreByPlanet(array $PLANET): array
    {
        return $this->scores->buildingByPlanet($PLANET);
    }

    /**
     * Sums the building scores of the planets per owner (hidden players are skipped).
     *
     * @param array<mixed> $userStats
     * @return array<int, array{build: array{count: int|float, points: int|float}}> owner id => building count/points
     */
    private function setUserScoresByPlanets(array $userStats): array
    {
        $userScores = [];

        foreach ($userStats['Planets'] as $PlanetData) {
            if ($this->isHidden($PlanetData)) {
                continue;
            }

            if (!isset($userScores[$PlanetData['id_owner']])) {
                $userScores[$PlanetData['id_owner']]['build']['count'] = $userScores[$PlanetData['id_owner']]['build']['points'] = 0;
            }

            $BuildPoints = $this->scores->buildingByPlanet($PlanetData);
            $userScores[$PlanetData['id_owner']]['build']['count'] += $BuildPoints['count'];
            $userScores[$PlanetData['id_owner']]['build']['points'] += $BuildPoints['points'];
        }

        return $userScores;
    }

    /** Writes the public statistics JSON files (see StatJsonExport::export()). */
    public function generateJson(): void
    {
        (new StatJsonExport($this->db, $this->configs, $this->state, $this->universes))->export();
    }

    /** Rebuilds the records table (see StatRecords::buildRecords()). */
    public function buildRecords(): void
    {
        (new StatRecords($this->db, $this->configs, $this->universes, $this->catalogs))->buildRecords();
    }

    /** Archives the statistics into the history tables (see StatArchive::archive()). */
    public function archiveStats(): void
    {
        (new StatArchive($this->db, $this->state))->archive();
    }
}
