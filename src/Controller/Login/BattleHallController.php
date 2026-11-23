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

namespace App\Controller\Login;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Universe;

/** Public hall of fame of the biggest battles. */
class BattleHallController extends AbstractLoginController
{
    /**
     * Public hall of fame of the biggest battles (top 100 by units lost, battles older than 6 hours). Request: universe
     * (id, default current), memorial (0 = normal battles, 1 = memorial battles only). Reads TOPKB with the participants.
     */
    #[GameRoute('index', 'battle-hall', 'battleHall')]
    #[\App\Routing\PublicCache(300)]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        $universe = $this->input()->int('universe', (int) $this->universe()->current());
        $memorial = $this->input()->int('memorial');

        $sql = "SELECT *, (
            SELECT DISTINCT
            IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
            FROM %%TOPKB_USERS%%
            LEFT JOIN %%USERS%% ON uid = %%USERS%%.id
            WHERE %%TOPKB_USERS%%.`rid` = %%TOPKB%%.`rid` AND `role` = 1
        ) as `attacker`,
        (
            SELECT DISTINCT
            IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
            FROM %%TOPKB_USERS%% LEFT JOIN %%USERS%% ON uid = id
            WHERE %%TOPKB_USERS%%.`rid` = %%TOPKB%%.`rid` AND `role` = 2
        ) as `defender`
        FROM %%TOPKB%% WHERE `universe` = :universe AND `memorial` = :memorial AND time < UNIX_TIMESTAMP() - 21600 ORDER BY units DESC LIMIT 100;";

        $hallRaw = $db->select($sql, [
            ':universe' => $universe,
            ':memorial' => $memorial,
        ]);

        $hallList    = [];
        foreach ($hallRaw as $hallRow) {
            $hallList[]    = [
                'result'    => $hallRow['result'],
                'time'      => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $hallRow['time']),
                'units'     => $hallRow['units'],
                'rid'       => $hallRow['rid'],
                'attacker'  => $hallRow['attacker'],
                'defender'  => $hallRow['defender'],
            ];
        }

        $universeSelect    = $this->getUniverseSelector();

        $memorialSelect = [
            0 => $LNG['tkb_memorial'] . ' ' . $LNG['tkb_exclude'],
            1 => $LNG['tkb_memorial'] . ' ' . $LNG['tkb_only'],
        ];


        return $this->render('login/page.battleHall.default.html.twig', [
            'universeSelect'    => $universeSelect,
            'memorialSelect'    => $memorialSelect,
            'hallList'          => $hallList,
            'universe'          => $universe,
            'memorial'          => $memorial,
        ]);
    }
}
