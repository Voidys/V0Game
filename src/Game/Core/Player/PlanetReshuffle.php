<?php

namespace App\Game\Core\Player;

use App\Game\Enum\AuthLevel;
use Exception;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Admin operation "reshuffle all planets": removes every planet of the regular players of a closed universe
 * and gives each a new home planet at a new position. Collaborators: PlanetPositions (new positions),
 * PlanetManager (planet creation), Language (home planet names in the player's language).
 */
class PlanetReshuffle
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Deletes every planet of all regular players of a universe and gives each a new home planet.
     *
     * Only allowed for an admin while the universe is closed, no regular player owns more than one planet and no fleet of
     * or to a regular player is in flight.
     * Side effects: sets onlinetime of all users to now, deletes planets (also moons/colonies), creates new
     * home planets, deletes their wreck fields, resets and saves last_galaxy_pos/last_system_pos, updates users' home coordinates.
     *
     * @param int $universe
     * @return string|bool true on success, otherwise the translated error text
     * @throws Exception on invalid generated positions
     */
    public function reshuffleAllPlanets(int $universe): string|bool
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $config = $this->configs->get($universe);
        // only the planets that are reshuffled count: regular players' planets (no moons, no destroyed ones, no staff/bots)
        $sql = 'SELECT MAX(count) AS max FROM(SELECT count(*) AS count, p.`id_owner` FROM %%PLANETS%% p
            INNER JOIN %%USERS%% u ON u.`id` = p.`id_owner`
            WHERE p.`universe` = :uni AND p.`planet_type` = 1 AND p.`destruyed` = 0 AND u.`authlevel` = :auth GROUP BY p.`id_owner`) AS plani;';
        $maxPlanetAmount = $db->selectSingle($sql, [
            ':uni' => $universe,
            ':auth' => AuthLevel::User->value,
        ], 'max');
        // fleets of (or heading to) the regular players would point to planets that are about to vanish
        $sql = 'SELECT COUNT(*) AS count FROM %%FLEETS%% WHERE `universe` = :uni AND (
            `owner` IN (SELECT `id` FROM %%USERS%% WHERE `authlevel` = :auth) OR `target_owner` IN (SELECT `id` FROM %%USERS%% WHERE `authlevel` = :auth));';
        $fleetsInFlight = (int) $db->selectSingle($sql, [
            ':uni' => $universe,
            ':auth' => AuthLevel::User->value,
        ], 'count');
        $uniStatus = $config->uni_status;

        if ($maxPlanetAmount > 1 || $fleetsInFlight > 0 || $uniStatus != STATUS_CLOSED || $USER['authlevel'] != AuthLevel::Admin->value) {
            $result = "";
            $LNG = $this->state->LNG;
            if ($maxPlanetAmount > 1) {
                $result = $LNG['rp_planet_error'];
            } elseif ($fleetsInFlight > 0) {
                $result = $LNG['rp_fleets_error'];
            } elseif ($uniStatus != STATUS_CLOSED) {
                $result = $LNG['rp_universe_error'];
            } elseif ($USER['authlevel'] != AuthLevel::Admin->value) {
                $result = $LNG['rp_rights_error'];
            }
            return $result;
        }
        $sql = 'UPDATE %%USERS%% SET `onlinetime` = :onlinetime WHERE `universe` = :universe;';
        $db->update($sql, [
            ':onlinetime' => $this->state->runtime->timestamp,
            ':universe' => $universe,
        ]);
        $sql = 'SELECT `id` FROM %%USERS%% WHERE `authlevel` = :auth AND `universe` = :universe ORDER BY `id`;';
        $userIds = $db->select($sql, [
            ':auth'      => AuthLevel::User->value,
            ':universe' => $universe,
        ]);
        foreach ($userIds as $userId) {
            $sql = 'DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` IN (SELECT `id` FROM %%PLANETS%% WHERE `id_owner` = :userId);';
            $db->delete($sql, [
                ':userId'   => $userId['id'],
            ]);
            // relics return to storage (the planets are deleted)
            (new \App\Game\Engagement\Relics\RelicInventory($this->db, $this->configs, $this->state))->releaseUser((int) $userId['id']);
            $sql = 'DELETE FROM %%PLANETS%% WHERE `id_owner` = :userId;';
            $db->delete($sql, [
                ':userId'   => $userId['id'],
            ]);
        }
        $config->last_galaxy_pos = 1;
        $config->last_system_pos = 1;
        foreach ($userIds as $userId) {
            $this->placeNewHomePlanet($universe, (int) $userId['id']);
        }
        $config->save(); // the position counters (last_*_pos) were advanced by the placement
        return true;
    }

    /**
     * Gives one player a fresh home planet at a new position (used by reshuffleAllPlanets()).
     *
     * Side effects: switches the shared language to the player's language, INSERT planet, UPDATE the user's
     * home coordinates and id_planet.
     *
     * @param int $universe
     * @param int $userId
     * @throws Exception when the generated position is invalid or taken
     */
    private function placeNewHomePlanet(int $universe, int $userId): void
    {
        $db = $this->db;
        $pos = $this->planetPositions()->getNewPlanetPosition($universe);

        if (isset($universe, $pos['galaxy'], $pos['system'], $pos['planet'])) {
            if ($this->planetPositions()->checkPosition($universe, $pos['galaxy'], $pos['system'], $pos['planet']) === false) {
                throw new Exception(
                    sprintf("Try to create a planet at position: %s:%s:%s!", $pos['galaxy'], $pos['system'], $pos['planet']),
                );
            }

            if ($this->planetPositions()->isPositionFree($universe, $pos['galaxy'], $pos['system'], $pos['planet']) === false) {
                throw new Exception(
                    sprintf("Position is not empty: %s:%s:%s!", $pos['galaxy'], $pos['system'], $pos['planet']),
                );
            }
        }
        $sql = 'SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
        $lang = $db->selectSingle($sql, [
            ':userId' => $userId,
        ], 'lang');
        $LNG    = new Language($this->configs, $lang);
        $this->state->LNG = $LNG;
        $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        $name = $LNG['fcm_mainplanet'];
        $planetId = $this->planetManager()->createPlanet(
            $pos['galaxy'],
            $pos['system'],
            $pos['planet'],
            $universe,
            $userId,
            $name,
            true,
        );
        $sql = 'UPDATE %%USERS%% SET
            `galaxy` = :galaxy,
            `system` = :system,
            `planet` = :planet,
            `id_planet` = :planetId
            WHERE `id` = :userId;';

        $db->update($sql, [
            ':galaxy'   => $pos['galaxy'],
            ':system'   => $pos['system'],
            ':planet'   => $pos['planet'],
            ':planetId' => $planetId,
            ':userId'   => $userId,
        ]);
    }

    private function planetPositions(): PlanetPositions
    {
        return $this->gameUtility(PlanetPositions::class);
    }

    private function planetManager(): PlanetManager
    {
        return $this->gameUtility(PlanetManager::class);
    }
}
