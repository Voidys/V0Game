<?php

namespace App\Game\Fleet;

use App\Game\Enum\Mission;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use Exception;

/**
 * The mission rules of FleetDispatch (is the mission allowed against this target, protection rules, hold limits); works on
 * the dispatch state of the host class and refuses through its refuse() method.
 */
trait FleetDispatchMissionRules
{
    /** Checks the mission is allowed against this target: available missions, vacation, bash rule, admin attack, noob protection (or outlaw status), buddy/alliance for hold and transfer, hold limits. */
    private function checkMission(): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $MisInfo = [];
        $MisInfo['galaxy'] = $this->targetGalaxy;
        $MisInfo['system'] = $this->targetSystem;
        $MisInfo['planet'] = $this->targetPlanet;
        $MisInfo['planettype'] = $this->targetType;
        $MisInfo['IsAKS'] = $this->fleetGroup;
        $MisInfo['Ship'] = $this->fleetArray;

        $this->availableMissions = $this->fleetFunctions()->getFleetMissions($this->state->USER, $MisInfo, $this->targetPlanetData);

        if (!in_array($this->targetMission, $this->availableMissions['MissionSelector'])) {
            $this->refuse($LNG['fl_invalid_mission'], 'game_fleet_step2_show');
        }

        if ($this->targetMission != Mission::Recycling->value && IsVacationMode($this->targetPlayerData)) {
            $this->refuse($LNG['fl_in_vacation_player'], 'game_fleet_step1_show');
        }

