<?php

namespace App\Game\Core\Player;

use App\Game\Enum\PlanetType;
use Exception;
use App\Game\Core\Database;
use App\Game\Core\Config;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Language;
use App\Game\Core\Singleton;
use App\Game\Core\GameUtilities;

/**
 * Creates and deletes planets and moons. Collaborators: PlanetPositions (slot validation),
 * ConfigRegistry (universe settings and start resources), Database, PlayerUtilAchievement/FleetFunctions
 * (reached via GameUtilities).
 */
class PlanetManager
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Position-band planet data (size, temperature, resource bonus per planet position): the generic rows
     * (universe 0) overridden per position by the rows of the current universe.
     *
     * @return array<int, array> rows of planet_data, re-indexed from 0
     */
    public function getPlanetDataPlanetConfig(): array
    {
        $db = $this->db;
        $currentUniverse = (int) $this->universe()->current();

        // the generic rows (universe 0) and the ones of this universe in one query
        $sql = "SELECT * FROM %%PLANET_DATA%% WHERE universe IN (0, :universe)";
        $rows = $db->select($sql, [
            ':universe' => $currentUniverse,
        ]);
        $planetDataBackend = [];
        $planetDataBackend_universe = [];
        foreach ($rows as $row) {
            if ((int) $row['universe'] === 0) {
                $planetDataBackend[] = $row;
            }
            if ((int) $row['universe'] === $currentUniverse) {
                $planetDataBackend_universe[] = $row;
            }
        }

        // Step 1: Re-index generic array by `planet_position`
        $genericByPosition = [];
        foreach ($planetDataBackend as $entry) {
            $genericByPosition[$entry['planet_position']] = $entry;
        }

        // Step 2: Replace entries in the indexed array with specific entries based on `planet_position`
        foreach ($planetDataBackend_universe as $specificEntry) {
            $positionSorted = $specificEntry['planet_position'];
            // If the position exists in the generic array, replace it
            $genericByPosition[$positionSorted] = $specificEntry;
        }

        // Step 3: Re-index the array by resetting the keys to a numeric index if needed
        $planetDataBackend = array_values($genericByPosition);

        return $planetDataBackend;
    }

    /**
     * Creates a planet (planet_type 1) at a free position and returns its id.
     *
     * Size, temperature and resource bonus come from the position-based planet data (see
     * getPlanetDataPlanetConfig()). The home planet uses the universe defaults (initial_fields/initial_temp);
     * the first colony gets the average of its position band, every further colony a random value in the band.
     * Picks a planet picture, preferring ones the player does not have yet when all_planet_pictures is on.
     * Side effects: INSERT into planets, PlayerUtilAchievement check 40 (home galaxy / colony galaxy).
     *
     * @param int $galaxy
     * @param int $system
     * @param int $planet slot
     * @param int $universe
     * @param int $userId owner
     * @param string|null $name default: translated main planet / colony name
     * @param bool $isHome true for the player's home planet
     * @param int $authlevel unused, kept for the signature
     * @return int id of the new planet
     * @throws Exception when the position is outside the universe or already taken
     */
    public function createPlanet(
        int $galaxy,
        int $system,
        int $planet,
        int $universe,
        int $userId,
        ?string $name = null,
        bool $isHome = false,
        int $authlevel = 0,
    ): int {
        if ($this->planetPositions()->checkPosition($universe, $galaxy, $system, $planet) === false) {
            throw new Exception(
                sprintf("Try to create a planet at position: %s:%s:%s!", $galaxy, $system, $planet),
            );
        }

        if ($this->planetPositions()->isPositionFree($universe, $galaxy, $system, $planet) === false) {
            throw new Exception(
                sprintf("Position is not empty: %s:%s:%s!", $galaxy, $system, $planet),
            );
        }
        $db = $this->db;

        $sql = 'SELECT count(`id`) as anz FROM %%PLANETS%% WHERE `id_owner` = :userId AND `planet_type` = 1';
        $planetArray = $db->selectSingle($sql, [
            ':userId' => $userId,
        ]);

        $planetData = [];
        require 'src/Game/Support/planet_data.php';

        $planetDataBackend = $this->getPlanetDataPlanetConfig();

        $config = $this->configs->get($universe);
        $dataIndex = (int) ceil($planet / ($config->max_planets / count($planetDataBackend)));

        [$maxFields, $maxTemperature, $metalBonusPercent, $crystalBonusPercent, $deuteriumBonusPercent]
            = $this->planetSizeAndBonus($isHome, $planetArray, $planetDataBackend[$dataIndex - 1] ?? null, $config);

        $sql = 'SELECT `galaxy` FROM %%USERS%% WHERE `id` = :userId;';
        $hpGala = $db->selectSingle($sql, [
            ':userId' => $userId,
        ], 'galaxy');
        $this->achievementGuard()->run(fn() => $this->playerUtilAchievements()->checkPlayerUtilAchievements40($isHome, $hpGala, $userId, $galaxy, $config));
        $minTemperature = $maxTemperature - 40;

        $diameter = (int) floor(1000 * sqrt($maxFields));

        $imageName = $this->pickPlanetImage($planetData, $dataIndex, $userId, $config);

        if (empty($name)) {
            $LNG = $this->ownerLanguage($userId);
            $name = $isHome ? $LNG['fcm_mainplanet'] : $LNG['fcp_colony'];
        }

        return $this->afterColonyCreated($this->insertPlanetRow([
            ':name'                     => $name,
            ':universe'                 => $universe,
            ':userId'                   => $userId,
            ':galaxy'                   => $galaxy,
            ':system'                   => $system,
            ':planet'                   => $planet,
            ':updateTimestamp'          => $this->state->runtime->timestamp,
            ':type'                     => 1,
            ':imageName'                => $imageName,
            ':diameter'                 => $diameter,
            ':maxFields'                => $maxFields,
            ':minTemperature'           => $minTemperature,
            ':maxTemperature'           => $maxTemperature,
            ':metal_start'              => $config->metal_start,
            ':metalBonusPercent'        => $metalBonusPercent,
            ':crystal_start'            => $config->crystal_start,
            ':crystalBonusPercent'      => $crystalBonusPercent,
            ':deuterium_start'          => $config->deuterium_start,
            ':deuteriumBonusPercent'    => $deuteriumBonusPercent,
            ':creation_time'            => $this->state->runtime->timestamp,
        ]), $isHome, $maxFields, $userId);
    }

    /**
     * Colonial overlord (achievement 95) check for a freshly created colony that already has at least 200 fields.
     */
    private function afterColonyCreated(int $planetId, bool $isHome, int|float $maxFields, int $userId): int
    {
        if (!$isHome && $maxFields >= 200) {
            $this->achievementGuard()->run(fn() => $this->planetRessAchievements()->checkColonialOverlord($userId));
        }

        return $planetId;
    }

    /**
     * Field count, maximum temperature and resource bonuses of a new planet.
     *
     * Home planet: universe defaults, no bonus. First colony (the user owns exactly one planet): average of the
     * position band. Later colonies: random value inside the band (random_int, so not reproducible).
     * Resource bonuses apply only when planet_ressource_bonus is enabled.
     *
     * @param bool $isHome
     * @param array<mixed>|null $planetArray row with `anz` = number of planets the user owns
     * @param array<mixed>|null $band planet data row of the position (fields_min/max, temp_min/max, *BonusPercent)
     * @param Config $config universe configuration
     * @return array{0: int, 1: int, 2: mixed, 3: mixed, 4: mixed} maxFields, maxTemperature, metal, crystal, deuterium bonus percent
     */
    private function planetSizeAndBonus(bool $isHome, ?array $planetArray, ?array $band, Config $config): array
    {
        $positionBasedRessourceBonus = $config->planet_ressource_bonus;

        $metalBonusPercent = 0;
        $crystalBonusPercent = 0;
        $deuteriumBonusPercent = 0;

        if ($isHome) {
            $maxFields = $config->initial_fields;
            $maxTemperature = $config->initial_temp;
        } elseif (!empty($planetArray) && $planetArray['anz'] == 1) {
            if ($positionBasedRessourceBonus) {
                $metalBonusPercent = $band['metalBonusPercent'];
                $crystalBonusPercent = $band['crystalBonusPercent'];
                $deuteriumBonusPercent = $band['deuteriumBonusPercent'];
            }
            $addToAvgSize = (int) floor(($band['fields_max'] - $band['fields_min']) / 2);
            $avgSize = $band['fields_min'] + $addToAvgSize;
            $maxFields = (int) floor($avgSize * $config->planet_size_factor);
            $addToAvgTemperature = (int) floor(($band['temp_max'] - $band['temp_min']) / 2);
            $maxTemperature = $band['temp_min'] + $addToAvgTemperature;
        } else {
            if ($positionBasedRessourceBonus) {
                $metalBonusPercent = $band['metalBonusPercent'];
                $crystalBonusPercent = $band['crystalBonusPercent'];
                $deuteriumBonusPercent = $band['deuteriumBonusPercent'];
            }
            $maxFields = (int) floor(random_int($band['fields_min'], $band['fields_max']) * $config->planet_size_factor);
            $maxTemperature = random_int($band['temp_min'], $band['temp_max']);
        }

        return [$maxFields, $maxTemperature, $metalBonusPercent, $crystalBonusPercent, $deuteriumBonusPercent];
    }

    /**
     * Chooses the image name of a new planet (e.g. "wasserplanet05").
     *
     * With all_planet_pictures enabled it retries up to MAXPLANETPICTURECOUNT times to avoid an image the
     * owner already uses on a non-destroyed planet.
     *
     * @param array<mixed> $planetData picture table per position band (from planet_data.php)
     * @param int $dataIndex index of the position band
     * @param int $userId owner, whose existing pictures are avoided
     * @param Config $config universe configuration
     * @return string image name
     */
    private function pickPlanetImage(array $planetData, int $dataIndex, int $userId, Config $config): string
    {
        $sql = 'SELECT `image` FROM %%PLANETS%% WHERE `id_owner` = :userId AND `destruyed` = 0';
        $planetPicturesAchieved = $this->db->select($sql, [
            ':userId' => $userId,
        ]);

        $planetPictures = [];
        foreach ($planetPicturesAchieved as $image => $value) {
            array_push($planetPictures, $value["image"]);
        }

        $enabledAllPlanetPictures = $config->all_planet_pictures;
        $maxImages = 0;
        do {
            if ($enabledAllPlanetPictures) {
                $planetData[$dataIndex]['image'] = getAllPlanetPictures();
            }
            $imageNames = array_keys($planetData[$dataIndex]['image']);
            $imageNameType = $imageNames[array_rand($imageNames)];
            $imageName = $imageNameType;
            $imageName .= 'planet';
            $imageName .= $planetData[$dataIndex]['image'][$imageNameType] < 10 ? '0' : '';
            $imageName .= $planetData[$dataIndex]['image'][$imageNameType];
            $maxImages++;
        } while (in_array($imageName, $planetPictures) && ($maxImages < MAXPLANETPICTURECOUNT) && ($enabledAllPlanetPictures));

        return $imageName;
    }

    /**
     * Inserts a planet row and returns the new id.
     *
     * @param array<string, mixed> $params bound parameters of the INSERT (see createPlanet())
     * @return int id of the new planet
     */
    private function insertPlanetRow(array $params): int
    {
        $sql = 'INSERT INTO %%PLANETS%% SET
            `name`		                = :name,
            `universe`                  = :universe,
            `id_owner`                  = :userId,
            `galaxy`		            = :galaxy,
            `system`		            = :system,
            `planet`		            = :planet,
            `last_update`               = :updateTimestamp,
            `planet_type`               = :type,
            `image`		                = :imageName,
            `diameter`                  = :diameter,
            `field_max`                 = :maxFields,
            `temp_min` 	                = :minTemperature,
            `temp_max` 	                = :maxTemperature,
            `metal`		                = :metal_start,
            `metal_bonus_percent`       = :metalBonusPercent,
            `crystal`		            = :crystal_start,
            `crystal_bonus_percent`     = :crystalBonusPercent,
            `deuterium`                 = :deuterium_start,
            `deuterium_bonus_percent`   = :deuteriumBonusPercent,
            `creation_time`             = :creation_time;';

        $this->db->insert($sql, $params);

        return $this->db->lastInsertId();
    }

    /** @var array<string, Language> languages loaded for default names, by language code */
    private array $ownerLanguages = [];

    /**
     * Language of the player a planet or moon is created for (default names are in the owner's language, not in the
     * language of whoever triggers the creation: an admin, a cron run or the other side of a fight). Falls back to the
     * request's language.
     */
    private function ownerLanguage(int $userId): mixed
    {
        $current = $this->state->LNG;
        $lang = (string) $this->db->selectSingle('SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;', [
            ':userId' => $userId,
        ], 'lang');
        if ($lang === '' || !$current instanceof Language || $current->getLanguage() === $lang) {
            return $current;
        }

        if (!isset($this->ownerLanguages[$lang])) {
            $language = new Language($this->configs, $lang);
            $language->includeData(['L18N', 'INGAME', 'CUSTOM']);
            $this->ownerLanguages[$lang] = $language;
        }

        return $this->ownerLanguages[$lang];
    }

    /**
     * Creates the moon of a planet and links it (parent planet id_luna).
     *
     * Diameter derives from the chance unless given; temperatures are the parent planet's minus a random 10..45.
     * Returns false (0 under the int return type) when the planet already has a moon.
     *
     * @param int $universe
     * @param int $galaxy
     * @param int $system
     * @param int $planet slot of the parent planet
     * @param int $userId owner
     * @param float $chance moon chance in percent, drives the diameter
     * @param string|null $moonName default: translated "Moon"
     * @param int|null $diameter fixed diameter
     * @return int id of the moon
     */
    public function createMoon(
        int $universe,
        int $galaxy,
        int $system,
        int $planet,
        int $userId,
        float $chance,
        ?string $moonName = null,
        ?int $diameter = null,
    ): int {
        $db = $this->db;

        $sql = 'SELECT `id_luna`, `planet_type`, `id`, `name`, `temp_max`, `temp_min`
            FROM %%PLANETS%%
            WHERE `universe` = :universe
            AND `galaxy` = :galaxy
            AND `system` = :system
            AND `planet` = :planet
            AND `planet_type` = :type
            AND `destruyed` = 0;';

        $parentPlanet = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':galaxy'   => $galaxy,
            ':system'   => $system,
            ':planet'   => $planet,
            ':type'     => 1,
        ]);

        // no parent (abandoned, destroyed or gone) or it has a moon already
        if (empty($parentPlanet) || $parentPlanet['id_luna'] != 0) {
            return false;
        }

        $config = $this->configs->get($universe);
        $moon_size_factor = $config->moon_size_factor;

        if (is_null($diameter)) {
            $diameter = floor(pow(random_int(10, 20) + 3 * $chance, 0.5) * 1000 * $moon_size_factor);
        }

        $maxTemperature = $parentPlanet['temp_max'] - random_int(10, 45);
        $minTemperature = $parentPlanet['temp_min'] - random_int(10, 45);

        if (empty($moonName)) {
            $moonName = $this->ownerLanguage($userId)['type_planet_3'] ?? 'Mond';
        }

        $moonId = $this->insertMoonRow([
            ':name'             => $moonName,
            ':owner'            => $userId,
            ':universe'         => $universe,
            ':galaxy'           => $galaxy,
            ':system'           => $system,
            ':planet'           => $planet,
            ':updateTimestamp'  => $this->state->runtime->timestamp,
            ':type'             => 3,
            ':image'            => 'mond0' . rand(1, 9),
            ':diameter'         => $diameter,
            ':fields'           => 1,
            ':minTemperature'   => $minTemperature,
            ':maxTemperature'   => $maxTemperature,
            ':metal'            => 0,
            ':metPerHour'       => 0,
            ':crystal'          => 0,
            ':cryPerHour'       => 0,
            ':deuterium'        => 0,
            ':deuPerHour'       => 0,
        ]);

        $sql = 'UPDATE %%PLANETS%% SET `id_luna` = :moonId WHERE `id` = :planetId;';

        $db->update($sql, [
            ':moonId'   => $moonId,
            ':planetId' => $parentPlanet['id'],
        ]);

        return $moonId;
    }

    /**
     * Inserts a moon row (planet_type 3) and returns its id.
     *
     * @param array<string, mixed> $params bound parameters of the INSERT (see createMoon())
     * @return int id of the new moon
     */
    private function insertMoonRow(array $params): int
    {
        $sql = 'INSERT INTO %%PLANETS%% SET
            `name`				= :name,
            `id_owner`			= :owner,
            `universe`			= :universe,
            `galaxy`			= :galaxy,
            `system`			= :system,
            `planet`			= :planet,
            `last_update`		= :updateTimestamp,
            `planet_type`		= :type,
            `image`				= :image,
            `diameter`			= :diameter,
            `field_max`			= :fields,
            `temp_min`			= :minTemperature,
            `temp_max`			= :maxTemperature,
            `metal`				= :metal,
            `metal_perhour`		= :metPerHour,
            `crystal`			= :crystal,
            `crystal_perhour`	= :cryPerHour,
            `deuterium`			= :deuterium,
            `deuterium_perhour` = :deuPerHour;';

        $this->db->insert($sql, $params);

        return $this->db->lastInsertId();
    }

    /**
     * Deletes a planet or moon.
     *
     * Fleets heading there are sent back. A moon row is removed and the parent unlinked. A planet is only marked
     * destroyed (destruyed = now + 24h), its moon and wreck field are deleted and the deletion achievement check runs.
     * The main planet (users.id_planet) cannot be deleted. Side effect: the session's planet id is reset.
     *
     * @param int $planetId
     * @return bool true
     * @throws Exception if the planet does not exist or is a main planet
     */
    public function deletePlanet(int $planetId): bool
    {
        $db = $this->db;
        $sql = 'SELECT `id_owner`, `planet_type`, `id_luna` FROM %%PLANETS%%
                    WHERE `id` = :planetId AND `id` NOT IN (SELECT `id_planet` FROM %%USERS%%);';
        $planetData = $db->selectSingle($sql, [
            ':planetId' => $planetId,
        ]);

        if (empty($planetData)) {
            throw new Exception("Can not found planet #" . $planetId . "!");
        }

        $sql = 'SELECT `id`, `owner` FROM %%FLEETS%% WHERE `end_id` = :planetId
                    OR (`end_type` = :type AND `end_id` = :moondId);';
        $fleetIds = $db->select($sql, [
            ':planetId' => $planetId,
            ':type'     => PlanetType::Moon->value,
            ':moondId'  => $planetData['id_luna'],
        ]);

        // every fleet heading for the planet or its moon turns around, foreign ones too (each is recalled in its owner's name)
        foreach ($fleetIds as $fleetId) {
            $this->fleetFunctions()->sendFleetBack(['id' => $fleetId['owner']], $fleetId['id']);
        }

        if ($planetData['planet_type'] == PlanetType::Moon->value) {
            $sql = 'DELETE FROM %%PLANETS%% WHERE id = :planetId;';
            $db->delete($sql, [
                ':planetId' => $planetId,
            ]);

            $sql = 'UPDATE %%PLANETS%% SET `id_luna` = :resetId WHERE `id_luna` = :planetId;';
            $db->update($sql, [
                ':resetId'  => 0,
                ':planetId' => $planetId,
            ]);
        } else {
            $this->achievementGuard()->run(fn() => $this->playerUtilAchievements()->checkPlayerUtilAchievementsDeletion($planetData, $planetId));
            // relics on the planet go back to the owner's storage
            $this->relicInventory()->releasePlanet($planetId);
            $sql = 'UPDATE %%PLANETS%% SET `destruyed` = :time WHERE `id` = :planetID;';
            $db->update($sql, [
                ':time'   => $this->state->runtime->timestamp + 86400,
                ':planetID' => $planetId,
            ]);
            $sql = 'DELETE FROM %%PLANETS%% WHERE `id` = :lunaID;';
            $db->delete($sql, [
                ':lunaID' => $planetData['id_luna'],
            ]);
            $sql = 'DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;';
            $db->delete($sql, [
                ':planetID' => $planetId,
            ]);
        }
        // no session reset here: this also runs in console fleet processing (moon destruction), where there is no
        // session, and for admins deleting someone else's planet; the owner abandoning a planet selects the main
        // planet in OverviewController, and a session still pointing at a deleted planet falls back to the main one
        return true;
    }

    /**
     * Delete all Planets of players and recreate them according to the algorithm, currently selected.
     *
     * @param int	 		$universe       The id of the Universe
     */

    /**
     * Sets resources, ships, defense and the hangar queue to zero on every planet of a user (reset helper).
     *
     * @param array<string, mixed> $USER user row
     */
    public function clearPlanets(array $USER): void
    {
        $elements = $this->elements();

        $db = $this->db;

        // fixed assignments plus the resource, fleet and defense columns from the game's resource table
        $expressions = ['b_hangar' => '0', 'b_hangar_id' => "''"];
        $expressions[$elements->column(RESOURCE_METAL)] = '0';
        $expressions[$elements->column(RESOURCE_CRYSTAL)] = '0';
        $expressions[$elements->column(RESOURCE_DEUT)] = '0';

        foreach ($elements->ids('fleet') as $element) {
            $expressions[$elements->column($element)] = '0';
        }

        foreach ($elements->ids('defense') as $element) {
            $expressions[$elements->column($element)] = '0';
        }

        $db->updateRows('PLANETS', [], ['id_owner' => $USER['id']], $expressions);
    }

    private function planetPositions(): PlanetPositions
    {
        return $this->gameUtility(PlanetPositions::class);
    }
}
