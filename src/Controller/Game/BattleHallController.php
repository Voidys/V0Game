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

use App\Game\Core\BattleHallFilter;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hall of fame of the strongest battles (battle hall) with its filter.
 */
#[\App\Controller\RequireModule('MODULE_BATTLEHALL')]
class BattleHallController extends AbstractGameController
{
    /**
     * Builds the filter dropdowns (memorial, time frame, diplomacy, galaxy) of the battle hall.
     */
    private function assembleSelectors()
    {
        $LNG = $this->state->LNG;

        $Selectors = [];
        $Selectors['memorial'] = [
            BattleHallFilter::MEMORIAL_ALL => $LNG['tkb_all'],
            BattleHallFilter::MEMORIAL_EXCLUDE => $LNG['tkb_exclude'],
            BattleHallFilter::MEMORIAL_ONLY => $LNG['tkb_only'],
        ];

        $Selectors['timeframe'] = [
            BattleHallFilter::TIMEFRAME_ALL => $LNG['tkb_all'],
            BattleHallFilter::TIMEFRAME_24H => $LNG['tkb_day'],
            BattleHallFilter::TIMEFRAME_1WK => $LNG['tkb_week'],
            BattleHallFilter::TIMEFRAME_1MTH => $LNG['tkb_month'],
        ];

        $Selectors['diplomacy'] = [
            BattleHallFilter::DIPLOMACY_ALL => $LNG['tkb_all'],
            BattleHallFilter::DIPLOMACY_ALLIANCE => $LNG['pl_ally'],
            BattleHallFilter::DIPLOMACY_SELF => $LNG['tkb_self'],
        ];

        $maxgala = $this->configs->get()->max_galaxy;

        $Selectors['galaxy'] = [
            0 => $LNG['tkb_all'],
        ];

        for ($curgala = 1; $curgala <= $maxgala; $curgala++) {
            $Selectors['galaxy'][$curgala] = $curgala;
        }

        return $Selectors;
    }

    /**
     * Players of the listed reports for the name links: report id => role (1 attacker, 2 defender) => list of id/name.
     * @param array<int, string> $rids
     * @return array<string, array<int, array<int, array{id:int, name:string}>>>
     */
    private function participantsByReport(array $rids): array
    {
        if ($rids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            $this->db->qb()
                ->select('tu.rid', 'tu.uid', 'tu.role', "IF(tu.username = '', u.username, tu.username) AS name")
                ->from($this->db->table('TOPKB_USERS'), 'tu')
                ->leftJoin('tu', $this->db->table('USERS'), 'u', 'u.id = tu.uid')
                ->where('tu.rid IN (:rids)')
                ->setParameter('rids', $rids, \Doctrine\DBAL\ArrayParameterType::STRING),
        );
        $result = [];
        foreach ($rows as $row) {
            if ($row['name'] === null || $row['name'] === '') {
                continue;
            }
            $result[$row['rid']][(int) $row['role']][] = ['id' => (int) $row['uid'], 'name' => $row['name']];
        }

        return $result;
    }

    /**
     * Hall of fame of the biggest battles with filters. Request: memorial (1 memorial battles only), timeframe, diplomacy, galaxy.
     */
    #[GameRoute('game', 'game/battle-hall', 'battleHall')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;


        $memorial = $this->input()->int('memorial', 1);
        $timeframe = $this->input()->int('timeframe');
        $diplomacy = $this->input()->int('diplomacy');
        $galaxy = $this->input()->int('galaxy');

        $filters = [
            'memorial' => $memorial,
            'timeframe' => $timeframe,
            'diplomacy' => $diplomacy,
            'galaxy' => $galaxy,
        ];

        $pBHFilter = new BattleHallFilter($this->db, $this->configs, $this->state, $filters);
        $top = $pBHFilter->getTopKBs();

        $TopKBList	= [];
        foreach ($top as $data) {
            $TopKBList[]	= [
                'result'	=> $data['result'],
                'date'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $data['time'], $USER['timezone']),
                'time'		=> $this->state->runtime->timestamp - $data['time'],
                'units'		=> $data['units'],
                'rid'		=> $data['rid'],
                'attacker'	=> $data['attacker'],
                'defender'	=> $data['defender'],
            ];
        }

        $Selectors = $this->assembleSelectors();
        $participants = $this->participantsByReport(array_column($TopKBList, 'rid'));
        foreach ($TopKBList as &$entry) {
            $entry['attackers'] = $participants[$entry['rid']][1] ?? [];
            $entry['defenders'] = $participants[$entry['rid']][2] ?? [];
        }
        unset($entry);

        return $this->render('game/page.battleHall.default.html.twig', [
            'TopKBList'		=> $TopKBList,
            'Selectors'		=> $Selectors,
            'memorial'		=> $memorial,
            'timeframe'		=> $timeframe,
            'diplomacy'		=> $diplomacy,
            'galaxy'		=> $galaxy,
        ]);
    }
}
