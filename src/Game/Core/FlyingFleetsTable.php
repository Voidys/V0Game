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

namespace App\Game\Core;

use App\Game\Enum\PlanetType;
use App\Game\Enum\Mission;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Builds the list of fleet movements shown on the overview and in the phalanx view: the player's own flights and
 * foreign flights aimed at the player (set up with setUser/setPlanet/setMissions/setPhalanxMode, then
 * renderTable()). In phalanx mode it lists the flights to and from the scanned planet and logs the scan in
 * phalanx_log/phalanx_fleets. Each event (arrival, hold end, return) becomes one translated line with fleet and mission popups.
 */
class FlyingFleetsTable
{
    use \App\Game\Core\GameUtilities;

    /** css style name of a mission (prefixed with own/hostile for the popup links and coordinates) */
    private const MISSION_STYLES = [
        Mission::Attack->value => 'attack',
        Mission::Acs->value => 'federation',
        Mission::Transport->value => 'transport',
        Mission::Station->value => 'deploy',
        Mission::Hold->value => 'hold',
        Mission::Spy->value => 'espionage',
        Mission::Colonisation->value => 'colony',
        Mission::Recycling->value => 'harvest',
        Mission::Destruction->value => 'destroy',
        Mission::Missile->value => 'missile',
        Mission::Expedition->value => 'transport',
        Mission::Trade->value => 'transport',
        Mission::Transfer->value => 'transport',
        Mission::PiratebaseAttack->value => 'attack',
        Mission::PiratebaseSpy->value => 'espionage',
        Mission::PiratebaseRecycling->value => 'harvest',
    ];

    protected int $userId = 0;
    protected int $planetId = 0;
    protected bool $IsPhalanx = false;
    protected string $missions = '';

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public function setUser(int $userId): void
    {
        $this->userId = $userId;
    }

    public function setPlanet(int $planetId): void
    {
        $this->planetId = $planetId;
    }

    public function setPhalanxMode()
    {
        $this->IsPhalanx = true;
    }

    public function setMissions(string $missions): void
    {
        $this->missions = implode(',', array_map('intval', array_filter(explode(',', $missions), 'is_numeric')));
    }

