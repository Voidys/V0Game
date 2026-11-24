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
use OutOfRangeException;
use App\Game\Core\BattleReportStore;
use App\Game\BattleEngine\BattleFleet;
use App\Game\BattleEngine\BattleHandler;
use App\Game\Engagement\MapContent\NpcTargets;
use App\Game\Engagement\Mothership\MothershipBattle;

/**
 * Mission "pirate base attack" (20): attack on an NPC pirate base (table `pirate_bases`, spawned by
 * PirateSpawnerCronjob, planet type 4) instead of a player planet. The simplified counterpart of
 * AbstractBattleMission: no vacation/protection checks, no moon, no rebuild protection, no ACS defenders.
 */
class MissionCasePiratebaseAttack extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet = $Fleet;
    }

    /**
     * Arrival: loads and locks the base (fleet returns with a "base gone" message if it was removed meanwhile).
     * The base defends with its stored ships as fleet 0 owned by user id 0, using tech levels of the
     * attacking (lead) player minus 3 (not below 0), so the strength scales with the attacker. After the fight
     * (BattleHandler, PIRATE_BASE_FIGHT) the survivors of both sides are saved, the loot of a win is calculated
     * (pirate bases give up to 100% of their resources) and removed from the base, the debris is added to the
     * base's debris fields, the report is stored (`rw`) and a report message is sent to the attacking players only.
     * The fleets then return. No fight result (too large): fight cancelled, fleets return.
     */
    public function TargetEvent()
    {
        $db = $this->db;
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $sql = "SELECT * FROM %%PIRATE_BASES%% WHERE `id` = :baseId FOR UPDATE;";
        $targetBase = $db->selectSingle($sql, [
            ':baseId' => $this->_fleet['end_id'],
        ]);

        // return fleet if the base is gone (removed or refreshed since launch)
        if ($targetBase == false) {
            $this->sendPiratebaseGoneMessage();
            $this->returnFleets();
            return;
        }

        [$fleetAttack, $userAttack] = $this->buildAttackers();

        // the pirate mothership (map content) only fights a combat group of enough different players
        $battle = new MothershipBattle($this->db, $this->configs, $this->state);
        $mothership = $targetBase['kind'] == NpcTargets::KIND_MOTHERSHIP ? $battle->alive((int) $targetBase['id']) : null;
        $power = $mothership === null ? [] : $battle->attackPower($fleetAttack);
        if ($targetBase['kind'] == NpcTargets::KIND_MOTHERSHIP && ($mothership === null || count($battle->qualifying($power)) < $battle->minPlayers($mothership))) {
            $this->refuseMothershipFight($userAttack);
            return;
        }

        [$fleetDefend, $userDefend] = $this->buildPirateDefenders($fleetAttack, $targetBase, $LNG);

        $battleHandler = new BattleHandler(false, $this->db, $this->configs, $this->state);

        $combatResult = $battleHandler->engine->fight($fleetAttack, $fleetDefend, $this->_fleet['universe']);
        $reportID = bin2hex(random_bytes(16));
        if (!$combatResult) {
            $this->cancelFight($userAttack, $userDefend, [
                'name' => $LNG['type_planet_4'],
            ]);
            return;
        }

        $this->updateFleetsAfterBattle($fleetAttack, $reportID);
        $sql = "UPDATE %%PIRATE_BASES%% SET `ships` = :fleetArray WHERE `id` = :baseId;";
        $db->update($sql, [
            ':fleetArray'   => $this->fleetFunctions()->serialize($fleetDefend[0]->units),
            ':baseId'       => $this->_fleet['end_id'],
        ]);

        $this->resolveFight($battleHandler, $combatResult, $targetBase, $fleetAttack, $fleetDefend, $userAttack, $userDefend, $reportID);

        if ($mothership !== null) {
            (new MothershipBattle($this->db, $this->configs, $this->state))->afterFight($mothership, $power, (float) $combatResult->losses->defenderValue, $fleetDefend[0]->units, (int) $this->_fleet['arrival_time']);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * After the fight: loot of a win (not from the mothership, whose loot is paid when it dies), debris (none at the
     * mothership), report, messages, base update and the engagement hook.
     *
     * @param array<int|string, BattleFleet> $fleetAttack surviving attackers
     * @param array<int|string, BattleFleet> $fleetDefend
     * @param array<string, mixed> $targetBase pirate base row before the fight
     * @param array<int|string, string> $userAttack
     * @param array<int|string, string> $userDefend
     */
    private function resolveFight(BattleHandler $battleHandler, \App\Game\BattleEngine\BattleResult $combatResult, array $targetBase, array $fleetAttack, array $fleetDefend, array $userAttack, array $userDefend, string $reportID): void
    {
        $stealResource = [
            RESOURCE_METAL      => 0,
            RESOURCE_CRYSTAL    => 0,
            RESOURCE_DEUT       => 0,
        ];

        $mothership = $targetBase['kind'] == NpcTargets::KIND_MOTHERSHIP;
        if ($combatResult->won == ATTACKER && !$mothership) {
            $stealResource = $battleHandler->calculateSteal($fleetAttack, $targetBase, $this->_fleet['universe']);
        }

        [$debris, $planetDebris] = $this->debrisOf($targetBase, $combatResult);
        if ($mothership) {
            $debris = [RESOURCE_METAL => 0, RESOURCE_CRYSTAL => 0];
            $planetDebris = [RESOURCE_METAL => $targetBase['der_metal'], RESOURCE_CRYSTAL => $targetBase['der_crystal']];
        }

        $reportInfo = [
            'thisFleet'             => $this->_fleet,
            'debris'                => $debris,
            'stealResource'         => $stealResource,
            'moonChance'            => 0,
            'additionalChance'      => 0,
            'moonDestroy'           => false,
            'moonName'              => null,
            'moonDestroyChance'     => null,
            'moonDestroySuccess'    => null,
            'fleetDestroyChance'    => null,
            'fleetDestroySuccess'   => null,
        ];

        $reportData = $battleHandler->GenerateReport($combatResult, $reportInfo, PIRATE_BASE_FIGHT, $fleetAttack, $fleetDefend, $battleHandler->engine->getName());

        $this->storeReport($battleHandler, $reportData, $reportID, $userAttack, $userDefend);
        $this->sendReports($userAttack, $combatResult, $reportID, $stealResource, $debris);

        $this->updateBase($stealResource, $planetDebris);
        $this->fireBattleOutcome($userAttack, $userDefend, $combatResult, $reportID, $stealResource, $debris, 'pirate');
    }

    /**
     * The combat group of a mothership attack is too small (or the mothership is already gone): tells the attackers, the fleets
     * turn around unharmed (nothing was fought, no losses).
     *
     * @param array<int|string, string> $userAttack user id => name
     */
    private function refuseMothershipFight(array $userAttack): void
    {
        if ($this->_fleet['group'] != 0) {
            $this->db->delete('DELETE FROM %%USERS_TO_ACS%% WHERE `acsID` = :acsId;', [':acsId' => $this->_fleet['group']]);
        }
        foreach ($userAttack as $userID => $userName) {
            $LNG = $this->playerLookup()->getLanguage(null, $userID);
            $this->playerMessenger()->sendMessage(
                $userID,
                0,
                $LNG['ms_sender'],
                MessageType::BattleReport->value,
                $LNG['ms_msg_title'],
                $LNG->format('ms_msg_group_too_small', GetTargetAddressLink($this->_fleet)),
                $this->_fleet['arrival_time'],
                1,
                $this->_fleet['universe'],
            );
        }
        $this->returnFleets();
    }

    /**
     * The attacking fleets (the lead fleet and, with ACS, the group) as battle fleets with their owners.
     *
     * @return array{0: array<int|string, BattleFleet>, 1: array<int|string, string>} fleets by fleet id, user id => name
     */
    private function buildAttackers(): array
    {
        $db = $this->db;
        $fleetAttack = [];
        $userAttack = [];
        $incomingFleets = $this->getIncomingFleets();
        foreach ($incomingFleets as $fleetID => $fleetDetail) {
            $sql = "SELECT * FROM %%USERS%% WHERE `id` = :userId;";
            $player = $db->selectSingle($sql, [
                ':userId'   => $fleetDetail['owner'],
            ]);

            $fleetAttack[$fleetID] = new BattleFleet(
                $fleetID,
                $player,
                $this->fleetFunctions()->unserialize($fleetDetail['ships']),
                $fleetDetail,
            );

            $userAttack[$player['id']] = $player['username'];
        }

        return [$fleetAttack, $userAttack];
    }

    /**
     * The pirate base's side: its stored ships as fleet 0 of user 0 ("Pirate base"); the tech levels are those of the
     * lead attacker minus 3 (not below 0), so the strength scales with the attacker.
     *
     * @param array<int|string, BattleFleet> $fleetAttack
     * @param array<string, mixed> $targetBase pirate base row
     * @return array{0: array<int, BattleFleet>, 1: array<int, string>} fleets, user id => name
     */
    private function buildPirateDefenders(array $fleetAttack, array $targetBase, \App\Game\Core\Language $LNG): array
    {
        $fleetDefend = [];
        $userDefend = [];
        $ownTechs = $fleetAttack[$this->_fleet['id']]->techs->withBonus(-3);
        $bossTech = null;
        if ($targetBase['kind'] == NpcTargets::KIND_MOTHERSHIP) {
            // fixed tech level, not derived from whoever leads the group
            $bossTech = (int) ($this->configs->get((int) $this->_fleet['universe'])->mothership_tech_level ?? 20);
        }
        $pirates = [
            'id'            => 0,
            'username'      => $LNG['type_planet_4'],
            'military_tech' => $bossTech ?? $ownTechs->weapons,
            'shield_tech'   => $bossTech ?? $ownTechs->shields,
            'defence_tech'  => $bossTech ?? $ownTechs->armor,
        ];
        $fleetDefend[0] = new BattleFleet(
            0,
            $pirates,
            $this->fleetFunctions()->unserialize($targetBase['ships']),
            [
                'start_galaxy'    => $this->_fleet['end_galaxy'],
                'start_system'    => $this->_fleet['end_system'],
                'start_planet'    => $this->_fleet['end_planet'],
                'start_type'      => $this->_fleet['end_type'],
            ],
        );

        $userDefend[$pirates['id']] = $pirates['username'];

        return [$fleetDefend, $userDefend];
    }

    /**
     * Stores the combat report (`rw`) with the engine that produced it.
     *
     * @param array<string, mixed> $reportData
     * @param array<int|string, string> $userAttack
     * @param array<int|string, string> $userDefend
     */
    private function storeReport(BattleHandler $battleHandler, array $reportData, string $reportID, array $userAttack, array $userDefend): void
    {
        (new BattleReportStore($this->db))->insert(
            $reportID,
            $reportData,
            $battleHandler->engine->getName(),
            $this->_fleet['arrival_time'],
            implode(',', array_keys($userAttack)),
            implode(',', array_keys($userDefend)),
        );
    }

    /**
     * Sends the battle report message to every attacking player, in his language, with the result colours (win, lose
     * or draw), the lost values, the loot and the debris. The pirates get no message.
     *
     * @param array<int|string, string> $userAttack user id => name
     * @param array<int, int|float> $stealResource loot by resource id
     * @param array<int, int|float> $debris debris by resource id
     */
    private function sendReports(array $userAttack, \App\Game\BattleEngine\BattleResult $combatResult, string $reportID, array $stealResource, array $debris): void
    {
        switch ($combatResult->won) {
            case ATTACKER:
                // Win
                $class = ['raportWin', 'raportLose'];
                break;
            case DEFENDER:
                // Lose
                $class = ['raportLose', 'raportWin'];
                break;
            default:
                // Draw
                $class = ['raportDraw', 'raportDraw'];
                break;
        }

        $i = 0;

        $messageHTML = $this->getMessageText();
        foreach ([$userAttack] as $data) {
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
            }
            $i++;
        }
    }

    /**
     * Takes the loot from the base and sets its debris fields to the old ones plus the new debris.
     *
     * @param array<mixed> $planetDebris
     * @param array<mixed> $stealResource
     */
    private function updateBase(array $stealResource, array $planetDebris): void
    {
        $db = $this->db;
        $sql = 'UPDATE %%PIRATE_BASES%% SET
            `metal` = `metal` - :metal,
            `crystal` = `crystal` - :crystal,
            `deuterium` = `deuterium` - :deuterium,
            `der_metal` = :der_metal,
            `der_crystal` = :der_crystal
            WHERE `id` = :baseId;';

        $db->update($sql, [
            ':metal'        => $stealResource[901],
            ':crystal'      => $stealResource[902],
            ':deuterium'    => $stealResource[903],
            ':der_metal'    => $planetDebris[901],
            ':der_crystal'  => $planetDebris[902],
            ':baseId'   => $this->_fleet['end_id'],
        ]);
    }

    /**
     * Debris of the fight (metal and crystal of both sides) and the new debris field of the base: the old field plus the
     * new debris.
     *
     * @param array<string, mixed> $targetBase pirate base row
     * @return array{0: array<int, int|float>, 1: array<int, int|float>} debris of the fight, debris field of the base after it
     */
    private function debrisOf(array $targetBase, \App\Game\BattleEngine\BattleResult $combatResult): array
    {
        $e = $this->elements();
        $debris = [];
        $planetDebris = [];
        $debrisResource = [RESOURCE_METAL, RESOURCE_CRYSTAL];
        foreach ($debrisResource as $elementID) {
            $debris[$elementID] = $combatResult->debris->total($elementID);
            $planetDebris[$elementID] = $targetBase['der_' . $e->column($elementID)] + $debris[$elementID];
        }

        return [$debris, $planetDebris];
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Sends the "fleet returned" message (cargo, lost ships) and lands ships and loot at the start planet.
     */
    public function ReturnEvent()
    {
        $this->sendAttackReturnMessage();

        $this->RestoreFleet();
    }

    /**
     * Engine could not compute the fight: tells all participants the attack was cancelled and turns the fleet around.
     */
    private function cancelFight($userAttack, $userDefend, $targetPlanet)
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
     * Writes the surviving ships of every attacking fleet (fleets and log_fleets), deletes fleets without ships and
     * marks them FLEET_DESTROYED in the log, and removes them from `$fleets` (by reference, like AbstractBattleMission::applyLosses()) so loot and report only see surviving fleets. The pirate base itself (fleet id 0) is skipped.
     *
     * @param array<int|string, BattleFleet> $fleets the attacking fleets (and the base as fleet 0) after the fight
     */
    private function updateFleetsAfterBattle(array &$fleets, string $reportID)
    {
        $db = $this->db;

        foreach ($fleets as $fleetID => $fleet) {
            if ($fleetID == 0) {
                continue; // Skip planet fleet
            }
            $fleetArray = '';
            $totalCount = 0;

            foreach (array_filter($fleet->units) as $elementID => $amount) {
                $fleetArray .= $elementID . ',' . floatToString($amount) . ';';
                $totalCount += $amount;
            }

            if ($totalCount == 0) {
                if ($this->_fleet['id'] == $fleetID) {
                    $this->KillFleet();
                } else {
                    $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
                        FROM %%FLEETS%%
                        INNER JOIN %%FLEETS_EVENT%% ON `fleetID` = `id`
                        WHERE `id` = :fleetId;';

                    $db->delete($sql, [
                        ':fleetId'  => $fleetID,
                    ]);
                }

                $sql = 'UPDATE %%LOG_FLEETS%% SET `report_id` = :reportId, `status` = :fleetState WHERE `id` = :fleetId;';
                $db->update($sql, [
                    ':reportId'     => $reportID,
                    ':fleetId'      => $fleetID,
                    ':fleetState'   => FLEET_DESTROYED,
                ]);

                unset($fleets[$fleetID]);
            } elseif ($totalCount > 0) {
                $sql = "UPDATE %%FLEETS%% f, %%LOG_FLEETS%% l SET
                    f.`ships`       = :fleetData,
                    f.`ship_amount` = :fleetCount,
                    l.`ships`		= :fleetData,
                    l.`ship_amount` = :fleetCount,
                    l.`report_id`   = :reportId
                    WHERE f.`id` = :fleetId AND l.`id` = :fleetId;";

                $db->update($sql, [
                    ':fleetData'    => substr($fleetArray, 0, -1),
                    ':fleetCount'   => $totalCount,
                    ':fleetId'      => $fleetID,
                    ':reportId'     => $reportID,
                ]);
            } else {
                throw new OutOfRangeException("Negative Fleet amount ....");
            }
        }
    }

    /**
     * The attacking fleets: all fleets of the ACS group (and the group is deleted) or just this one.
     * @return array<int, array<string, mixed>> fleet rows by fleet id.
     */
    private function getIncomingFleets()
    {
        $incomingFleets = [];
        if ($this->_fleet['group'] != 0) {
            $db = $this->db;
            $sql = "DELETE FROM %%AKS%% WHERE `id` = :acsId;";
            $db->delete($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            $sql = "SELECT * FROM %%FLEETS%% WHERE `group` = :acsId ORDER BY `id` ASC;";
            $incomingFleetsResult = $db->select($sql, [
                ':acsId'    => $this->_fleet['group'],
            ]);

            foreach ($incomingFleetsResult as $incomingFleetRow) {
                $incomingFleets[$incomingFleetRow['id']] = $incomingFleetRow;
            }
        } else {
            $incomingFleets = [$this->_fleet['id'] => $this->_fleet];
        }
        return $incomingFleets;
    }

    /**
     * Cancels the attack: deletes the ACS group (if any) and turns this fleet around.
     */
    private function returnFleets()
    {
        if ($this->_fleet['group'] != 0) {
            $db = $this->db;
            $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                FROM %%AKS%%
                LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                WHERE %%AKS%%.id = :acsId;';

            $db->delete($sql, [
                ':acsId' => $this->_fleet['group'],
            ]);
        }
        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Template of the combat report message (see BattleReporting::getMessageText()).
     */
    private function getMessageText()
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
