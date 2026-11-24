<?php

namespace App\Game\Core\Player;

use App\Game\Enum\PlanetType;
use App\Game\Core\Config;
use App\Game\Core\StoredData;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Finds and validates coordinates for planets: free-slot checks, the position strategies for new home planets
 * (random, block, linear; chosen by the planet_creation setting) and the astrophysics based limits for
 * colonies. Collaborators: ConfigRegistry (universe settings), Database (planets/users), state (runtime clock,
 * resource map).
 */
class PlanetPositions
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Whether a slot is unoccupied by an object of the given type.
     *
     * @param int $universe
     * @param int $galaxy
     * @param int $system
     * @param int $planet
     * @param string $type PlanetType::Planet->value or PlanetType::Moon->value
     * @return bool true if no such object exists and all coordinates are positive
     */
    public function isPositionFree(int $universe, int $galaxy, int $system, int $planet, int|string $type = PlanetType::Planet->value): bool
    {
        $db = $this->db;
        $sql = 'SELECT COUNT(*) as record
            FROM %%PLANETS%%
            WHERE `universe` = :universe
            AND `galaxy` = :galaxy
            AND `system` = :system
            AND `planet` = :planet
            AND `planet_type` = :type;';

        $count = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':galaxy'   => $galaxy,
            ':system'   => $system,
            ':planet' => $planet,
            ':type'     => $type,
        ], 'record');

        if ($count == 0 && $type == PlanetType::Planet->value) {
            // a live derelict field / pirate mothership (map content) occupies the slot
            $count = $db->selectSingle(
                'SELECT COUNT(*) AS record FROM %%PIRATE_BASES%% WHERE `universe` = :universe AND `kind` > 0 AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet;',
                [':universe' => $universe, ':galaxy' => $galaxy, ':system' => $system, ':planet' => $planet],
                'record',
            );
        }

        return $count == 0 && $planet > 0 && $system > 0 && $galaxy > 0;
    }

    /**
     * Whether the coordinates lie inside the universe size (1..max_galaxy/max_system/max_planets).
     *
     * @return bool
     */
    public function checkPosition(int $universe, int $galaxy, int $system, int $planet): bool
    {
        $config = $this->configs->get($universe);

        return !(1 > $galaxy
            || 1 > $system
            || 1 > $planet
            || $config->max_galaxy < $galaxy
            || $config->max_system < $system
            || $config->max_planets < $planet);
    }

    /**
     * Picks a random free home-planet position, preferring the emptiest part of the universe.
     *
     * Chooses a galaxy with the lowest average number of active planets per system (ties: random), then the
     * least populated systems of it, and a random slot between 20% and 80% of max_planets. The position
     * search is "random with retries"; the result may be 0/0 values if no free slot was found.
     *
     * @param int $universe
     * @return array{galaxy: int, system: int, planet: int}
     */
    public function randomHP(int $universe): array
    {
        $config = $this->configs->get($universe);
        $galaxy = $this->pickEmptiestGalaxy($universe, $config);
        $systems = $this->systemPlanetCounts($universe, $galaxy, $config);

        $planet = 0;
        $system = 0;

        // get empty systems in selected gala
        for ($planetamount = 0; $planetamount <= $config->max_planets; $planetamount++) {
            $usableSystems = [];
            foreach ($systems as $sysArray) {
                if ($sysArray['anz'] == $planetamount) {
                    $usableSystems[] = $sysArray['system'];
                }
            }

            $planet = 0;
            $system = 0;

            // find random system and random planet inside
            if (!empty($usableSystems)) {
                do {
                    $system = $usableSystems[array_rand($usableSystems)];
                    do {
                        $planet = random_int(round($config->max_planets * 0.2), round($config->max_planets * 0.8));
                    } while (!$this->allowPlanetPosition($planet, null, $universe));
                } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);
            }
            if ($this->allowPlanetPosition($planet, null, $universe) && $this->isPositionFree($universe, $galaxy, $system, $planet)) {
                break;
            }
        }
        $pos = [
            'galaxy'   => $galaxy,
            'system'   => $system,
            'planet'   => $planet,
        ];
        return $pos;
    }

    /**
     * Chooses the galaxy with the lowest average count of planets of active players per system; random among ties.
     *
     * @param int $universe
     * @param Config $config configuration of the universe (max_galaxy, max_system, max_planets)
     * @return int galaxy number
     */
    private function pickEmptiestGalaxy(int $universe, Config $config): int
    {
        $db = $this->db;
        //get avg planets per system per galaxy
        $sql = 'SELECT tab.`galaxy`, (sum(tab.anz)/:maxSys) as AvgPlanetsPerSys FROM (
            SELECT p.`galaxy`, p.`system`, COUNT(p.`planet`) as anz
            FROM %%PLANETS%% p
            JOIN %%USERS%% u on u.`id` = p.`id_owner`
            WHERE `planet_type` = 1 AND p.`galaxy` <= :maxGala AND u.`onlinetime` >= ( :ts - :inactive ) AND p.`universe` = :universe
            GROUP BY p.`galaxy`, p.`system`
        ) as tab GROUP BY `galaxy` ORDER BY tab.`galaxy` ASC;';
        $result = $db->select($sql, [
            ':universe' => $universe,
            ':maxGala' => $config->max_galaxy,
            ':maxSys' => $config->max_system,
            ':ts' => $this->state->runtime->timestamp,
            ':inactive' => INACTIVE,
        ]);

        $avgPlanetsPerGala = [];
        if (empty($result)) {
            for ($i = 1; $i <= $config->max_galaxy; $i++) {
                $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
            }
        } else {
            $i = 1;
            foreach ($result as $resultArray) {
                while ($i < $resultArray['galaxy']) {
                    $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
                    $i++;
                }
                $avgPlanetsPerGala[] = $resultArray;
                $i++;
            }
            for (; $i <= $config->max_galaxy; $i++) {
                $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
            }
        }

        // get gala with min avg systems
        $minAvg = $config->max_planets;
        $galaArray = [];
        foreach ($avgPlanetsPerGala as $data) {
            if ($data['AvgPlanetsPerSys'] < $minAvg) {
                $minAvg = $data['AvgPlanetsPerSys'];
            }
        }
        foreach ($avgPlanetsPerGala as $data) {
            if ($data['AvgPlanetsPerSys'] == $minAvg) {
                array_push($galaArray, $data['galaxy']);
            }
        }
        return $galaArray[rand(0, count($galaArray) - 1)];
    }

    /**
     * Number of planets per system of a galaxy, with empty systems listed as 0.
     *
     * @param int $universe
     * @param int $galaxy
     * @param Config $config
     * @return array<int, array{system: int, anz: int}> one row per system 1..max_system
     */
    private function systemPlanetCounts(int $universe, int $galaxy, Config $config): array
    {
        // get system with planet count for selected gala
        $sql = 'SELECT `system`, count(`planet`) as anz FROM %%PLANETS%%
            WHERE `planet_type` = 1 AND `galaxy` = :gala AND `universe` = :universe GROUP BY `system`;';
        $result = $this->db->select($sql, [
            ':gala' => $galaxy,
            ':universe' => $universe,
        ]);

        $systems = [];
        if (empty($result)) {
            for ($i = 1; $i <= $config->max_system; $i++) {
                $systems[] = ['system' => $i, 'anz' => 0];
            }
        } else {
            $i = 1;
            foreach ($result as $resultArray) {
                while ($i < $resultArray['system']) {
                    $systems[] = ['system' => $i, 'anz' => 0];
                    $i++;
                }
                $systems[] = $resultArray;
                $i++;
            }
            for (; $i <= $config->max_system; $i++) {
                $systems[] = ['system' => $i, 'anz' => 0];
            }
        }
        return $systems;
    }

    /**
     * Home-planet position strategy "block": fills systems that hold few active players.
     *
     * With fewer than 50 planets in the universe it falls back to randomHP(). Otherwise it looks for systems with
     * fewer than 1 (then 2) planets of active players inside the 20%..80% slot band and picks a random allowed
     * slot there; if none is found it falls back to randomHP().
     *
     * @param int $universe
     * @return array{galaxy: int, system: int, planet: int}
     */
    public function blockHP(int $universe): array
    {
        $config = $this->configs->get($universe);
        $db = $this->db;

        //if less than 50 planets, place randomly
        $sql = 'SELECT Count(`id`) AS planetcount FROM %%PLANETS%% WHERE `universe` = :universe;';
        $planetcount = $db->selectSingle($sql, [
            ':universe' => $universe,
        ], 'planetcount');
        if ($planetcount < 50) {
            $pos = $this->randomHP($universe);
            return $pos;
        }

        $minPos = round($config->max_planets * 0.2);
        $maxPos = round($config->max_planets * 0.8);
        $maxPlanets = $maxPos - $minPos;

        //Search for systems with one planet, if none, search for 2 planets
        for ($planetamount = 1; $planetamount < 3; $planetamount++) {
            $sql = 'SELECT p.`galaxy`, p.`system` FROM
                %%PLANETS%% p
                JOIN (
                        SELECT `galaxy`, `system`
                        FROM %%PLANETS%%
                        WHERE `planet_type` = 1
                        AND `universe` = :universe
                        AND `galaxy` <= :maxGala
                        AND `planet` >= :minPos
                        AND `planet` <= :maxPos
                        GROUP BY `galaxy`, `system`
                        HAVING COUNT(`planet`) < :maxPlanets
                    ) as tab ON tab.`galaxy` = p.`galaxy` AND tab.`system` = p.`system`
                JOIN %%USERS%% u on u.`id` = p.`id_owner`
                WHERE p.`planet_type` = 1 AND u.`onlinetime` >= ( :ts - :inactive ) AND p.`universe` = :universe
                GROUP BY p.`galaxy`, p.`system`
                HAVING COUNT(p.`planet`) < :planetamount;';
            $systems = $db->select($sql, [
                ':universe' => $universe,
                ':maxGala' => $config->max_galaxy,
                ':minPos' => $minPos,
                ':maxPos' => $maxPos,
                ':maxPlanets' => $maxPlanets,
                ':planetamount' => $planetamount,
                ':ts' => $this->state->runtime->timestamp,
                ':inactive' => INACTIVE,
            ]);

            if (!empty($systems)) {
                break;
            }
        }

        // if systems found, place planet
        if (!empty($systems)) {
            do {
                $galasys = $systems[array_rand($systems)];
                $galaxy = $galasys['galaxy'];
                $system = $galasys['system'];
                do {
                    $planet = random_int($minPos, $maxPos);
                } while (!$this->allowPlanetPosition($planet, null, $universe));
            } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);
            $pos = [
                'galaxy'   => $galaxy,
                'system'   => $system,
                'planet'  => $planet,
            ];
            return $pos;
        }

        // else place random planet
        $pos = $this->randomHP($universe);
        return $pos;
    }

    /**
     * Home-planet position strategy "linear": walks through the universe system by system.
     *
     * Continues behind last_galaxy_pos/last_system_pos of the config and stores the chosen coordinates back into it
     * (side effect: config values changed in memory, saved by the caller).
     *
     * @param int $universe
     * @param Config $config universe configuration (last_*_pos are updated)
     * @return array{galaxy: int, system: int, planet: int}
     */
    public function linearHP(int $universe, Config $config): array
    {
        $galaxy = $config->last_galaxy_pos;
        $system = $config->last_system_pos;
        $planet = $config->last_planet_pos;

        if ($galaxy > $config->max_galaxy) {
            $galaxy = 1;
        }

        if ($system > $config->max_system) {
            $system = 1;
        }

        do {
            // like randomHP/blockHP: only slots a new player (astrophysics 1) may hold
            do {
                $planet = random_int(round($config->max_planets * 0.2), round($config->max_planets * 0.8));
            } while (!$this->allowPlanetPosition($planet, null, $universe));
            if ($planet < 3) {
                $planet += 1;
            } else {
                if ($system >= $config->max_system) {
                    $system = 1;
                    if ($galaxy >= $config->max_galaxy) {
                        $galaxy = 1;
                    } else {
                        $galaxy += 1;
                    }
                } else {
                    $system += 1;
                }
            }
        } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);

        // Update last coordinates to config table
        $config->last_galaxy_pos = $galaxy;
        $config->last_system_pos = $system;
        $config->last_planet_pos = $planet;

        $pos = [
            'galaxy'   => $galaxy,
            'system'   => $system,
            'planet'   => $planet,
        ];
        return $pos;
    }

    /**
     * Chooses a position for a new home planet by the planet_creation setting: 1 = random, 2 = random or
     * block (coin flip), anything else = linear.
     *
     * @param int $universe
     * @return array{galaxy: int, system: int, planet: int}
     */
    public function getNewPlanetPosition(int $universe): array
    {
        $config = $this->configs->get($universe);
        $pos = [];
        if ($config->planet_creation == 1) {
            $pos = $this->randomHP($universe);
        } elseif ($config->planet_creation == 2) {
            $rand_vs_block = rand(0, 1);
            switch ($rand_vs_block) {
                case 0:
                    $pos = $this->randomHP($universe);
                    break;
                case 1:
                    $pos = $this->blockHP($universe);
                    break;
            }
        } else {
            $pos = $this->linearHP($universe, $config);
        }
        return $pos;
    }

    /**
     * Whether a player may settle a slot with the current astrophysics level.
     *
     * Outer slots need more astrophysics: slots 1 and max_planets level 8, 2 and max-1 level 6, 3 and max-2 level 4,
     * everything else level 1. Without a user the check assumes level 1 (home planet placement). A player with
     * astrophysics 0 who still has a free colony slot is treated as level 1.
     *
     * @param int $position slot (1..max_planets)
     * @param array<string, mixed>|null $USER user row, or null for a new player
     * @param int $universe used when no user is given
     * @return bool
     */
    public function allowPlanetPosition(int $position, ?array $USER = null, int $universe = ROOT_UNI): bool
    {
        // http://owiki.de/index.php/Astrophysik#.C3.9Cbersicht

        if (isset($USER)) {
            $config = $this->configs->get($USER['universe']);
            $astroTech = $this->getAstroTech($USER);

            // the planet counts only matter when astro tech is 0 (max(astroTech, 1) changes nothing otherwise)
            if ($astroTech < 1 && $this->currentPlanetCount($USER) < $this->maxPlanetCount($USER)) {
                $astroTech = 1;
            }
        } else {
            $config = $this->configs->get($universe);
            $astroTech = 1;
        }

        switch ($position) {
            case 0:
                return false;
            case 1:
            case ($config->max_planets):
                return $astroTech >= 8;
            case 2:
            case ($config->max_planets - 1):
                return $astroTech >= 6;
            case 3:
            case ($config->max_planets - 2):
                return $astroTech >= 4;
            default:
                return $astroTech >= 1 && $config->max_planets >= $position;
        }
    }

    /**
     * Astrophysics level of a user including a finished-but-not-yet-applied research in the queue.
     *
     * @param array<string, mixed> $USER user row (research level columns, b_tech_queue)
     * @return int
     */
    private function getAstroTech(array $USER): int
    {
        $elements = $this->elements();

        $astroTech = $USER[$elements->column(124)];

        $CurrentQueue = !empty($USER['b_tech_queue']) ? StoredData::decode($USER['b_tech_queue']) : [];
        if (!empty($CurrentQueue) && count($CurrentQueue) > 0) {
            foreach ($CurrentQueue as $ListIDArray) {
                if ($ListIDArray[0] == 124 && $ListIDArray[3] <= $this->state->runtime->timestamp) {
                    $astroTech++;
                }
            }
        }

        return $astroTech;
    }

    /**
     * Number of planets a player may own: max_initial_planets plus planets_per_tech per astrophysics
     * level, capped by max_additional_planets (a universe without initial planets removes the cap).
     *
     * @param array<string, mixed> $USER user row
     * @return int
     */
    public function maxPlanetCount(array $USER): int
    {
        $elements = $this->elements();
        $config = $this->configs->get($USER['universe']);

        $planetPerTech = $config->max_additional_planets;

        if ($config->max_initial_planets == 0) {
            $planetPerTech = 999;
        }

        if ($config->max_initial_planets == 0) {
            $planetPerBonus = 999;
        }

        // http://owiki.de/index.php/Astrophysik#.C3.9Cbersicht
        return (int) ceil(
            $config->max_initial_planets + min(
                $planetPerTech,
                $this->getAstroTech($USER) * $config->planets_per_tech,
            ),
        );
    }

    /**
     * Number of non-destroyed planets (not moons) the user owns.
     *
     * @param array<string, mixed> $USER user row
     * @return int
     */
    public function currentPlanetCount(array $USER): int
    {
        $db = $this->db;
        $sql = 'SELECT COUNT(*) as state
            FROM %%PLANETS%%
            WHERE `id_owner`	= :userId
            AND `planet_type`	= :type
            AND `destruyed`		= :destroyed;';

        $currentPlanetCount = $db->selectSingle($sql, [
            ':userId'       => $USER['id'],
            ':type'         => 1,
            ':destroyed'    => 0,
        ], 'state');

        return $currentPlanetCount;
    }
}