    /**
     * Fleet rows with owner/target names and planet names. Phalanx: flights starting at or heading to the scanned
     * planet (no deployments, recycling or trade targets); with $acsID: all fleets of that group; with a mission
     * filter: only those missions; otherwise own flights and foreign flights targeting the player (except
     * recycling and trade).
     *
     * @return array<mixed>
     */
    private function getFleets(int $acsID = 0): array
    {
        if ($this->IsPhalanx && $acsID <= 0) {
            $where = '(fleet.`start_id` = :planetId AND fleet.`start_type` = :type AND fleet.`mission` != :stay) OR'
                . ' (fleet.`end_id` = :planetId AND fleet.`end_type` = :type AND fleet.`mission` != :rec AND fleet.`mission` != :trade AND fleet.`status` IN (:outward, :return)'
                . ' AND fleet.`mission` NOT IN (' . Mission::PiratebaseAttack->value . ',' . Mission::PiratebaseSpy->value . ',' . Mission::PiratebaseRecycling->value . '))';

            $param = [
                ':planetId' => $this->planetId,
                ':type'     => PlanetType::Planet->value,
                ':stay'     => Mission::Station->value,
                ':rec'      => Mission::Recycling->value,
                ':trade'    => Mission::Trade->value,
                ':outward'  => FLEET_OUTWARD,
                ':return'   => FLEET_RETURN,
            ];
        } elseif ($acsID > 0) {
            $where  = 'fleet.`group` = :acsId';
            $param = [':acsId'    => $acsID];
        } elseif ($this->missions) {
            $where = '(fleet.`owner` = :userId OR (fleet.`target_owner` = :userId AND fleet.`mission` != :rec AND fleet.`mission` != :trade))'
                . ' AND fleet.`mission` IN (:missions)';
            $param = [
                ':missions' => array_map('intval', explode(',', $this->missions)),
                ':userId'   => $this->userId,
                ':rec'      => Mission::Recycling->value,
                ':trade'    => Mission::Trade->value,
            ];
        } else {
            $where  = 'fleet.`owner` = :userId OR (fleet.`target_owner` = :userId AND fleet.`mission` != :rec AND fleet.`mission` != :trade)';
            $param = [
                ':userId'   => $this->userId,
                ':rec'      => Mission::Recycling->value,
                ':trade'    => Mission::Trade->value,
            ];
        }

        $qb = $this->db->qb()
            ->select('fleet.*', 'ownuser.`username` AS own_username', 'targetuser.`username` AS target_username', 'ownplanet.`name` AS own_planetname', 'targetplanet.`name` AS target_planetname')
            ->distinct()
            ->from($this->db->table('FLEETS'), 'fleet')
            ->leftJoin('fleet', $this->db->table('USERS'), 'ownuser', 'ownuser.`id` = fleet.`owner`')
            ->leftJoin('fleet', $this->db->table('USERS'), 'targetuser', 'targetuser.`id` = fleet.`target_owner`')
            ->leftJoin('fleet', $this->db->table('PLANETS'), 'ownplanet', 'ownplanet.`id` = fleet.`start_id`')
            ->leftJoin('fleet', $this->db->table('PLANETS'), 'targetplanet', 'targetplanet.`id` = fleet.`end_id` AND fleet.`mission` NOT IN (' . Mission::PiratebaseAttack->value . ',' . Mission::PiratebaseSpy->value . ',' . Mission::PiratebaseRecycling->value . ')')
            ->where($where)
            ->orderBy('fleet.`id`');
        foreach ($param as $name => $value) {
            if (is_array($value)) {
                $qb->setParameter(ltrim($name, ':'), $value, ArrayParameterType::INTEGER);
            } else {
                $qb->setParameter(ltrim($name, ':'), $value);
            }
        }

        return $this->db->fetchAll($qb);
    }

