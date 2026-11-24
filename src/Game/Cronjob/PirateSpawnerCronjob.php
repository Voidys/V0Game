<?php

namespace App\Game\Cronjob;

use App\Game\Core\Discord;
use App\Game\Core\Universe;
use App\Game\Engagement\Events\EventModifiers;

/**
 * Weekly pirate base generation (cronjob "pirates", off by default; Sunday 02:00): archives and replaces all personal (kind 0)
 * pirate bases (table pirate_bases) for universes with MODULE_PIRATE_BASES.
 */
class PirateSpawnerCronjob extends AbstractCronjobTask
{
    public function __construct(
        \App\Game\Core\Database $db,
        \App\Game\Core\ConfigRegistry $configs,
        \App\Game\Core\Singleton $state,
    ) {
        parent::__construct($db, $configs, $state);
        require_once $state->runtime->rootPath . 'config/game.php';
    }

    /**
     * Copies the current bases to pirate_bases_history and deletes them. Then, per universe and for every player who
     * was online within INACTIVE (7 days) and has at least piratebase_min_points points, creates one base in a random
     * planet's galaxy/system of that player: ships are a random share (piratebase_fleet_percentage_min..max percent,
     * at least 1) of the player's total ships (planets plus flying fleets) except probes, solar satellites and void
     * titans; resources are the production of piratebase_ress_hours hours (main planet only if
     * piratebase_mainplanet_only). Errors are logged and sent to Discord.
     */
    public function run(): void
    {
        try {
            // only the personal bases (kind 0); derelicts and the mothership of the map content features live in the same table
            $this->db->insert("INSERT INTO %%PIRATE_BASES_HISTORY%%
                (`id`, `id_owner`, `universe`, `galaxy`, `system`, `planet_type`, `metal`, `crystal`, `deuterium`, `ships`, `der_metal`, `der_crystal`, `timestamp`)
                SELECT `id`, `id_owner`, `universe`, `galaxy`, `system`, `planet_type`, `metal`, `crystal`, `deuterium`, `ships`, `der_metal`, `der_crystal`, :timestamp
                FROM %%PIRATE_BASES%% WHERE `kind` = 0;", [
                ':timestamp' => $this->state->runtime->timestamp,
            ]);
            $this->db->nativeQuery("DELETE FROM %%PIRATE_BASES%% WHERE `kind` = 0;");

            $excludedShips = [
                SHIP_PROBE, // Spy Probe
                SHIP_SOLSAT, // Solar Satellite
                VOID_TITAN, // Void Titan
            ];

            $universes = $this->universe()->availableUniverses();
            foreach ($universes as $universe) {
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES, $universe)) {
                    continue;
                }
                $config = $this->configs->get($universe);

                $users = $this->getUsers($universe, $config->piratebase_min_points);
                // universe events (pirate surge): stronger bases (share of the player's ships, at most 100 %) with more loot
                $strength = $this->eventModifiers()->factor($universe, EventModifiers::PIRATE_STRENGTH);
                $loot = $this->eventModifiers()->factor($universe, EventModifiers::PIRATE_LOOT);
                $percentMin = min(100, (int) round($config->piratebase_fleet_percentage_min * $strength));
                $percentMax = min(100, max($percentMin, (int) round($config->piratebase_fleet_percentage_max * $strength)));

                foreach ($users as $user) {
                    // one failing player must not abort the run (the bases written so far stay valid)
                    try {
                        $planets = $this->getUserPlanets($user['id']);
                        if ($planets === []) {
                            continue;
                        }
                        $userShips = $this->getUserShips($user['id'], $planets);

                        $pirateFleet = $this->getPirateFleet($userShips, $excludedShips, $percentMin, $percentMax);
                        if ($pirateFleet === []) {
                            continue; // no ships: an empty base would only hand out free resources
                        }
                        $targetPlanet = $planets[random_int(0, count($planets) - 1)];
                        $ress = $this->getPirateRess($planets, $user['id_planet'], $config->piratebase_mainplanet_only, $config->piratebase_ress_hours);
                        foreach ($ress as $resourceId => $amount) {
                            $ress[$resourceId] = $amount * $loot;
                        }

                        $this->createPirateBase($targetPlanet, $pirateFleet, $ress, $user);
                    } catch (\Throwable $e) {
                        error_log("Error in PirateSpawnerCronjob for user " . $user['id'] . ": " . $e->getMessage());
                        $this->discord()->sendLog('PirateSpawnerCronjob', null, $e);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Error in PirateSpawnerCronjob: " . $e->getMessage());
            $this->discord()->sendLog('PirateSpawnerCronjob', null, $e);
        }
    }

