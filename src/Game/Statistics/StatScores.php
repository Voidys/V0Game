<?php

namespace App\Game\Statistics;

use App\Game\Core\ConfigRegistry;
use App\Game\Model\ElementCatalog;

/**
 * Score formulas of the statistics: research, building, defense and fleet points of a player or planet from the element
 * levels/amounts and the price lists of its universe. Pure calculation (no database access); the score is the total
 * resource cost divided by the `stat_settings` config value (points per 1000 resource units), building and research
 * level costs memoized.
 */
class StatScores
{
    /**
     * @param array<int, ElementCatalog> $catalogs universe => game data of that universe
     */
    public function __construct(
        private readonly ConfigRegistry $configs,
        private readonly array $catalogs,
    ) {}

    /** @var array<int, array<int, array<int, float|int>>> cost of an element level by universe, element, level */
    private array $levelCosts = [];

    /** Total cost of building/researching $element up to $level (level $level only), memoized. */
    private function levelCost(int $uni, int $element, int $level): float|int
    {
        return $this->levelCosts[$uni][$element][$level] ??= $this->catalogs[$uni]->cost($element)->metalCrystalDeuterium()
            * pow($this->catalogs[$uni]->factor($element), $level - 1);
    }

    /**
     * Research score of a player: total cost of all tech levels (each level costs factor^(level-1) times the base cost)
     * divided by the points-per-score setting (stat_settings).
     *
     * @param array<string, mixed> $USER player row with the tech level columns and `universe`
     * @return array{count: int|float, points: int|float} number of levels, score
     */
    public function researchByUser(array $USER): array
    {
        $elements = $this->catalogs[$USER['universe']];
        $TechCounts = 0;
        $TechPoints = 0;

        foreach ($elements->ids('tech') as $Techno) {
            if ($USER[$elements->column($Techno)] == 0) {
                continue;
            }

            // PointsPerCot == Config::get()->stat_settings
            for ($i = 1; $i <= $USER[$elements->column($Techno)]; $i++) {
                $TechPoints += $this->levelCost($USER['universe'], $Techno, $i);
            }
            $TechCounts += $USER[$elements->column($Techno)];
        }

        return ['count' => $TechCounts, 'points' => ($TechPoints / max(1, $this->configs->get((int) $USER['universe'])->stat_settings))];
    }

    /**
     * Building score of a planet: total cost of all building levels divided by stat_settings.
     *
     * @param array<string, mixed> $PLANET planet row with the building columns and `universe`
     * @return array{count: int|float, points: int|float} number of levels, score
     */
    public function buildingByPlanet(array $PLANET): array
    {
        $elements = $this->catalogs[$PLANET['universe']];
        $BuildCounts = 0;
        $BuildPoints = 0;

        foreach ($elements->ids('build') as $Build) {
            if ($PLANET[$elements->column($Build)] == 0) {
                continue;
            }

            // PointsPerCost == Config::get()->stat_settings
            for ($i = 1; $i <= $PLANET[$elements->column($Build)]; $i++) {
                $BuildPoints += $this->levelCost($PLANET['universe'], $Build, $i);
            }
            $BuildCounts += $PLANET[$elements->column($Build)];
        }
        return ['count' => $BuildCounts, 'points' => ($BuildPoints / max(1, $this->configs->get((int) $PLANET['universe'])->stat_settings))];
    }

    /**
     * Defense score of a player: cost of all defense and missile units divided by stat_settings.
     *
     * @param array<string, mixed> $USER player row with the unit columns (summed over all planets) and `universe`
     * @return array{count: int|float, points: int|float} number of units, score
     */
    public function defenseByUser(array $USER): array
    {
        $elements = $this->catalogs[$USER['universe']];
        $DefenseCounts = 0;
        $DefensePoints = 0;

        foreach (array_merge($elements->ids('defense'), $elements->ids('missile')) as $Defense) {
            if ($USER[$elements->column($Defense)] == 0) {
                continue;
            }

            $Units = $elements->cost($Defense)->metalCrystalDeuterium();
            $DefensePoints += $Units * $USER[$elements->column($Defense)];
            $DefenseCounts += $USER[$elements->column($Defense)];
        }

        return ['count' => $DefenseCounts, 'points' => ($DefensePoints / max(1, $this->configs->get((int) $USER['universe'])->stat_settings))];
    }

    /**
     * Fleet score of a player: cost of all ships (on planets and flying) divided by stat_settings.
     *
     * @param array<string, mixed> $USER player row with the ship columns (including flying fleets) and `universe`
     * @return array{count: int|float, points: int|float} number of ships, score
     */
    public function fleetByUser(array $USER): array
    {
        $elements = $this->catalogs[$USER['universe']];
        $FleetCounts = 0;
        $FleetPoints = 0;

        foreach ($elements->ids('fleet') as $Fleet) {
            if ($USER[$elements->column($Fleet)] == 0) {
                continue;
            }

            $Units = $elements->cost($Fleet)->metalCrystalDeuterium();
            $FleetPoints += $Units * $USER[$elements->column($Fleet)];
            $FleetCounts += $USER[$elements->column($Fleet)];
        }

        return ['count' => $FleetCounts, 'points' => ($FleetPoints / max(1, $this->configs->get((int) $USER['universe'])->stat_settings))];
    }
}