    /**
     * Expands the fleets into time-ordered events: outward arrival (once per ACS group), missile arrivals, hold end
     * (hold missions, also at the scanned planet), and the return of own fleets, each only if it lies in the future.
     * In phalanx mode also writes the scan to phalanx_log and the fleets seen to phalanx_fleets.
     * @param bool $isPhalanx true when called for a phalanx scan.
     * @return array<string, array{text: \Twig\Markup, returntime: int, resttime: int}> keyed by event time + fleet id (sorted ascending).
     */
    public function renderTable(bool $isPhalanx = false): array
    {
        $db = $this->db;
        $fleetResult            = $this->getFleets();
        $ACSDone                = [];
        $FleetData              = [];

        $phalanxLogID = $isPhalanx ? $this->logPhalanxScan() : 0;

        foreach ($fleetResult as $fleetRow) {

            if ($isPhalanx && $phalanxLogID > 0) {
                $db->insert(
                    'INSERT INTO %%PHALANX_FLEETS%% (`phalanx_log_id`, `fleet_id`)
                    VALUES (:phalanx_log_id, :fleet_id);',
                    [
                        ':phalanx_log_id' => $phalanxLogID,
                        ':fleet_id'       => $fleetRow['id'],
                    ],
                );
            }

            if ($fleetRow['mission'] == Mission::Trade->value) {
                $fleetRow['target_planetname'] = "";
            }
            if (
                $fleetRow['status'] == FLEET_OUTWARD && $fleetRow['arrival_time'] > $this->state->runtime->timestamp
                && ($fleetRow['group'] == 0 || !isset($ACSDone[$fleetRow['group']]))
            ) {
                $ACSDone[$fleetRow['group']]      = true;
                $FleetData[$fleetRow['arrival_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    0,
                );
            }

            if (
                $fleetRow['mission'] == Mission::Missile->value
                || (($fleetRow['mission'] == Mission::Transfer->value
                || $fleetRow['mission'] == Mission::Station->value) && $fleetRow['status'] == FLEET_OUTWARD)
            ) {
                continue;
            }

            if (
                $fleetRow['stay_time'] != $fleetRow['arrival_time']
                && $fleetRow['stay_time'] > $this->state->runtime->timestamp
                && ($this->IsPhalanx && $fleetRow['end_id'] == $this->planetId)
            ) {
                $FleetData[$fleetRow['stay_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    2,
                );
            }

            $MissionsOK = 5;
            if ($fleetRow['stay_time'] > $this->state->runtime->timestamp && $fleetRow['mission'] == $MissionsOK) {
                $FleetData[$fleetRow['stay_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    2,
                );
            }

            if ($fleetRow['owner'] != $this->userId) {
                continue;
            }

            if ($fleetRow['return_time'] > $this->state->runtime->timestamp) {
                $FleetData[$fleetRow['return_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    1,
                );
            }

        }

        ksort($FleetData);
        return $FleetData;
    }

    /**
     * Writes the scan of the phalanx to phalanx_log (scanning user and planet, scanned user and planet, time).
     *
     * @return int|string id of the log entry
     */
    private function logPhalanxScan(): int|string
    {
        $db = $this->db;
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $sql = 'INSERT INTO %%PHALANX_LOG%%
            (`owner`, `owner_planet_id`, `target`, `target_planet_id`, `phalanx_time`)
            VALUES (:owner, :planet_id, :target, :designatedPlanetID, :timestamp);';

        $db->insert($sql, [
            ':owner'                => $USER['id'],
            ':planet_id'            => $PLANET['id'],
            ':target'               => $this->userId,
            ':designatedPlanetID'   => $this->planetId,
            ':timestamp'            => $this->state->runtime->timestamp,
        ]);

        return $db->lastInsertId();
    }

    /**
     * One table entry. Outward events of an ACS group list every fleet of the group, one below the other.
     * @param int $FleetState 0 = outward (arrival), 1 = return, 2 = hold end.
     * @param array<mixed> $fleetRow
     * @return array<mixed>
     */
    private function buildFleetEventTable(array $fleetRow, int $FleetState): array
    {
        $Time   = 0;
        $Rest   = 0;

        if (
            $FleetState == 0 && $this->IsPhalanx && $fleetRow['group'] != 0
            && str_contains((string) ($this->state->requestServer['REQUEST_URI'] ?? ''), 'game/phalanx')
        ) {
            // Rebuilt the code above to eliminate possible errors with ACS without Phalanx.
            $acsResult      = $this->getFleets($fleetRow['group']);
            $EventString    = '';

            foreach ($acsResult as $acsRow) {
                if ($acsRow['group'] != 0) {
                    $Return         = $this->getEventData($acsRow, $FleetState);

                    $Rest           = $Return[0];
                    $EventString    .= $Return[1] . '<br><br>';
                    $Time           = $Return[2];
                }
            }

            $EventString    = substr($EventString, 0, -8);
        } elseif ($FleetState == 0 && $fleetRow['group'] != 0) {
            $acsResult      = $this->getFleets($fleetRow['group']);
            $EventString    = '';

            foreach ($acsResult as $acsRow) {
                $Return         = $this->getEventData($acsRow, $FleetState);

                $Rest           = $Return[0];
                $EventString    .= $Return[1] . '<br><br>';
                $Time           = $Return[2];
            }

            $EventString    = substr($EventString, 0, -8);
        } else {
            list($Rest, $EventString, $Time) = $this->getEventData($fleetRow, $FleetState);
        }

        return [
            // built from translations, numbers and the escaped player name: trusted markup
            'text'          => new \Twig\Markup($EventString, 'UTF-8'),
            'returntime'    => $Time,
            'resttime'      => $Rest,
            // someone else's fleet heading for one of the viewer's planets with a hostile mission (attack, ACS attack, spy, destroy, missile)
            'hostile'       => $FleetState == 0 && $fleetRow['owner'] != $this->userId && $fleetRow['target_owner'] == $this->userId && in_array((int) $fleetRow['mission'], [1, 2, 6, 9, 10], true),
            // structured facts for the live event feed (LiveEventsPayload)
            'id'            => (int) $fleetRow['id'],
            'mission'       => (int) $fleetRow['mission'],
            'returning'     => $FleetState == 1,
            'own'           => $fleetRow['owner'] == $this->userId,
            'cssClass'      => preg_match('/^<span class="([A-Za-z0-9_ -]*)">/', $EventString, $m) ? $m[1] : '',
            'origin'        => [
                'galaxy' => (int) $fleetRow['start_galaxy'],
                'system' => (int) $fleetRow['start_system'],
                'planet' => (int) $fleetRow['start_planet'],
                'type'   => (int) $fleetRow['start_type'],
                'name'   => (string) ($fleetRow['own_planetname'] ?? ''),
            ],
            'target'        => [
                'galaxy' => (int) $fleetRow['end_galaxy'],
                'system' => (int) $fleetRow['end_system'],
                'planet' => (int) $fleetRow['end_planet'],
                'type'   => (int) $fleetRow['end_type'],
                'name'   => (string) ($fleetRow['target_planetname'] ?? ''),
            ],
        ];
    }

    /**
     * Text of one fleet event.
     * @param int $Status FLEET_OUTWARD, FLEET_RETURN or FLEET_HOLD.
     * @param array<mixed> $fleetRow
     * @return array{0: int, 1: string, 2: int} seconds left, translated HTML line (mission and viewer specific:
     * own, foreign or hostile wording), event timestamp.
     */
    public function getEventData(array $fleetRow, int $Status): array
    {
        $LNG = $this->state->LNG;
        $Owner          = $fleetRow['owner'] == $this->userId;
        $friendly = !$Owner && $fleetRow['target_owner'] != $this->userId;

        $MissionType = $fleetRow['mission'];
        if ($friendly && $fleetRow['group'] != 0) {
            $MissionType = Mission::Acs->value;
        }

        $FleetPrefix = ($Owner == true) ? 'own' : '';
        if ($MissionType != Mission::Attack->value && !$Owner && !$friendly && $fleetRow['group'] != 0) {
            $FleetPrefix = 'hostile';
        }
        $FleetType = $FleetPrefix . self::MISSION_STYLES[$MissionType];
        if (
            !$Owner
            && ($MissionType == Mission::Attack->value || $MissionType == Mission::Acs->value)
            && $Status == FLEET_OUTWARD && $fleetRow['target_owner'] != $this->userId
        ) {
            $FleetName = $LNG['cff_acs_fleet'];
        } else {
            $FleetName = $LNG['ov_fleet'];
        }

        // In the phalanx the table is built for the scanned player: their fleets must not read "Your ..."
        $ownText = $Owner && !$this->IsPhalanx;

        $EventString = $this->eventText([
            'row'       => $fleetRow,
            'status'    => $Status,
            'mission'   => $MissionType,
            'owner'     => $Owner,
            'fleetType' => $FleetType,
            'content'   => $this->createFleetPopupedFleetLink($fleetRow, $FleetName, $FleetPrefix . self::MISSION_STYLES[$MissionType]),
            'capacity'  => $this->createFleetPopupedMissionLink($fleetRow, $LNG['type_mission_' . $MissionType], $FleetPrefix . self::MISSION_STYLES[$MissionType]),
            'start'     => $LNG['type_planet_' . $fleetRow['start_type']],
            'target'    => $LNG['type_planet_' . $fleetRow['end_type']],
            'textKey'   => function (string $own) use ($LNG, $ownText): string {
                $other = str_replace('cff_mission_own_', 'cff_mission_other_', $own);

                return !$ownText && isset($LNG[$other]) ? $other : $own;
            },
        ]);

        $FleetClass = $this->eventClass($fleetRow, $MissionType, $Status, $Owner);

        $EventString = '<span class="' . $FleetClass . '">' . $EventString . '</span>';

        if ($Status == FLEET_OUTWARD) {
            $Time = $fleetRow['arrival_time'];
        } elseif ($Status == FLEET_RETURN) {
            $Time = $fleetRow['return_time'];
        } elseif ($Status == FLEET_HOLD) {
            $Time = $fleetRow['stay_time'];
        } else {
            $Time = $this->state->runtime->timestamp;
        }

        $Rest   = $Time - $this->state->runtime->timestamp;
        return [$Rest, $EventString, $Time];
    }

    /**
     * The wording of an event by mission, status and viewer (own, foreign or hostile fleet).
     *
     * @param array<string, mixed> $c context from getEventData(): row (fleet row), status, mission (effective mission,
     *        ACS for friendly group members), owner (viewer owns the fleet), fleetType (css class prefix + style),
     *        content/capacity (popup links), start/target (planet type names), textKey (picks the own/other wording)
     */
    private function eventText(array $c): string
    {
        $MissionType = $c['mission'];
        if ($MissionType == Mission::Recycling->value) {
            return $this->recyclingEventText($c);
        }
        if ($MissionType == Mission::Missile->value) {
            return $this->missileEventText($c);
        }
        if ($MissionType == Mission::Expedition->value) {
            return $this->expeditionEventText($c);
        }

        return $this->flightEventText($c);
    }

    /**
     * Recycling: outward and return wording. @param array<string, mixed> $c see eventText()
     *
     * @param array<mixed> $c
     */
    private function recyclingEventText(array $c): string
    {
        [$fleetRow, $Status, $FleetType, $FleetContent, $FleetCapacity, $StartType, $TargetType, $Owner, $textKey] = [$c['row'], $c['status'], $c['fleetType'], $c['content'], $c['capacity'], $c['start'], $c['target'], $c['owner'], $c['textKey']];
        $LNG = $this->state->LNG;

        if ($Status == FLEET_OUTWARD) {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_recy_0'),
                $FleetContent,
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                GetTargetAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        } else {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_recy_1'),
                $FleetContent,
                GetTargetAddressLink($fleetRow, $FleetType),
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        }

        return $EventString;
    }

    /**
     * Missile attack: own and target wording. @param array<string, mixed> $c see eventText()
     *
     * @param array<mixed> $c
     */
    private function missileEventText(array $c): string
    {
        [$fleetRow, $Status, $FleetType, $FleetContent, $FleetCapacity, $StartType, $TargetType, $Owner, $textKey] = [$c['row'], $c['status'], $c['fleetType'], $c['content'], $c['capacity'], $c['start'], $c['target'], $c['owner'], $c['textKey']];
        $LNG = $this->state->LNG;

        if ($Owner) {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_mip'),
                $fleetRow['ship_amount'],
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $TargetType,
                $fleetRow['target_planetname'],
                GetTargetAddressLink($fleetRow, $FleetType),
            );
        } else {
            $EventString = $LNG->format(
                'cff_mission_target_mip',
                $fleetRow['ship_amount'],
                $this->buildHostileFleetPlayerLink($fleetRow),
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $TargetType,
                $fleetRow['target_planetname'],
                GetTargetAddressLink($fleetRow, $FleetType),
            );
        }

        return $EventString;
    }

    /**
     * Expedition: outward, hold and return wording. @param array<string, mixed> $c see eventText()
     *
     * @param array<mixed> $c
     */
    private function expeditionEventText(array $c): string
    {
        [$fleetRow, $Status, $FleetType, $FleetContent, $FleetCapacity, $StartType, $TargetType, $Owner, $textKey] = [$c['row'], $c['status'], $c['fleetType'], $c['content'], $c['capacity'], $c['start'], $c['target'], $c['owner'], $c['textKey']];
        $LNG = $this->state->LNG;

        if ($Status == FLEET_OUTWARD) {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_expo_0'),
                $FleetContent,
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                GetTargetAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        } elseif ($Status == FLEET_HOLD) {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_expo_2'),
                $FleetContent,
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                GetTargetAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        } else {
            $EventString = $LNG->format(
                $textKey('cff_mission_own_expo_1'),
                $FleetContent,
                GetTargetAddressLink($fleetRow, $FleetType),
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        }

        return $EventString;
    }

    /**
     * Foreign fleet of a flight event: ACS call for outward attacks, otherwise stay, good or bad visit.
     *
     * @param array<string, mixed> $c see eventText()
     */
    private function foreignFlightEventText(array $c): string
    {
        $MissionType = $c['mission'];
        $GoodMissions = [Mission::Transport->value, Mission::Hold->value];
        [$fleetRow, $Status, $FleetType, $FleetContent, $FleetCapacity, $StartType, $TargetType] = [$c['row'], $c['status'], $c['fleetType'], $c['content'], $c['capacity'], $c['start'], $c['target']];
        $LNG = $this->state->LNG;

        if (($MissionType == Mission::Attack->value || $MissionType == Mission::PiratebaseAttack->value || $MissionType == Mission::Acs->value) && $Status == FLEET_OUTWARD) {
            $EventString = $LNG->format(
                'cff_mission_acs',
                $FleetContent,
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $TargetType,
                $fleetRow['target_planetname'],
                GetTargetAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        } else {
            if ($Status == FLEET_HOLD) {
                $Message    = 'cff_mission_target_stay';
            } elseif (in_array($MissionType, $GoodMissions)) {
                $Message    = 'cff_mission_target_good';
            } else {
                $Message    = 'cff_mission_target_bad';
            }

            $EventString = $LNG->format(
                $Message,
                $FleetContent,
                $this->buildHostileFleetPlayerLink($fleetRow),
                $StartType,
                $fleetRow['own_planetname'],
                GetStartAddressLink($fleetRow, $FleetType),
                $TargetType,
                $fleetRow['target_planetname'],
                GetTargetAddressLink($fleetRow, $FleetType),
                $FleetCapacity,
            );
        }

        return $EventString;
    }

    /**
     * All other missions: own fleets by status, foreign ones as ACS call, stay, good or bad visit. @param array<string, mixed> $c see eventText()
     *
     * @param array<mixed> $c
     */
    private function flightEventText(array $c): string
    {
        [$fleetRow, $Status, $FleetType, $FleetContent, $FleetCapacity, $StartType, $TargetType, $Owner, $textKey] = [$c['row'], $c['status'], $c['fleetType'], $c['content'], $c['capacity'], $c['start'], $c['target'], $c['owner'], $c['textKey']];
        $LNG = $this->state->LNG;

        if ($Owner == true) {
            if ($Status == FLEET_OUTWARD) {
                $EventString  = $LNG->format(
                    $textKey('cff_mission_own_0'),
                    $FleetContent,
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $TargetType,
                    $fleetRow['target_planetname'],
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $FleetCapacity,
                );
            } elseif ($Status == FLEET_RETURN) {
                $EventString  = $LNG->format(
                    $textKey('cff_mission_own_1'),
                    $FleetContent,
                    $TargetType,
                    $fleetRow['target_planetname'],
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $FleetCapacity,
                );
            } else {
                $EventString  = $LNG->format(
                    $textKey('cff_mission_own_2'),
                    $FleetContent,
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $TargetType,
                    $fleetRow['target_planetname'],
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $FleetCapacity,
                );
            }
        } else {
            $EventString = $this->foreignFlightEventText($c);
        }

        return $EventString;
    }

    /**
     * The css class of an event line: the mission colour (own or foreign, ACS also friend) of an outward event, the
     * return colours for returns and holds.
     *
     * @param array<string, mixed> $fleetRow fleet row
     * @param int $MissionType effective mission (ACS for friendly group members)
     */
    private function eventClass(array $fleetRow, int $MissionType, int $Status, bool $Owner): string
    {
        $db = $this->db;
        $FleetClass = '';
        if ($Status == FLEET_OUTWARD) {
            if ($MissionType == Mission::Acs->value) {
                $sql = "SELECT count(uta.`acsID`) as count FROM %%USERS_TO_ACS%% AS uta 
                    INNER JOIN %%FLEETS%% AS f ON uta.`acsID` = f.`group`
                    WHERE uta.`userID` = :userId AND f.`id` = :fleetId;";
                $friend = $db->selectSingle($sql, [
                    ':userId'   => $this->state->USER['id'],
                    ':fleetId'  => $fleetRow['id'],
                ], 'count');
                if ($Owner == true) {
                    $FleetClass = 'colorMission2Own';
                } elseif ($friend > 0) {
                    $FleetClass = 'colorMission2friend';
                } else {
                    $FleetClass = 'colorMission2Foreign';
                }
            } elseif ($MissionType == Mission::Attack->value || $MissionType == Mission::PiratebaseAttack->value) {
                $FleetClass = $Owner ? 'colorMission1Own' : 'colorMission1Foreign';
            } elseif ($MissionType == Mission::Transport->value) {
                $FleetClass = $Owner ? 'colorMission3Own' : 'colorMission3Foreign';
            } elseif ($MissionType == Mission::Station->value) {
                $FleetClass = $Owner ? 'colorMission4Own' : 'colorMission4Foreign';
            } elseif ($MissionType == Mission::Hold->value) {
                $FleetClass = $Owner ? 'colorMission5Own' : 'colorMission5Foreign';
            } elseif ($MissionType == Mission::Spy->value || $MissionType == Mission::PiratebaseSpy->value) {
                $FleetClass = $Owner ? 'colorMission6Own' : 'colorMission6Foreign';
            } elseif ($MissionType == Mission::Colonisation->value) {
                $FleetClass = $Owner ? 'colorMission7Own' : 'colorMission7Foreign';
            } elseif ($MissionType == Mission::Recycling->value || $MissionType == Mission::PiratebaseRecycling->value) {
                $FleetClass = $Owner ? 'colorMission8Own' : 'colorMission8Foreign';
            } elseif ($MissionType == Mission::Destruction->value) {
                $FleetClass = $Owner ? 'colorMission9Own' : 'colorMission9Foreign';
            } elseif ($MissionType == Mission::Missile->value) {
                $FleetClass = $Owner ? 'colorMission10Own' : 'colorMission10Foreign';
            } elseif ($MissionType == Mission::Expedition->value) {
                $FleetClass = $Owner ? 'colorMission15Own' : 'colorMission15Foreign';
            } elseif ($MissionType == Mission::Trade->value) {
                $FleetClass = $Owner ? 'colorMission16Own' : 'colorMission16Foreign';
            } elseif ($MissionType == Mission::Transfer->value) {
                $FleetClass = $Owner ? 'colorMission17Own' : 'colorMission17Foreign';
            }
        } else {
            if ($MissionType == Mission::Colonisation->value) {
                $FleetClass = $Owner ? 'colorMission7OwnReturn' : 'colorMissionReturnForeign';
            } else {
                $FleetClass = $Owner ? 'colorMissionReturnOwn' : 'colorMissionReturnForeign';
            }
        }

        return $FleetClass;
    }

    /**
     * @param array<mixed> $fleetRow
     */
    private function buildHostileFleetPlayerLink(array $fleetRow): string
    {
        $LNG = $this->state->LNG;
        return htmlspecialchars((string) $fleetRow['own_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
            . ' <a href="#" data-action="dialog-pm" data-id="' . (int) $fleetRow['owner'] . '">' . $LNG['PM'] . '</a>';
    }

    /**
     * Mission name as a link with a hover popup showing the carried resources (plain text when the fleet carries
     * nothing or in phalanx mode).
     *
     * @param array<mixed> $fleetRow
     */
    private function createFleetPopupedMissionLink(array $fleetRow, string $Texte, string $FleetType): string
    {
        $LNG = $this->state->LNG;
        $FleetTotalC  = $fleetRow['resource_metal'] + $fleetRow['resource_crystal']
            + $fleetRow['resource_deuterium'];
        if ($FleetTotalC != 0 && !$this->IsPhalanx) {
            $textForBlind = $LNG['tech'][900] . ': ';
            $textForBlind .= floatToString($fleetRow['resource_metal']) . ' ' . $LNG['tech'][901];
            $textForBlind .= '; ' . floatToString($fleetRow['resource_crystal']) . ' ' . $LNG['tech'][902];
            $textForBlind .= '; ' . floatToString($fleetRow['resource_deuterium']) . ' ' . $LNG['tech'][903];

            $FRessource   = '<table class=\'tip-table\'>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][901]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_metal'])
                . '</td></tr>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][902]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_crystal'])
                . '</td></tr>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][903]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_deuterium'])
                . '</td></tr>';
            $FRessource  .= '</table>';

            $MissionPopup  = '<a data-tooltip-content="' . $FRessource . '" class="tooltip ' . $FleetType . '">'
                . $Texte . '</a><span class="textForBlind"> (' . $textForBlind . ')</span>';
        } else {
            $MissionPopup  = $Texte;
        }

        return $MissionPopup;
    }

    /**
     * "Fleet" label with a hover popup that lists the ships. Own fleets show types and amounts; foreign fleets
     * depend on the viewer's espionage tech (the phalanx always shows at least the ship count): below 8 the
     * number of ships, from 4 also the ship types, from 8 types with amounts; below 2 nothing.
     *
     * @param array<mixed> $fleetRow
     */
    private function createFleetPopupedFleetLink(array $fleetRow, string $Text, string $FleetType): string
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $elements = $this->elements();
        $SpyTech        = $USER[$elements->column(106)];
        $Owner          = $fleetRow['owner'] == $this->userId && !$this->IsPhalanx;
        $FleetRec       = explode(';', $fleetRow['ships']);
        $FleetPopup     = '<a href="#" data-tooltip-content="<table class=\'tip-table\'>';
        $textForBlind   = '';
        if ($this->IsPhalanx || $SpyTech >= 2 || $Owner) {
            if ($SpyTech < 8 && !$Owner) {
                $addColon = $SpyTech >= 4 ? ": " : "";
                $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['cff_aproaching']
                    . $fleetRow['ship_amount'] . $LNG['cff_ships'] . $addColon . '</td></tr>';
                $textForBlind   = $LNG['cff_aproaching'] . $fleetRow['ship_amount'] . $LNG['cff_ships'] . $addColon;
            }
            $shipsData  = [];
            foreach ($FleetRec as $Item => $Group) {
                if (empty($Group)) {
                    continue;
                }

                $Ship    = explode(',', $Group);
                if ($Owner) {
                    $FleetPopup     .= '<tr><td>' . $LNG['tech'][$Ship[0]]
                        . ':</td><td>' . $this->viewHelpers()->prettyNumber($Ship[1]) . '</td></tr>';
                    $shipsData[]    = floatToString($Ship[1]) . ' ' . $LNG['tech'][$Ship[0]];
                } else {
                    if ($SpyTech >= 8) {
                        $FleetPopup     .= '<tr><td>' . $LNG['tech'][$Ship[0]]
                            . ':</td><td>' . $this->viewHelpers()->prettyNumber($Ship[1]) . '</td></tr>';
                        $shipsData[]    = floatToString($Ship[1]) . ' ' . $LNG['tech'][$Ship[0]];
                    } elseif ($SpyTech >= 4) {
                        $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['tech'][$Ship[0]]
                            . '</td></tr>';
                        $shipsData[]    = $LNG['tech'][$Ship[0]];
                    }
                }
            }
            $textForBlind   .= implode('; ', $shipsData);
        } else {
            $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['cff_no_fleet_data']
                . '</span></td></tr>';
            $textForBlind   = $LNG['cff_no_fleet_data'];
        }

        $FleetPopup  .= '</table>" class="tooltip ' . $FleetType . '">' . $Text . '</a><span class="textForBlind"> ('
            . $textForBlind . ')</span>';

        return $FleetPopup;
    }
}