    /**
     * Inserts the base (owner = the targeted player, coordinates of $targetPlanet, resources, serialized ships, empty debris).
     *
     * @param array<mixed> $pirateFleet
     * @param array<mixed> $ress
     * @param array<string, mixed> $targetPlanet
     * @param array<string, mixed> $user
     */
    private function createPirateBase(array $targetPlanet, array $pirateFleet, array $ress, array $user): void
    {
        $sql = "INSERT INTO %%PIRATE_BASES%% SET
            `id_owner`      = :idOwner,
            `universe`      = :universe,
            `galaxy`        = :galaxy,
            `system`        = :system,
            `metal`         = :metal,
            `crystal`       = :crystal,
            `deuterium`     = :deuterium,
            `ships`         = :fleetArray,
            `der_metal`     = :derMetal,
            `der_crystal`   = :derCrystal;";
        $this->db->insert($sql, [
            ':idOwner'      => $user['id'],
            ':universe'     => $targetPlanet['universe'],
            ':galaxy'       => $targetPlanet['galaxy'],
            ':system'       => $targetPlanet['system'],
            ':metal'        => $ress[RESOURCE_METAL],
            ':crystal'      => $ress[RESOURCE_CRYSTAL],
            ':deuterium'    => $ress[RESOURCE_DEUT],
            ':fleetArray'   => $this->fleetFunctions()->serialize($pirateFleet),
            ':derMetal'     => 0,
            ':derCrystal'   => 0,
        ]);
    }

    /**
     * Resources of a base: hourly production of the player's planets (or only the main planet) times $hours.
     * @param array<mixed> $planets
     * @return array<int,int|float> resource id => amount.
     */
    private function getPirateRess(array $planets, int $mainPlanetId, bool $mainPlanetOnly, int $hours): array
    {
        $ress = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];
        foreach ($planets as $planet) {
            if ($mainPlanetOnly && $planet['id'] != $mainPlanetId) {
                continue;
            }

            $ress[RESOURCE_METAL] += $planet['metal_perhour'] * $hours;
            $ress[RESOURCE_CRYSTAL] += $planet['crystal_perhour'] * $hours;
            $ress[RESOURCE_DEUT] += $planet['deuterium_perhour'] * $hours;
        }
        return $ress;
    }

    /**
     * Enemy fleet: per ship type max(1, floor(amount * random percentage between min and max / 100)), excluded types skipped.
     * @param array<mixed> $excludedShips
     * @param array<mixed> $userShips
     * @return array<int,int|float> ship id => amount.
     */
    private function getPirateFleet(array $userShips, array $excludedShips, int $minPercentage, int $maxPercentage): array
    {
        $pirateFleet = [];
        foreach ($userShips as $elementId => $amount) {
            if (in_array($elementId, $excludedShips)) {
                continue;
            }
            $percentage = random_int($minPercentage, $maxPercentage);
            $spawnAmount = max(1, floor($amount * ($percentage / 100)));
            $pirateFleet[$elementId] = $spawnAmount;
        }
        return $pirateFleet;
    }

    /**
     * Total ships of a player by ship id: stationed on all given planets plus those in own flying fleets.
     * @param array<int, array<string, mixed>> $planets planet rows of the player.
     * @return array<mixed>
     */
    private function getUserShips(int $userId, array $planets): array
    {
        $elements = $this->elements();

        $sql = "SELECT `ships` FROM %%FLEETS%% WHERE `owner` = :userId;";
        $fleets = $this->db->select($sql, [
            ':userId' => $userId,
        ]);

        $userShips = [];
        foreach ($elements->ids('fleet') as $elementId) {
            if (!isset($userShips[$elementId])) {
                $userShips[$elementId] = 0;
            }
            foreach ($planets as $planet) {
                $userShips[$elementId] += $planet[$elements->column($elementId)];
            }
        }
        foreach ($fleets as $fleet) {
            $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
            foreach ($fleetArray as $elementId => $amount) {
                // only real ships (missile fleets carry 502/503, which are not in the fleet list)
                if (isset($userShips[$elementId])) {
                    $userShips[$elementId] += $amount;
                }
            }
        }

        $userShips = array_filter($userShips);
        return $userShips;
    }

    /**
     * Planets of the player that are not marked for destruction.
     *
     * @return array<mixed>
     */
    private function getUserPlanets(int $userId): array
    {
        $sql = "SELECT * FROM %%PLANETS%% WHERE `id_owner` = :userId AND destruyed = 0;";
        return $this->db->select($sql, [
            ':userId' => $userId,
        ]);
    }

    /**
     * Players of the universe that were online within INACTIVE and have at least $minPoints total points (stat type 1 = total).
     *
     * @return array<mixed>
     */
    private function getUsers(int $universe, int $minPoints): array
    {
        $sql = "SELECT u.id, u.id_planet, us.lang FROM %%USERS%% u
            JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1
            JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
            WHERE u.onlinetime > :time AND u.universe = :universe AND s.total_points >= :minPoints;";
        return $this->db->select($sql, [
            ':time'      => $this->state->runtime->timestamp - INACTIVE,
            ':universe'  => $universe,
            ':minPoints' => $minPoints,
        ]);
    }
}
