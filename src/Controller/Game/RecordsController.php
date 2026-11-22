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

use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records page: highest building and research levels of the universe next to the player's own.
 */
#[\App\Controller\RequireModule('MODULE_RECORDS')]
class RecordsController extends AbstractGameController
{
    /**
     * Records page: advanced-statistic records of the universe (ADVANCED_STATS_UNIVERSE), and the highest building and research levels (RECORDS), each
     * next to the player's own value. Players who did not opt in to records (`records_optIn` = 0) appear as `-`.
     */
    #[GameRoute('game', 'game/records', 'records')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $universe = $this->universe()->current();

        $firstTimes = $this->firstTimes($universe);

        $sql = "SELECT r.elementID, r.level, r.userID, u.username, settings.records_optIn, v.name
            FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% as settings ON settings.user_id = u.id
            INNER JOIN %%RECORDS%% r ON r.userID = u.id
            INNER JOIN %%VARS%% v ON v.elementID = r.elementID
            WHERE r.universe = :universe;";
        $recordResult = $db->select($sql, [':universe' => $universe]);

        $userTechResult = $this->ownResearchLevels();
        $userBuildResult = $this->ownBuildingMaxLevels();

        [, $fleetList, $researchList, $buildList] = $this->groupRecords($recordResult);
        $lastRun = $this->cronjobs()->getLastExecutionTime('statistic');

        return $this->render('game/page.records.default.html.twig', [
            'fleetList'     => $fleetList,
            'researchList'  => $researchList,
            'buildList'     => $buildList,
            'update'        => $lastRun === false
                ? '-'
                : $this->viewHelpers()->formatDate($LNG['php_tdformat'], $lastRun, $USER['timezone']),
            'userTech'      => $userTechResult,
            'userBuild'     => $userBuildResult,
            'firstTimes'    => $firstTimes,
            // hidden staff (authlevel at or above stat_level) set no records: tell them why their own levels may be higher
            'staffExcluded' => (int) $this->configs->get()->stat_level > 0 && (int) $USER['authlevel'] >= (int) $this->configs->get()->stat_level,
        ]);
    }

    /**
     * The "first time" records of the universe (advanced stats older than one week) with the holder (`-` when he did
     * not opt in to records), the date, and the viewer's own date for the same stat (`-` when he has none).
     *
     * @return array<string, array{text: mixed, name: mixed, ts: string, user_ts: string}> stat key => row
     */
    private function firstTimes(mixed $universe): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $sql = "SELECT advu.statkey, advu.userId, u.username AS current_name, advu.username, advu.statvalue, adv.statvalue AS uservalue, settings.records_optIn
            FROM %%ADVANCED_STATS_UNIVERSE%% advu
            LEFT JOIN %%USERS%% u ON u.id = advu.userId
            LEFT JOIN %%ADVANCED_STATS%% adv ON adv.statkey = advu.statkey AND adv.userId = :userId
            LEFT JOIN %%USERS_SETTINGS%% settings ON settings.user_id = u.id
            WHERE advu.universe = :universe and advu.statvalue < :one_week_old ORDER BY `statvalue` ASC;";
        $firstTimesResult = $this->db->select($sql, [
            ':universe'      => $universe,
            ':one_week_old'  => $this->state->runtime->timestamp - TIME_1_WEEK,
            ':userId'        => $USER['id'],
        ]);

        $firstTimes = [];
        foreach ($firstTimesResult as $result) {
            $element = substr(strrchr($result['statkey'], "_"), 1);
            $text = $statsHandler->getLangKey(strtolower($result['statkey']));

            $firstTimes[$result['statkey']] = [
                'text'      => $text,
                'name'      => ($result['records_optIn'] === 0 ? '-' : ($result['current_name'] ?: $result['username'])),
                'ts'        => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $result['statvalue'], $USER['timezone']),
                'user_ts'   => $result['uservalue'] != null ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $result['uservalue'], $USER['timezone']) : '-',
            ];
        }

        return $firstTimes;
    }

    /**
     * The viewer's own research levels (one column per tech of the user row).
     *
     * @return array<string, mixed>|false column => level
     */
    private function ownResearchLevels(): array|false
    {
        $selected_tech = [];
        foreach ($this->elements()->ids('tech') as $Techno) {
            $selected_tech[] = $this->elements()->column($Techno);
        }

        $db = $this->db;
        // column names come from the game's resource table
        return $db->fetchRow(
            $db->qb()
                ->select(...$selected_tech)
                ->from($db->table('USERS'))
                ->where('id = :userId')
                ->setParameter('userId', $this->state->USER['id']),
        );
    }

    /**
     * The viewer's highest level of every building over all his planets and moons.
     *
     * @return array<string, mixed>|false column => max level
     */
    private function ownBuildingMaxLevels(): array|false
    {
        $select_buildings = [];
        foreach ($this->elements()->ids('build') as $Building) {
            $select_buildings[] = sprintf('max(%1$s) AS \'%1$s\'', $this->elements()->column($Building));
        }

        $db = $this->db;
        return $db->fetchRow(
            $db->qb()
                ->select(...$select_buildings)
                ->from($db->table('PLANETS'))
                ->where('id_owner = :userId')
                ->setParameter('userId', $this->state->USER['id']),
        );
    }

    /**
     * Sorts the record rows into the four lists of the page (defense incl. missiles, fleet, research, buildings), keyed by
     * element id (every enabled element of the list gets an entry, empty when nobody holds a record).
     *
     * @param list<array<string, mixed>> $recordResult RECORDS rows
     * @return array{0: array, 1: array, 2: array, 3: array} defenseList, fleetList, researchList, buildList
     */
    private function groupRecords(array $recordResult): array
    {
        $defenseList = array_fill_keys($this->elements()->ids('defense'), []);
        $fleetList = array_fill_keys($this->elements()->ids('fleet'), []);
        $researchList = array_fill_keys($this->elements()->ids('tech'), []);
        $buildList = array_fill_keys($this->elements()->ids('build'), []);

        foreach ($recordResult as $recordRow) {
            $element = $recordRow['elementID'];
            if ($this->buildFunctions()->isEnabled($element)) {
                if ($this->elements()->isDefense($element)) {
                    $defenseList[$element][] = $recordRow;
                } elseif ($this->elements()->isFleet($element)) {
                    $fleetList[$element][] = $recordRow;
                } elseif ($this->elements()->isResearch($element)) {
                    $researchList[$element][] = $recordRow;
                } elseif ($this->elements()->isBuilding($element)) {
                    $buildList[$element][] = $recordRow;
                } elseif ($this->elements()->isMissile($element)) {
                    $defenseList[$element][] = $recordRow;
                }
            }
        }

        return [$defenseList, $fleetList, $researchList, $buildList];
    }
}
