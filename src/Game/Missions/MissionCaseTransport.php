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
use App\Game\Core\Log;

/**
 * Mission "transport" (3): delivers the cargo to the target planet (own or foreign) and flies home empty.
 */
class MissionCaseTransport extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $Fleet;
    }

    /**
     * Arrival: turns the fleet around if the target is gone/abandoned. Otherwise reports to the sender and, if the
     * planet belongs to somebody else, to the receiver too (plus a transaction log entry of type 5 with the
     * delivered amounts), updates the target planet to the arrival time and unloads the cargo into it. If the start
     * planet no longer exists (destroyed moon) the return destination is changed to the owner's main planet.
     * Finally state FLEET_RETURN.
     */
    public function TargetEvent()
    {
        $db = $this->db;

        $sql = 'SELECT * FROM %%PLANETS%% WHERE `id` = :planetId;';
        $targetPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['end_id'],
        ]);

        // return fleet if target planet deleted or abandoned (destruyed is set while the row waits for cleanup)
        if ($targetPlanet == false || !empty($targetPlanet['destruyed'])) {
            // nothing was delivered: the anti-push guard must not count this transport
            $this->UpdateFleetLog('hasCanceled', 1);
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';

        $startPlanetName	= $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $targetPlanetName = $targetPlanet ? (string) $targetPlanet['name'] : null;

        /**
         * If target exists, deploy resources.
         * If it is a destroyed moon, avoid to call StoreGoodsToPlanet()
         */
        if ($targetPlanetName !== null) {
            $this->reportDelivery($startPlanetName, $targetPlanetName);

            $this->UpdatePlanet($this->_fleet['target_owner'], $this->_fleet['end_id']);
            $this->StoreGoodsToPlanet();
        }

        /**
         * Check if returning planet exists.
         * If is a player destroyed moon, redirect the fleet to the main planet.
         */
        if (!$startPlanetName) {
            $sql = 'SELECT `id_planet`, `galaxy`, `system`, `planet` FROM %%USERS%% WHERE `id` = :id;';
            $originUser = $db->selectSingle($sql, [
                ':id'	=> $this->_fleet['owner'],
            ]);

            $this->UpdateFleet('start_id', $originUser['id_planet']);
            $this->UpdateFleet('start_galaxy', $originUser['galaxy']);
            $this->UpdateFleet('start_system', $originUser['system']);
            $this->UpdateFleet('start_planet', $originUser['planet']);
            $this->UpdateFleet('start_type', 1);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();
    }

    /**
     * Messages of a delivery: the sender gets the transport report; a foreign receiver gets his own notice plus a
     * transaction log entry (type 5) with the delivered amounts.
     */
    private function reportDelivery(string|false|null $startPlanetName, string $targetPlanetName): void
    {
        $LNG			= $this->getLanguage(null, $this->_fleet['owner']);

        $Message		= $LNG->format(
            'sys_tran_mess_owner',
            $startPlanetName,
            GetStartAddressLink($this->_fleet, ''),
            $LNG['type_planet_' . $this->_fleet['end_type']],
            $targetPlanetName,
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
        );

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::Transport->value,
            $LNG['sys_mess_transport'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );

        if ($this->_fleet['target_owner'] != $this->_fleet['owner']) {
            $this->notifyReceiver($startPlanetName, $targetPlanetName);
        }
    }

    /**
     * The receiver of a foreign delivery: writes the transaction log (type 5) and sends the "foreign transport"
     * notice in his language.
     */
    private function notifyReceiver(string|false|null $startPlanetName, string $targetPlanetName): void
    {
        $LNG			= $this->getLanguage(null, $this->_fleet['target_owner']);
        $Message        = $LNG->format(
            'sys_tran_mess_user',
            $startPlanetName,
            GetStartAddressLink($this->_fleet, ''),
            $targetPlanetName,
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
        );

        $new = [];

        $new['startPlanet'] 		= $this->_fleet['start_id'];
        $new['startPlanetName']	 	= $startPlanetName;
        $new['metal'] 				= $this->_fleet['resource_metal'];
        $new['crystal'] 			= $this->_fleet['resource_crystal'];
        $new['deuterium'] 			= $this->_fleet['resource_deuterium'];
        $new['targetPlanet'] 		= $this->_fleet['end_id'];
        $new['targetPlanetName'] 	= $targetPlanetName;


        $LOG = new Log($this->db, $this->configs, $this->state, 5);
        $LOG->target = $this->_fleet['target_owner'];
        $LOG->admin = $this->_fleet['owner'];
        $LOG->old = $new;
        $LOG->new = $new;
        $LOG->saveTr();

        $this->playerMessenger()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::ForeignTransport->value,
            $LNG['sys_mess_transport'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );
    }

    /**
     * No hold phase.
     */
    public function EndStayEvent()
    {
        return;
    }

    /**
     * Sends the "fleet returned" message and lands the ships (cargo is empty) at the start planet.
     */
    public function ReturnEvent()
    {
        $LNG		= $this->getLanguage(null, $this->_fleet['owner']);
        $sql		= 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName	= $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $Message	= $LNG->format('sys_tran_mess_back', $planetName, GetStartAddressLink($this->_fleet, ''));

        $this->playerMessenger()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::System->value,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id'],
        );

        $this->RestoreFleet();
    }
}
