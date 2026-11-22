<?php

/*
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
/**
 * Calculates missile attacks
 *
 */

namespace App\Game\Missions;

use App\Game\Enum\MessageType;

/**
 * Mission "missile attack" (10): interplanetary missiles hit the defenses of a planet; the missiles are used up
 * (the fleet is always deleted, there is no return).
 */
class MissionCaseMIP extends MissionFunctions implements MissionInterface
{
    /**
     * @param array<mixed> $Fleet
     */
    public function __construct(array $Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $Fleet;
    }

    /**
     * Arrival: the target defender's interceptor missiles shoot down missiles first (one for one: if they are at
     * least as many as the incoming ones all are intercepted and that many interceptors are lost, otherwise all
     * interceptors are used up). Surviving missiles (calculateMIPAttack) destroy defenses, primary target first
     * (a valid defense id chosen at launch, interceptors/invalid fall back to the missile launcher), then in the
     * order of the list. Both players get a report with the destroyed amounts (also if everything was
     * intercepted or nothing could be hit); destroyed/lost units are counted in the advanced stats. If the target
     * planet no longer exists the missiles are only counted as lost; an owner in vacation mode returns the fleet.
     */
    public function TargetEvent(): void
    {
        $e = $this->elements();
        $db	= $this->db;

        $elementIDs	= array_merge($e->ids('defense'), $e->ids('missile'));

        $targetData	= $this->loadTarget($elementIDs);

        // kill fleet if target planet deleted
        if ($targetData == false) {
            $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
            $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], $fleetArray);
            $this->KillFleet();
            return;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($this->returnIfTargetInVacation($targetData['id_owner'])) {
            return;
        }

        // The interceptor column may be missing from the query (no element class) or NULL: always an integer
        $targetData[$e->column(INTERCEPTOR_MISSILE)] = (int) ($targetData[$e->column(INTERCEPTOR_MISSILE)] ?? 0);

        $sql = 'SELECT us.`lang`, u.`military_tech` FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` 
            WHERE u.`id` = :userId;';
        $senderData	= $db->selectSingle($sql, [
            ':userId'	=> $this->_fleet['owner'],
        ]);

        if (
            !in_array($this->_fleet['missile_target'], array_merge($e->ids('defense'), $e->ids('missile')))
            || $this->_fleet['missile_target'] == INTERCEPTOR_MISSILE
            || $this->_fleet['missile_target'] == 0
        ) {
            $primaryTarget	= MISSILE_LAUNCHER;
        } else {
            $primaryTarget	= $this->_fleet['missile_target'];
        }

        $targetDefensive    = [];

        foreach ($elementIDs as $elementID) {
            $targetDefensive[$elementID]	= $targetData[$e->column($elementID)];
        }

        unset($targetDefensive[INTERCEPTOR_MISSILE]);

        $senderData['LNG']	= $this->getLanguage($senderData['lang']);
        $targetData['LNG']	= $this->getLanguage($targetData['lang']);

        $senderData['MSG'] = false;
        $targetData['MSG'] = false;

        $this->resolveHit($senderData, $targetData, $targetDefensive, $primaryTarget);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName	= $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $ownerLink			= $planetName . " " . GetStartAddressLink($this->_fleet);
        $targetLink 		= $targetData['name'] . " " . GetTargetAddressLink($this->_fleet);
        $senderData['MSG'] 	= $senderData['LNG']->format('sys_irak_mess', $this->_fleet['ship_amount'], $ownerLink, $targetLink) . $senderData['MSG'];
        $targetData['MSG']	= $targetData['LNG']->format('sys_irak_mess', $this->_fleet['ship_amount'], $ownerLink, $targetLink) . $targetData['MSG'];

        $this->sendReports($senderData, $targetData);

