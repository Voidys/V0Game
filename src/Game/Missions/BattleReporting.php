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

namespace App\Game\Missions;

use App\Game\Enum\MessageType;
use App\Game\BattleEngine\BattleHandler;
use App\Game\BattleEngine\BattleResult;
use App\Game\Core\BattleReportStore;

/**
 * Report side of a fight for AbstractBattleMission: stores the combat report, sends the report messages, top-KB rows and the
 * "attack cancelled" message, and builds the message template.
 */
trait BattleReporting
{
    /** Stores the combat report (table rw) for the fight in the format of the engine used. */
    protected function storeReport(BattleSetup $setup, BattleHandler $battleHandler, array $reportData, string $reportID): void
    {
        (new BattleReportStore($this->db))->insert(
            $reportID,
            $reportData,
            $battleHandler->engine->getName(),
            $this->_fleet['arrival_time'],
            implode(',', array_keys($setup->userAttack)),
            implode(',', array_keys($setup->userDefend)),
        );
    }

    /**
     * Sends the battle report message to every attacker and defender (in their language) and, for a fight that makes the
     * top list, inserts the participants into the top-KB users table.
     *
     * @param array<int, int|float> $stealResource loot by resource
     * @param array<int, int|float> $debris        new debris by resource
     * @param array{0: string, 1: string} $class   CSS classes of the two sides
     * @return bool whether the fight is a top-KB entry
     */
    protected function sendReportMessages(BattleSetup $setup, BattleResult $combatResult, string $reportID, string $messageHTML, array $class, array $stealResource, array $debris, int|float $units): bool
    {
        $db = $this->db;
        $userAttack = $setup->userAttack;
        $userDefend = $setup->userDefend;

        $i = 0;
        $universe = $this->_fleet['universe'];
        $topkb = $this->checkForTopKB($units, $universe);

        foreach ([$userAttack, $userDefend] as $data) {
            foreach ($data as $userID => $userName) {
                $LNG = $this->getLanguage(null, $userID);

                $message = sprintf(
                    $messageHTML,
                    $reportID,
                    $class[$i],
                    $LNG['sys_mess_attack_report'],
                    $LNG->format(
                        'sys_adress_planet',
                        $this->_fleet['end_galaxy'],
                        $this->_fleet['end_system'],
                        $this->_fleet['end_planet'],
                    ),
                    $LNG['type_planet_short_' . $this->_fleet['end_type']],
                    $LNG['sys_lost'],
                    $class[0],
                    $LNG['sys_attack_attacker_pos'],
                    $this->viewHelpers()->prettyNumber($combatResult->losses->attackerValue),
                    $class[1],
                    $LNG['sys_attack_defender_pos'],
                    $this->viewHelpers()->prettyNumber($combatResult->losses->defenderValue),
                    $LNG['sys_gain'],
                    $LNG['tech'][901],
                    $this->viewHelpers()->prettyNumber($stealResource[901]),
                    $LNG['tech'][902],
                    $this->viewHelpers()->prettyNumber($stealResource[902]),
                    $LNG['tech'][903],
                    $this->viewHelpers()->prettyNumber($stealResource[903]),
                    $LNG['sys_debris'],
                    $LNG['tech'][901],
                    $this->viewHelpers()->prettyNumber($debris[901]),
                    $LNG['tech'][902],
                    $this->viewHelpers()->prettyNumber($debris[902]),
                );

                $this->playerMessenger()->sendMessage(
                    $userID,
                    0,
                    $LNG['sys_mess_tower'],
                    MessageType::BattleReport->value,
                    $LNG['sys_mess_attack_report'],
                    $message,
                    $this->_fleet['arrival_time'],
                    1,
                    $this->_fleet['universe'],
                    $this->_fleet['end_id'],
                );

                if ($topkb) {
                    $sql = "INSERT INTO %%TOPKB_USERS%% SET
                        `rid` = :reportId,
                        `role` = :userRole,
                        `username` = :username,
                        `uid` = :userId;";

                    $db->insert($sql, [
                        ':reportId' => $reportID,
                        ':userRole' => $i + 1,
                        ':username' => $userName,
                        ':userId'   => $userID,
                    ]);
                }
            }

            $i++;
        }

        return $topkb;
    }

    /** Inserts the fight into the top-KB list (table topkb). */
    protected function insertTopKb(BattleResult $combatResult, string $reportID, int|float $units): void
    {
        $db = $this->db;
        $sql = 'INSERT INTO %%TOPKB%% SET
            `units` = :units,
            `rid` = :reportId,
            `time` = :time,
            `universe` = :universe,
            `galaxy` = :galaxy,
            `result` = :result;';

        $db->insert($sql, [
            ':units'    => $units,
            ':reportId' => $reportID,
            ':time'     => $this->_fleet['arrival_time'],
            ':universe' => $this->_fleet['universe'],
            ':galaxy'	=> $this->_fleet['end_galaxy'],
            ':result'   => $combatResult->won,
        ]);

        $this->achievementGuard()->run(fn() => $this->battleAchievements()->checkBattleHallAchievements($combatResult));
    }

    /**
     * Called when the engine returned no result (fight too large for every engine): sends every involved player the
     * "attack cancelled" report message and turns the attacking fleet around unharmed.
     * @param array<int, string> $userAttack attacking user ids => names.
     * @param array<int, string> $userDefend defending user ids => names.
     */
    protected function cancelFight($userAttack, $userDefend, $targetPlanet)
    {
        foreach ([$userAttack, $userDefend] as $data) {
            foreach ($data as $userID => $userName) {
                $LNG = $this->getLanguage(null, $userID);

                $message = $LNG->format(
                    'sys_mess_attack_cancel',
                    $targetPlanet['name'],
                    GetTargetAddressLink($this->_fleet),
                );

                $this->playerMessenger()->sendMessage(
                    $userID,
                    0,
                    $LNG['sys_mess_tower'],
                    MessageType::BattleReport->value,
                    $LNG['sys_mess_attack_report'],
                    $message,
                    $this->state->runtime->timestamp,
                    1,
                    $this->_fleet['universe'],
                    $this->_fleet['end_id'],
                );
            }
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Template (minified HTML with sprintf placeholders) of the combat report message: link to the report, result
     * line with units lost per side, loot and debris. Filled by sendReportMessages().
     */
    protected function getMessageText()
    {
        $messageHTML = <<<HTML
<div class="raportMessage">
	<table>
		<tr>
			<td colspan="2">
			<a href="report/%s" target="_blank"><span class="%s">%s %s (%s)</span></a>
			</td>
		</tr>
		<tr>
			<td>%s</td><td><span class="%s">%s: %s</span>&nbsp;<span class="%s">%s: %s</span></td>
		</tr>
		<tr>
			<td>%s</td>
			<td>
                <span>%s:&nbsp;<span class="reportSteal element901">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportSteal element902">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportSteal element903">%s</span></span>
			</td>
		</tr>
		<tr>
			<td>%s</td>
			<td>
                <span>%s:&nbsp;<span class="reportDebris element901">%s</span>&nbsp;</span>
                <span>%s:&nbsp;<span class="reportDebris element902">%s</span></span>
			</td>
		</tr>
	</table>
</div>
HTML;
        //Minize HTML
        return str_replace(["\n", "\t", "\r"], "", $messageHTML);
    }
}
