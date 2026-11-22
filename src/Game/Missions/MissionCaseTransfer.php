<?php

namespace App\Game\Missions;

use App\Game\Enum\MessageType;

/**
 * Mission "transfer" (17): gives ships and cargo to another player's planet. Everything is handed over (the
 * fleet is not coming back).
 */
class MissionCaseTransfer extends MissionFunctions implements MissionInterface
{
    public function __construct($Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $Fleet;
    }

    /**
     * Arrival: turns the fleet around if the target is gone/abandoned. Otherwise both players get a transport
     * message with the ship list, the cargo is unloaded into the target planet and the ships are added to it
     * (RestoreFleet(false) with the target owner), which deletes the fleet.
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
            $this->setState(FLEET_RETURN);
            $this->SaveFleet();
            return;
        }

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';

        $startPlanetName	= $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $fleet = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $this->reportToOwner($startPlanetName, $targetPlanet, $fleet);
        $this->reportToTargetOwner($startPlanetName, $targetPlanet, $fleet);

        $this->StoreGoodsToPlanet();
        $this->RestoreFleet(false);
    }

    /**
     * The sender's transport report: what was delivered where, plus the ship list; in the sender's language.
     *
     * @param string|false|null $startPlanetName name of the start planet (false when it is gone)
     * @param array<string, mixed> $targetPlanet planet row
     * @param array<int, int|float|string> $fleet ships by element id
     */
    private function reportToOwner(string|false|null $startPlanetName, array $targetPlanet, array $fleet): void
    {
        $LNG			= $this->getLanguage(null, $this->_fleet['owner']);

        $Message		= $LNG->format(
            'sys_tran_mess_owner',
            $startPlanetName,
            GetStartAddressLink($this->_fleet, ''),
            $targetPlanet['name'],
            $LNG['type_planet_' . $this->_fleet['end_type']],
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
        );


        foreach ($fleet as $elementID => $amount) {
            $Message   	.= '<br>' . $LNG['tech'][$elementID] . ': ' . $this->viewHelpers()->prettyNumber($amount);
        }

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
        );
    }

    /**
     * The receiver's notice of the delivery (same content, receiver's language).
     *
     * @param string|false|null $startPlanetName name of the start planet (false when it is gone)
     * @param array<string, mixed> $targetPlanet planet row
     * @param array<int, int|float|string> $fleet ships by element id
     */
    private function reportToTargetOwner(string|false|null $startPlanetName, array $targetPlanet, array $fleet): void
    {
        $LNG			= $this->getLanguage(null, $this->_fleet['target_owner']);
        $Message        = $LNG->format(
            'sys_tran_mess_user',
            $startPlanetName,
            GetStartAddressLink($this->_fleet, ''),
            $targetPlanet['name'],
            GetTargetAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
        );

        foreach ($fleet as $elementID => $amount) {
            $Message   	.= '<br>' . $LNG['tech'][$elementID] . ': ' . $this->viewHelpers()->prettyNumber($amount);
        }

        $this->playerMessenger()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $LNG['sys_mess_tower'],
            MessageType::Transport->value,
            $LNG['sys_mess_transport'],
            $Message,
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
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
     * Fleet that was turned back: "fleet returned" message with ships and cargo, landing at the start planet.
     */
    public function ReturnEvent()
    {
        $LNG				= $this->getLanguage(null, $this->_fleet['owner']);

        $db = $this->db;
        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $startPlanet = $db->selectSingle($sql, [
            ':planetId' => $this->_fleet['start_id'],
        ]);

        $stationShips = "";
        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        foreach ($fleetArray as $shipID => $amount) {
            $stationShips .= "<br>" . $LNG['tech'][$shipID] . ": " . $amount;
        }

        $Message = $LNG->format(
            'sys_fleet_return',
            $LNG['type_planet_' . $this->_fleet['start_type']],
            $startPlanet['name'] ?? '',
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
            $stationShips,
        );

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
        );

        $this->RestoreFleet();
    }
}