        if ($this->targetMission == Mission::Attack->value || $this->targetMission == Mission::Acs->value || $this->targetMission == Mission::Destruction->value) {
            if (count($this->fleetArray) != 1 || !array_key_exists(210, $this->fleetArray)) {
                if ($this->fleetFunctions()->checkBash($this->targetPlanetData['id'], $this->fleetGroup)) {
                    $this->refuse($LNG['fl_bash_protection'], 'game_fleet_table_show');
                }
            }
        }
        $this->checkHostileRules();
        $this->checkHoldRules();
    }

    /** Hold and transfer need the same alliance or a buddy; hold is limited by the number of fleets and players already holding at the target. */
    private function checkHoldRules(): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        if ($this->targetMission == Mission::Hold->value || $this->targetMission == Mission::Transfer->value) {
            if ($this->targetPlayerData['ally_id'] != $this->state->USER['ally_id'] || $this->state->USER['ally_id'] == 0) {
                $sql = "SELECT COUNT(*) as state FROM %%BUDDY%%
                    WHERE `id` NOT IN (SELECT `id` FROM %%BUDDY_REQUEST%% WHERE %%BUDDY_REQUEST%%.`id` = %%BUDDY%%.`id`) 
                      AND ((`owner` = :ownerID AND `sender` = :userID) OR (`owner` = :userID AND `sender` = :ownerID));";
                $buddy = $db->selectSingle($sql, [
                    ':ownerID'  => $this->targetPlayerData['id'],
                    ':userID'   => $this->state->USER['id'],
                ], 'state');

                if ($buddy == 0) {
                    $this->refuse($LNG['fl_no_same_alliance'], 'game_fleet_table_show');
                }
            }
        }

        if ($this->targetMission == Mission::Hold->value) {
            $sql = "SELECT COUNT(id) AS anz
                FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold);";
            $fleetCount = $db->selectSingle($sql, [
                ':planetID' => $this->targetPlanetData['id'],
                ':mission'  => Mission::Hold->value,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
            ], "anz");

            $sql = "SELECT COUNT(DISTINCT `owner`) AS anz FROM %%FLEETS%%
                WHERE `end_id` = :planetID AND `mission` = :mission AND `status` IN (:stateOutward, :stateHold)
                  AND `owner` != :userID;";
            $playerCount = $db->selectSingle($sql, [
                ':planetID' => $this->targetPlanetData['id'],
                ':mission'  => Mission::Hold->value,
                ':stateOutward' => FLEET_OUTWARD,
                ':stateHold' => FLEET_HOLD,
                ':userID' => $this->state->USER['id'],
            ], "anz");

            if (($fleetCount + 1) >= $this->config->max_fleets_per_acs) {
                $this->refuse($LNG['fl_hold_max_fleets'], 'game_fleet_table_show');
            } elseif (($playerCount + 1) >= $this->config->max_participants_per_acs) {
                $this->refuse($LNG['fl_hold_max_user'], 'game_fleet_table_show');
            }
        }
    }

    /**
     * Anti-push guard (audit F-02, on by default): with the universe setting transport_push_check on and
     * transport_push_limit above 0, a transport, hold or transfer to another player is refused when, over the last
     * 7 days, the value sent to that player (cargo, plus the ships of transfers; deuterium units at the current market
     * ratios, this fleet included) exceeds the value that player sent back by more than the limit.
     */
    private function checkPushGuard(): void
    {
        $config = $this->configs->get();
        $limit = (float) $config->transport_push_limit;
        if (
            !$config->transport_push_check || $limit <= 0
            || !in_array($this->targetMission, [Mission::Transport->value, Mission::Hold->value, Mission::Transfer->value])
            || ($this->targetPlayerData['id'] ?? 0) == $this->state->USER['id']
        ) {
            return;
        }

        $market = new \App\Game\Core\MarketManager($this->db, $this->configs, $this->state);
        // a hold delivers nothing (the cargo flies home again), so only transports and transfers count
        $balance = $this->targetMission == Mission::Hold->value ? 0.0 : $market->convertToDeutNumeric([
            901 => $this->fleetResource[901],
            902 => $this->fleetResource[902],
            903 => $this->fleetResource[903],
        ]);
        if ($this->targetMission == Mission::Transfer->value) {
            $balance += $market->getFleetValue($this->fleetArray);
        }
        $senderId = (int) $this->state->USER['id'];
        $targetId = (int) $this->targetPlayerData['id'];
        $balance += $this->pushValue($market, $senderId, $targetId) - $this->pushValue($market, $targetId, $senderId);
        if ($balance > $limit) {
            $this->refuse($this->state->LNG['fl_push_guard'], 'game_fleet_table_show');
        }
    }

    /** Value (deuterium units) of the transport and transfer fleets $from sent to $to in the last 7 days, not cancelled. */
    private function pushValue(\App\Game\Core\MarketManager $market, int $from, int $to): float
    {
        $rows = $this->db->select(
            'SELECT `mission`, `ships`, `resource_metal`, `resource_crystal`, `resource_deuterium` FROM %%LOG_FLEETS%%
            WHERE `owner` = :from AND `target_owner` = :to AND `mission` IN (:transport, :transfer)
              AND `start_time` > :since AND `hasCanceled` = 0;',
            [
                ':from' => $from,
                ':to' => $to,
                ':transport' => Mission::Transport->value,
                ':transfer' => Mission::Transfer->value,
                ':since' => $this->state->runtime->timestamp - 7 * ONE_DAY,
            ],
        );
        $value = 0.0;
        foreach ($rows as $row) {
            $value += $market->convertToDeutNumeric([
                901 => (float) $row['resource_metal'],
                902 => (float) $row['resource_crystal'],
                903 => (float) $row['resource_deuterium'],
            ]);
            if ((int) $row['mission'] === Mission::Transfer->value) {
                $value += $market->getFleetValue((new \App\Game\Core\FleetString())->unserialize((string) $row['ships']));
            }
        }

        return $value;
    }

    /** Hostile missions (attack, ACS, hold, spy, destruction): admin protection, noob protection and the outlaw status for attacking stronger players. */
    private function checkHostileRules(): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        if (
            $this->targetMission == Mission::Attack->value || $this->targetMission == Mission::Acs->value || $this->targetMission == Mission::Hold->value || $this->targetMission == Mission::Spy->value
            || $this->targetMission == Mission::Destruction->value
        ) {
            if ($this->configs->get()->adm_attack == 1 && $this->targetPlayerData['authattack'] > $this->state->USER['authlevel']) {
                $this->refuse($LNG['fl_admin_attack'], 'game_fleet_table_show');
            }

            $sql = 'SELECT `total_points`
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userId AND `stat_type` = :statType';

            try {
                $this->state->USER += $this->db->selectSingle($sql, [
                    ':userId' => $this->state->USER['id'],
                    ':statType' => 1,
                ]) ?: ['total_points' => 0];
            } catch (Exception $exception) {
            }

            $IsNoobProtec = $this->protectionRules()->checkNoobProtectionFor($this->models()->user($this->state->USER), $this->models()->user($this->targetPlayerData));

            if ($IsNoobProtec['NoobPlayer']) {
                $this->refuse($LNG['fl_player_is_noob'], 'game_fleet_table_show');
            }

            if ($IsNoobProtec['StrongPlayer']) {
                if ($this->configs->get()->noob_protection_outlaw) {
                    $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                        ':outlawTime' => $this->state->runtime->timestamp,
                        ':userId'     => $this->state->USER['id'],
                    ]);
                    $statsHandler->increment($this->state->USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
                } else {
                    $this->refuse($LNG['fl_player_is_strong'], 'game_fleet_table_show');
                }
            }
        }

    }
}
