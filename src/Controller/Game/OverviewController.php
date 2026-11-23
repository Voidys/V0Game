<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Game\Enum\PlanetType;
use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\FlyingFleetsTable;
use App\Game\Core\LiveEventsPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Game\Core\Universe;

/**
 * Planet overview: planet data, fleet events, news and rank; planet actions, rename and give-up (modes: show,
 * actions, rename, delete).
 */
class OverviewController extends AbstractGameController
{
    private function getFleets()
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $fleetTableObj = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $fleetTableObj->setUser($USER['id']);
        $fleetTableObj->setPlanet($PLANET['id']);
        return $fleetTableObj->renderTable();
    }

    /**
     * Planet overview: the planet and its moon (or parent planet), the running building, research and hangar entries, the wreck field with repair state,
     * the fleet movements of the table (FlyingFleetsTable), own rank and points, universe counters (players online in the last 15 minutes, fleets
     * in flight), online admins and the outlaw state. No request parameters besides the standard cp= planet switch.
     */
    #[GameRoute('game', 'game', 'overview')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $USER = $this->state->USER;

        $AllPlanets = $this->otherPlanets($USER, $PLANET);
        $Moon = $this->moonOf($PLANET, $USER);

        $buildInfo['buildings'] = (!empty($PLANET['b_building']) && !empty($PLANET['b_building_id']))
            ? $this->buildingEntry($PLANET, $USER)
            : false;
        $buildInfo['fleet'] = $this->hangarEntry($PLANET, $USER);
        $buildInfo['tech'] = $this->researchEntry($USER);
        $buildInfo['repair'] = $this->repairEntry($PLANET, $USER);

        $AdminsOnline = $this->adminsOnline();

        $config = $this->configs->get();
        $rankInfo = $this->rankInfo($USER, $config);

        $enablePlanetStyles = $USER['enable_planet_styles'];

        $outlawTime = $this->outlawUntil($USER);

        return $this->render('game/page.overview.default.html.twig', [
            'umode'                     => $USER['urlaubs_modus'],
            'rankInfo'                  => $rankInfo,
            'recentNews'                => $this->recentNews(),
            'planetname'                => $PLANET['name'],
            'planetimage'               => $PLANET['image'],
            'planet_style'              => $PLANET['planet_style'],
            'galaxy'                    => $PLANET['galaxy'],
            'system'                    => $PLANET['system'],
            'planet'                    => $PLANET['planet'],
            'planet_type'               => $PLANET['planet_type'],
            'username'                  => $USER['username'],
            'userid'                    => $USER['id'],
            'buildInfo'                 => $buildInfo,
            'Moon'                      => $Moon,
            'fleets'                    => $this->getFleets(),
            'AllPlanets'                => $AllPlanets,
            'AdminsOnline'              => $AdminsOnline,
            'planet_diameter'           => $this->viewHelpers()->prettyNumber($PLANET['diameter']),
            'planet_field_current'      => $PLANET['field_current'],
            'planet_field_max'          => $this->planetQueries()->calculateMaxPlanetFields($PLANET),
            'planet_temp_min'           => $PLANET['temp_min'],
            'planet_temp_max'           => $PLANET['temp_max'],
            'ref_active'                => $config->ref_active,
            'servertime'                => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
            'path'                      => $this->state->runtime->httpPath,
            'colors'                    => $USER['colors'],
            'enablePlanetStyles'        => $enablePlanetStyles,
            'isCatchUp'                 => $config->catch_up,
            'catchUp'                   => $LNG->format('sys_catchup', round((1 - $this->protectionRules()->catchUpFactorFor($this->player())) * 100, 2)),
            'outlawTime'                => $outlawTime,
        ]);
    }

    /**
     * Template entry of the first running construction of a planet or moon row: building id, target level, time left
     * (counted from the vacation start in vacation mode), end time, formatted time left and build mode.
     *
     * @param array<string, mixed> $row planet row with b_building and b_building_id
     * @param array<string, mixed> $USER
     * @return array<string, mixed>
     */
    private function buildingEntry(array $row, array $USER): array
    {
        $Queue = StoredData::decode($row['b_building_id']);
        $timeleft = $USER['urlaubs_modus'] ? ($row['b_building'] - $USER['urlaubs_start']) : ($row['b_building'] - $this->state->runtime->timestamp);

        return [
            'id'        => $Queue[0][0],
            'level'     => $Queue[0][1],
            'timeleft'  => $timeleft,
            'time'      => $row['b_building'],
            'starttime' => $this->viewHelpers()->prettyTime($timeleft),
            'mode'      => $Queue[0][4],
        ];
    }

    /**
     * The player's other planets and moons for the planet list, each with its running construction (false when none or
     * already finished).
     *
     * @param array<string, mixed> $USER user with its PLANETS rows
     * @param array<string, mixed> $PLANET the current planet
     * @return list<array<string, mixed>>
     */
    private function otherPlanets(array $USER, array $PLANET): array
    {
        $AllPlanets = [];
        foreach ($USER['PLANETS'] as $ID => $CPLANET) {
            if ($ID == $PLANET['id']) {
                continue;
            }

            if (!empty($CPLANET['b_building']) && !empty($CPLANET['b_building_id']) && $CPLANET['b_building'] > $this->state->runtime->timestamp) {
                $BuildPlanet = $this->buildingEntry($CPLANET, $USER);
            } else {
                $BuildPlanet = false;
            }

            $AllPlanets[] = [
                'id'            => $CPLANET['id'],
                'name'          => $CPLANET['name'],
                'image'         => $CPLANET['image'],
                'planet_style'  => $CPLANET['planet_style'],
                'build'         => $BuildPlanet,
                'type'          => $CPLANET['planet_type'],
                'galaxy'        => $CPLANET['galaxy'],
                'system'        => $CPLANET['system'],
                'planet'        => $CPLANET['planet'],
            ];
        }

        return $AllPlanets;
    }

    /**
     * The moon of the current planet, or the parent planet when the current one is a moon, with its running construction
     * under 'build' (false when none). Empty array (or false from the lookup) when there is none.
     *
     * @param array<string, mixed> $PLANET the current planet
     * @param array<string, mixed> $USER
     * @return array<string, mixed>|false
     */
    private function moonOf(array $PLANET, array $USER): array|false
    {
        $db = $this->db;
        $Moon = [];
        if ($PLANET['id_luna'] != 0) {
            $sql = "SELECT id, name, image, planet_style, planet_type, b_building_id, b_building FROM %%PLANETS%% WHERE id = :lunaID;";
            $Moon = $db->selectSingle($sql, [':lunaID'   => $PLANET['id_luna']]);
        } elseif ($PLANET['planet_type'] == PlanetType::Moon->value) {
            $sql = "SELECT id, name, image, planet_style, planet_type, b_building_id, b_building FROM %%PLANETS%% WHERE id_luna = :id;";
            $Moon = $db->selectSingle($sql, [':id'   => $PLANET['id']]);
        }
        if (!empty($Moon)) {
            if (!empty($Moon['b_building']) && !empty($Moon['b_building_id']) && $Moon['b_building'] > $this->state->runtime->timestamp) {
                $Moon['build'] = $this->buildingEntry($Moon, $USER);
            } else {
                $Moon['build'] = false;
            }
        }

        return $Moon;
    }

    /**
     * Template entry of the first shipyard order of the planet (id, amount, time left of the whole order), false when idle.
     *
     * @param array<string, mixed> $PLANET
     * @param array<string, mixed> $USER
     * @return array<string, mixed>|false
     */
    private function hangarEntry(array $PLANET, array $USER): array|false
    {
        if (empty($PLANET['b_hangar_id'])) {
            return false;
        }

        $Queue  = StoredData::decode($PLANET['b_hangar_id']);
        $time   = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $Queue[0][0]) * $Queue[0][1];

        return [
            'id'        => $Queue[0][0],
            'level'     => $Queue[0][1],
            'timeleft'  => $time - $PLANET['b_hangar'],
            'time'      => $time,
            'starttime' => $this->viewHelpers()->prettyTime($time - $PLANET['b_hangar']),
        ];
    }

    /**
     * Template entry of the running research (id, level, time left; counted from the vacation start in vacation mode), false when idle.
     *
     * @param array<string, mixed> $USER
     * @return array<string, mixed>|false
     */
    private function researchEntry(array $USER): array|false
    {
        if (empty($USER['b_tech_id']) || empty($USER['b_tech_queue'])) {
            return false;
        }

        $timeleft = $USER['urlaubs_modus'] ? ($USER['b_tech'] - $USER['urlaubs_start']) : ($USER['b_tech'] - $this->state->runtime->timestamp);
        $Queue = StoredData::decode($USER['b_tech_queue']);

        return [
            'id'        => $Queue[0][0],
            'level'     => $Queue[0][1],
            'timeleft'  => $timeleft,
            'time'      => $USER['b_tech'],
            'starttime' => $this->viewHelpers()->prettyTime($timeleft),
        ];
    }

    /**
     * Wreck field repair state of the planet: while repairing the time until the repair is done, afterwards the
     * 72 hours the repaired ships can be deployed. False when no repair was ordered.
     *
     * @param array<string, mixed> $PLANET
     * @param array<string, mixed> $USER
     * @return array<string, mixed>|false
     */
    private function repairEntry(array $PLANET, array $USER): array|false
    {
        $LNG = $this->state->LNG;
        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfield = $this->db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        if (empty($wreckfield['repair_order'])) {
            return false;
        }

        $repairing = $wreckfield['repair_order_end'] > $this->state->runtime->timestamp;
        $timeleft = $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] - $this->state->runtime->timestamp);
        $deploy_timeleft = $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] + TIME_72_HOURS - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] + TIME_72_HOURS - $this->state->runtime->timestamp);

        return [
            'text'          => $LNG['bd_repairing'],
            'deploy_text'   => $LNG['bd_deployable'],
            'timeleft'      => $timeleft + ($repairing ? 0 : TIME_72_HOURS),
            'time'          => $wreckfield['repair_order_end'] + ($repairing ? 0 : TIME_72_HOURS),
            'starttime'     => $this->viewHelpers()->prettyTime($repairing ? $timeleft : $deploy_timeleft),
            'repairing'     => $repairing,
        ];
    }

    /**
     * Staff accounts of this universe that were online in the last 10 minutes.
     *
     * @return array<int, string> user id => username
     */
    private function adminsOnline(): array
    {
        $sql = "SELECT id,username FROM %%USERS%%
            WHERE universe = :universe AND onlinetime >= :onlinetime AND authlevel > :authlevel;";
        $onlineAdmins = $this->db->select($sql, [
            ':universe'     => $this->universe()->current(),
            ':onlinetime'   => $this->state->runtime->timestamp - 10 * 60,
            ':authlevel'    => AuthLevel::User->value,
        ]);

        $AdminsOnline = [];
        foreach ($onlineAdmins as $AdminRow) {
            $AdminsOnline[$AdminRow['id']]  = $AdminRow['username'];
        }

        return $AdminsOnline;
    }

    /**
     * Own points and rank for the template (it formats ov_userrank_info with them); null while the player is not ranked yet.
     *
     * @param array<string, mixed> $USER
     * @return array{points: string, rank: int|string, usersCount: mixed}|null
     */
    private function rankInfo(array $USER, object $config): ?array
    {
        $sql    = 'SELECT total_points, total_rank FROM %%STATPOINTS%%
		    WHERE id_owner = :userId AND stat_type = :statType';

        $statData   = $this->db->selectSingle($sql, [
            ':userId'   => $USER['id'],
            ':statType' => 1,
        ]);

        if (($statData['total_rank'] ?? 0) == 0) {
            return null;
        }

        return [
            'points'     => $this->viewHelpers()->prettyNumber($statData['total_points']),
            'rank'       => $statData['total_rank'],
            'usersCount' => $config->users_amount,
        ];
    }

    /**
     * Formatted end of the outlaw status: 7 days after the last activation when there were several, 2 days after the
     * only one; null when the status is over or was never active.
     *
     * @param array<string, mixed> $USER
     */
    private function outlawUntil(array $USER): ?string
    {
        $LNG = $this->state->LNG;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $outlawTime = null;
        $outlawActivations = $statsHandler->getValue($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
        if ($outlawActivations > 1 && $this->state->runtime->timestamp - $USER['outlaw_time'] <= 7 * TIME_24_HOURS) {
            $outlawTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['outlaw_time'] + 7 * TIME_24_HOURS, $USER['timezone']);
        } elseif ($outlawActivations == 1 && $this->state->runtime->timestamp - $USER['outlaw_time'] <= 2 * TIME_24_HOURS) {
            $outlawTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['outlaw_time'] + 2 * TIME_24_HOURS, $USER['timezone']);
        }

        return $outlawTime;
    }

    /**
     * Popup with the planet actions (rename, abandon); passes the confirmation text and whether this is the main planet.
     */
    #[GameRoute('game', 'game/overview/actions', 'overview', 'actions')]
    public function actions(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $this->setWindow('popup');


        return $this->render('game/page.overview.actions.html.twig', [
            'ov_security_confirm' => $LNG->format('ov_security_confirm', $PLANET['name'] . ' ['
                . $PLANET['galaxy'] . ':' . $PLANET['system'] . ':' . $PLANET['planet'] . ']'),
            'mainPlanet' => $USER['id_planet'],
            'currentPlanet' => $PLANET['id'],
        ]);
    }

    /**
     * Renames the current planet (XHR, request: name): at most 20 characters and valid characters; answers JSON {message, error}.
     */
    #[GameRoute('game', 'game/overview/rename', 'overview', 'rename', ['POST'])]
    public function rename(): Response
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $newname        = $this->input()->string('name', '', UTF8_SUPPORT);
        if (!empty($newname)) {
            if (mb_strlen($newname) > 20) {
                // PLANETS.name is varchar(20)
                return $this->jsonResponse(['message' => $LNG->format('ov_newname_too_long', 20), 'error' => true]);
            } elseif (!$this->playerRegistration()->isNameValid($newname, true)) {
                return $this->jsonResponse(['message' => $LNG['ov_newname_specialchar'], 'error' => true]);
            } else {
                $db = $this->db;
                $sql = "UPDATE %%PLANETS%% SET name = :newName WHERE id = :planetID;";
                $db->update($sql, [
                    ':newName'  => $newname,
                    ':planetID' => $PLANET['id'],
                ]);

                return $this->jsonResponse(['message' => $LNG['ov_newname_done'], 'error' => false]);
            }
        }

        return new Response();
    }

    /**
     * Abandons the current planet (XHR, request: password, which is actually the planet's name typed as confirmation). Refused while own fleets
     * start from the planet or its moon, and for the main planet. Research queued on the planet is re-homed to the main planet, then the planet is
     * deleted (PlanetManager::deletePlanet); answers JSON.
     */
    #[GameRoute('game', 'game/overview/delete', 'overview', 'delete', ['POST'])]
    public function delete(): Response
    {
        $LNG = $this->state->LNG;
        $PLANET = $this->state->PLANET;
        $password   = $this->input()->string('password', '', true);

        if (!empty($password)) {
            $db = $this->db;
            // the planet row is locked while the fleets are counted and the planet is removed: a fleet cannot be launched in between
            $db->startTransaction();
            // user row first (the research queue is rewritten below from the locked copy), then the planet
            $locked = $db->selectSingle('SELECT b_tech, b_tech_id, b_tech_planet, b_tech_queue FROM %%USERS%% WHERE id = :userID FOR UPDATE;', [':userID' => $this->state->USER['id']]);
            if (!empty($locked)) {
                $this->state->USER = array_merge($this->state->USER, $locked);
            }
            $db->selectSingle('SELECT id FROM %%PLANETS%% WHERE id = :planetID FOR UPDATE;', [':planetID' => $PLANET['id']]);
            $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE
                      (`owner` = :userID AND (`start_id` = :planetID OR `start_id` = :lunaID));";
            $IfFleets = $db->selectSingle($sql, [
                ':userID'   => $this->state->USER['id'],
                ':planetID' => $PLANET['id'],
                ':lunaID'   => $PLANET['id_luna'],
            ], 'state');

            if ($IfFleets > 0) {
                $db->rollback();
                return $this->jsonResponse(['message' => $LNG['ov_abandon_planet_not_possible']]);
            } elseif ($this->state->USER['id_planet'] == $PLANET['id']) {
                $db->rollback();
                return $this->jsonResponse(['message' => $LNG['ov_principal_planet_cant_abanone']]);
                // } elseif (!password_verify($password, $accPassword)) {
                //     return $this->jsonResponse(['message' => $LNG['ov_wrong_pass']]);
            } elseif ($password != $PLANET['name']) {
                $db->rollback();
                return $this->jsonResponse(['message' => $LNG['ov_wrong_name']]);
            } else {
                // Re-home research queued on the abandoned planet to the main planet and persist it
                if (!empty($this->state->USER['b_tech_queue'])) {
                    $TechQueue = StoredData::decode($this->state->USER['b_tech_queue']);
                    if ($this->state->USER['b_tech_planet'] == $PLANET['id'] && isset($TechQueue[0])) {
                        // the running research was paid from the abandoned planet: nothing to refund on the main planet (empty cost)
                        $TechQueue[0][5] = [];
                    }
                    foreach ($TechQueue as $ID => $ListIDArray) {
                        if ($ListIDArray[4] == $PLANET['id']) {
                            $TechQueue[$ID][4] = $this->state->USER['id_planet'];
                        }
                    }

                    if ($this->state->USER['b_tech_planet'] == $PLANET['id']) {
                        $this->state->USER['b_tech_planet'] = $this->state->USER['id_planet'];
                    }

                    $this->state->USER['b_tech_queue'] = StoredData::encode($TechQueue);
                    $this->resourceUpdate()->saveTechQueue($this->state->USER);
                }

                $this->planetManager()->deletePlanet($PLANET['id']);
                $db->commit();
                $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                $statsHandler->increment($this->state->USER['id'], (int) $PLANET['planet_type'] === PlanetType::Moon->value ? AdvancedStatsEnum::MOONS_DELETED : AdvancedStatsEnum::PLANETS_DELETED, 1);

                $session = $this->sessions()->load();
                $session->planetId = $this->state->USER['id_planet'];
                $session->save();
                return $this->jsonResponse(['ok' => true, 'message' => $LNG['ov_planet_abandoned']]);
            }
        }

        return new Response();
    }

    /**
     * Community news for the overview row above the tutorial: published posts (status 1, date reached) of the last
     * three days, pinned first, at most three. Summary = the post's summary, else the start of its plain text.
     *
     * @return list<array{id: int, title: string, date: string, summary: string, pinned: bool}>
     */
    private function recentNews(): array
    {
        $now = $this->state->runtime->timestamp;
        $rows = $this->db->select('SELECT id, title, summary, text, date, pinned FROM %%NEWS%%
            WHERE status = 1 AND date <= :now AND date >= :since ORDER BY pinned DESC, date DESC LIMIT 3;', [
            ':now' => $now,
            ':since' => $now - 3 * 86400,
        ]);
        $LNG = $this->state->LNG;
        $news = [];
        foreach ($rows as $row) {
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($row['summary'] ?: $row['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
            $news[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'date' => (string) $this->viewHelpers()->formatDate($LNG['php_tdformat'], $row['date']),
                'summary' => mb_strimwidth($plain, 0, 220, '…', 'UTF-8'),
                'pinned' => (int) $row['pinned'] === 1,
            ];
        }

        return $news;
    }
}