        $this->KillFleet();
    }

    /**
     * Reads the target planet with its owner's language and defence tech and the counts of all defense and missile types.
     *
     * @param array<int> $elementIDs defense and missile element ids whose columns are read
     * @return array<string, mixed>|false false when the planet is gone
     */
    private function loadTarget(array $elementIDs): array|false
    {
        $e = $this->elements();
        $db = $this->db;
        $targetQuery = $db->qb()
            ->select('us.`lang`', 'u.`defence_tech`', 'p.`id`', 'p.`name`', 'p.`id_owner`')
            ->from($db->table('PLANETS'), 'p')
            ->innerJoin('p', $db->table('USERS'), 'u', 'p.`id_owner` = u.`id`')
            ->innerJoin('u', $db->table('USERS_SETTINGS'), 'us', 'u.`id` = us.`user_id`')
            ->where('p.`id` = :planetId')
            ->setParameter('planetId', $this->_fleet['end_id']);
        foreach ($elementIDs as $elementID) {
            $targetQuery->addSelect('p.`' . $e->column($elementID) . '`');
        }

        $target = $db->fetchRow($targetQuery);
        if ($target === false) {
            return false;
        }

        // lock the planet row and take the counts from the locking read (it sees the latest committed values, the
        // joined read above may come from the event transaction's snapshot): concurrent volleys must not both spend
        // the same interceptors/defense
        $locked = $db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;', [
            ':planetId' => $this->_fleet['end_id'],
        ]);
        if (empty($locked)) {
            return false;
        }
        foreach ($elementIDs as $elementID) {
            $column = $e->column($elementID);
            if (array_key_exists($column, $locked)) {
                $target[$column] = $locked[$column];
            }
        }

        return $target;
    }

    /** Subtracts $amount of an element from the planet, never below 0. */
    private function deduct(int|string $planetId, int $elementId, int|float|string $amount): void
    {
        $column = $this->elements()->column($elementId);
        $this->db->updateRows('PLANETS', [], ['id' => $planetId], [
            $column => 'GREATEST(0, `' . $column . '` - ' . (int) $amount . ')',
        ]);
    }

    /**
     * The impact: interceptor missiles are used first (all missiles intercepted: only the interceptors are lost;
     * otherwise they are all gone), the remaining missiles destroy the defense (primary target first, then the list
     * order, see calculateMIPAttack). Writes the lost units to the planet, counts the stats and puts the result text
     * into the `MSG` of both players.
     *
     * @param array<string, mixed> $senderData sender row part (lang, military_tech, LNG, MSG), MSG is set
     * @param array<string, mixed> $targetData target planet/owner data (counts, defence_tech, LNG, MSG), MSG is set
     * @param array<int, int|string> $targetDefensive defense counts without the interceptors, by element id
     * @param int $primaryTarget element id hit first
     */
    private function resolveHit(array &$senderData, array &$targetData, array $targetDefensive, int $primaryTarget): void
    {
        $e = $this->elements();
        $db = $this->db;

        if ($targetData[$e->column(INTERCEPTOR_MISSILE)] >= $this->_fleet['ship_amount']) {
            $senderData['MSG'] 	= $senderData['LNG']['sys_irak_no_att'];
            $targetData['MSG']	= $targetData['LNG']['sys_irak_no_att'];
            $this->deduct($targetData['id'], INTERCEPTOR_MISSILE, $this->_fleet['ship_amount']);
            $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], INTERPLANETARY_MISSILE, $this->_fleet['ship_amount'], false);
            $this->statsHandler->updateLostAdvancedStats($this->_fleet['target_owner'], [INTERCEPTOR_MISSILE => $this->_fleet['ship_amount']]);
        } else {
            if ($targetData[$e->column(INTERCEPTOR_MISSILE)] > 0) {
                $db->updateRows('PLANETS', [$e->column(INTERCEPTOR_MISSILE) => 0], ['id' => $targetData['id']]);

                $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], INTERPLANETARY_MISSILE, $targetData[$e->column(INTERCEPTOR_MISSILE)], false);
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['target_owner'], [INTERCEPTOR_MISSILE => $targetData[$e->column(INTERCEPTOR_MISSILE)]]);
            }

            $targetDefensive = array_filter($targetDefensive);

            if (!empty($targetDefensive)) {
                $result   	= $this->calculateMIPAttack(
                    $targetData["defence_tech"],
                    $senderData["military_tech"],
                    $this->_fleet['ship_amount'],
                    $targetDefensive,
                    $primaryTarget,
                    $targetData[$e->column(INTERCEPTOR_MISSILE)],
                );

                $result		= array_filter($result);


                $senderData['MSG'] 	= $senderData['LNG']->format('sys_irak_def', $targetData[$e->column(INTERCEPTOR_MISSILE)]) . '<br><br>';
                $targetData['MSG']	= $targetData['LNG']->format('sys_irak_def', $targetData[$e->column(INTERCEPTOR_MISSILE)]) . '<br><br>';

                ksort($result, SORT_NUMERIC);

                foreach ($result as $Element => $destroy) {
                    $senderData['MSG'] 	.= sprintf('%s (- %d)<br>', $senderData['LNG']['tech'][$Element], $destroy);
                    $targetData['MSG']	.= sprintf('%s (- %d)<br>', $targetData['LNG']['tech'][$Element], $destroy);

                    $this->deduct($targetData['id'], (int) $Element, $destroy);
                    $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['owner'], $this->_fleet['target_owner'], $Element, $destroy);
                }
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], [INTERPLANETARY_MISSILE => $this->_fleet['ship_amount'] - $targetData[$e->column(INTERCEPTOR_MISSILE)]]);
            } else {
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], [INTERPLANETARY_MISSILE => $this->_fleet['ship_amount']]);
                $senderData['MSG'] 	= $senderData['LNG']['sys_irak_no_def'];
                $targetData['MSG']	= $targetData['LNG']['sys_irak_no_def'];
            }
        }
    }

    /**
     * Sends the missile report (battle report type) to the owner and to the target owner, each in his language.
     *
     * @param array<string, mixed> $senderData sender data with LNG and MSG
     * @param array<string, mixed> $targetData target data with LNG and MSG
     */
    private function sendReports(array $senderData, array $targetData): void
    {
        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $senderData['LNG']['sys_mess_tower'],
            MessageType::BattleReport->value,
            $senderData['LNG']['sys_irak_subject'],
            $senderData['MSG'],
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $targetData['LNG']['sys_mess_tower'],
            MessageType::BattleReport->value,
            $targetData['LNG']['sys_irak_subject'],
            $targetData['MSG'],
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent(): void
    {
        return;
    }

    /**
     * Missiles do not return (the fleet is deleted in TargetEvent). Only a missile that met a target in vacation mode
     * (returnIfTargetInVacation turns it to FLEET_RETURN) gets here: it is spent (the owner gets a message and the
     * lost-missiles stat), so the fleet and its event are deleted instead of staying as a zombie that is claimed on every request.
     */
    public function ReturnEvent(): void
    {
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);
        $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], $this->fleetFunctions()->unserialize($this->_fleet['ships']));
        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::BattleReport->value,
            $LNG['sys_irak_subject'],
            $LNG['sys_irak_vacation'],
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
        $this->KillFleet();
    }

    /**
     * Damage of the surviving missiles: total attack = surviving missiles * missile attack * (1 + 0.1 * attacker
     * weapons tech); every defense is destroyed as many as the attack covers (structure points = (metal+crystal)
     * cost / 10 * (1 + 0.1 * defender's armor tech)), primary target first, until the attack is used up.
     * @param array<int,int> $targetDefensive defense id => amount on the planet (interceptors removed).
     * @return array<int,int> destroyed amount by defense id.
     */
    private function calculateMIPAttack(
        int $TargetDefTech,
        int $OwnerAttTech,
        int $missiles,
        array $targetDefensive,
        int $firstTarget,
        int $defenseMissles,
    ): array {
        $e = $this->elements();
        $destroyShips = [];
        $countMissles = $missiles - $defenseMissles;

        if ($countMissles == 0) {
            return $destroyShips;
        }

        $totalAttack = $countMissles * $e->combat(INTERPLANETARY_MISSILE)->attack * (1 +  0.1 * $OwnerAttTech);

        // Select primary target, if exists
        if (isset($targetDefensive[$firstTarget])) {
            $firstTargetData	= [$firstTarget => $targetDefensive[$firstTarget]];
            unset($targetDefensive[$firstTarget]);
            $targetDefensive	= $firstTargetData + $targetDefensive;
        }

        foreach ($targetDefensive as $element => $count) {
            if ($element == 0) {
                throw new \Exception("Unknown error. Please report this error at https://github.com/Voidys/V0Game/issues. Debuginforations:<br><br>" . json_encode([$TargetDefTech, $OwnerAttTech, $missiles, $targetDefensive, $firstTarget, $defenseMissles]));
            }
            $elementStructurePoints = ($e->cost($element)->metal + $e->cost($element)->crystal) * (1 + 0.1 * $TargetDefTech) / 10;
            $destroyCount           = floor($totalAttack / $elementStructurePoints);
            $destroyCount           = min($destroyCount, $count);
            $totalAttack           -= $destroyCount * $elementStructurePoints;

            $destroyShips[$element]	= $destroyCount;
            if ($totalAttack <= 0) {
                return $destroyShips;
            }
        }

        return $destroyShips;
    }
}
