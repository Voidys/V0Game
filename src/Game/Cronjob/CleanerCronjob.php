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

namespace App\Game\Cronjob;

use App\Game\Enum\AuthLevel;
use App\Game\Enum\MessageType;
use App\Game\Core\Universe;

/**
 * Weekly cleaning (default Saturday 02:45, cronjob "cleaner"): deletes old data in all tables and removes
 * players and accounts that are inactive or flagged for deletion.
 */
class CleanerCronjob extends AbstractCronjobTask
{
    /**
     * Steps: user messages older than message_delete_days (favorites kept, except spy reports) and orphaned message
     * bodies; empty alliances with their ranks; planets whose `destruyed` time has passed; expired sessions
     * (setting session_lifetime); fleet events without fleet; fleet logs and phalanx logs older than
     * del_oldstuff days; advanced-stat history older than 7 days; resets debris-field flags (tf_active). Per
     * universe (skipped for registration-only universes): deletes players (authlevel user) who requested deletion
     * more than del_user_manually days ago or were offline longer than del_user_automatic days (0 = off).
     * Accounts: deletes accounts that requested deletion, and accounts without any player and inactive for a month.
     */
    public function run(): void
    {
        $config = $this->configs->get(ROOT_UNI);
        $unis   = $this->universe()->availableUniverses();

        //Delete old messages
        $del_before     = $this->state->runtime->timestamp - ($config->del_oldstuff * 86400);
        $del_messages   = $this->state->runtime->timestamp - ($config->message_delete_days * 86400);

        $this->deleteOldMessages($del_messages);
        $this->deleteExpiredGameData($del_before);
        $this->deleteInactivePlayers($unis);
        $this->deleteAccounts();
    }

    /**
     * Deletes user messages older than the cutoff (favorites stay, except spy reports; messages marked deleted before
     * the cutoff go too) and the message bodies no user refers to any more.
     *
     * @param int $del_messages cutoff timestamp
     */
    private function deleteOldMessages(int|float $del_messages): void
    {
        $db = $this->db;
        $sql = 'DELETE um FROM %%USERS_TO_MESSAGES%% um
            LEFT JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
            WHERE `deleted_at` < :time OR (um.`created_at` < :time AND (um.`message_favorite` = 0 OR m.`message_type` = :mess_type_spy));';
        $db->delete($sql, [
            ':time'             => $del_messages,
            ':mess_type_spy'    => MessageType::SpyReport->value,
        ]);

        $sql = 'DELETE m
            FROM %%MESSAGES%% m
            LEFT JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            WHERE um.message_id IS NULL;';
        $db->delete($sql);
    }

    /**
     * Deletes what has expired or lost its owner: empty alliances with their ranks, planets past their destruction time,
     * expired sessions, fleet events without fleet, fleet and phalanx logs older than the cutoff, advanced-stat history
     * older than 7 days; resets the debris field flags (tf_active).
     *
     * @param int $del_before cutoff timestamp of the fleet and phalanx logs (del_oldstuff days)
     */
    private function deleteExpiredGameData(int|float $del_before): void
    {
        $db = $this->db;
        // ranks of empty alliances go first, while the alliance rows still identify them
        $sql = 'DELETE r FROM %%ALLIANCE_RANK%% r JOIN %%ALLIANCE%% a ON a.`id` = r.`allianceID` WHERE a.`ally_members` = 0;';
        $db->delete($sql);

        $emptyAlliances = '(SELECT `id` FROM %%ALLIANCE%% WHERE `ally_members` = 0)';
        $db->delete('DELETE FROM %%DIPLO%% WHERE `owner_1` IN ' . $emptyAlliances . ' OR `owner_2` IN ' . $emptyAlliances . ';');
        $db->delete('DELETE FROM %%STATPOINTS%% WHERE `stat_type` = 2 AND `id_owner` IN ' . $emptyAlliances . ';');
        $db->delete('DELETE FROM %%STATPOINTS_HISTORY%% WHERE `stat_type` = 2 AND `id_owner` IN ' . $emptyAlliances . ';');

        $db->delete('DELETE FROM %%ENG_ALLIANCE_GOALS%% WHERE `alliance_id` IN ' . $emptyAlliances . ';');

        $sql = 'DELETE FROM %%ALLIANCE%% WHERE `ally_members` = 0;';
        $db->delete($sql);

        $sql = 'DELETE FROM %%PLANETS%% WHERE `destruyed` < :time AND `destruyed` != 0;';
        $db->delete($sql, [
            ':time' => $this->state->runtime->timestamp,
        ]);

        $sql = 'DELETE FROM %%SESSION%% WHERE `lastonline` < :time;';
        $db->delete($sql, [
            ':time' => $this->state->runtime->timestamp - \App\Security\SessionPolicy::idleSeconds($this->configs->get(ROOT_UNI)->session_lifetime ?? null),
        ]);

        $sql = 'DELETE FROM %%FLEETS_EVENT%% WHERE `fleetID` NOT IN (SELECT `id` FROM %%FLEETS%%);';
        $db->delete($sql);

        $sql = 'DELETE FROM %%LOG_FLEETS%% WHERE `start_time` < :time;';
        $db->delete($sql, [
            ':time' => $del_before,
        ]);

        $sql = 'UPDATE %%PLANETS%% SET `tf_active` = 0 WHERE `tf_active` = 1;';
        $db->update($sql);

        $sql = 'DELETE FROM %%PHALANX_LOG%% WHERE `phalanx_time` < :time;';
        $db->delete($sql, [
            ':time' => $del_before,
        ]);

        $sql = 'DELETE FROM %%PHALANX_FLEETS%% WHERE `phalanx_log_id` NOT IN (SELECT `id` FROM %%PHALANX_LOG%%)';
        $db->delete($sql);

        $sql = "DELETE FROM %%ADVANCED_STATS_HISTORY%% WHERE `timestamp` < :timestamp;";
        $db->delete($sql, [
            ':timestamp' => $this->state->runtime->timestamp - (7 * 86400),
        ]);
    }

    /**
     * Deletes the players (authlevel user) of every universe except registration-only ones who requested the deletion
     * more than del_user_manually days ago or were offline longer than del_user_automatic days (0 = off), separately switchable and with own day counts
     * for players in vacation mode (del_user_vacation*) and banned players (del_user_banned*; 0 days = same as inactive players).
     *
     * @param iterable<int> $unis available universes
     */
    private function deleteInactivePlayers(iterable $unis): void
    {
        $db = $this->db;
        foreach ($unis as $uni) {
            $config = $this->configs->get($uni);
            $status = $config->uni_status;
            if ($status == STATUS_REG_ONLY) {
                continue;
            }

            $now            = $this->state->runtime->timestamp;
            $inactiveDays   = (int) $config->del_user_automatic;
            // per group: threshold timestamp, 0 = group off (no onlinetime is smaller than 0, so nobody matches);
            // days 0 for vacation/banned players means "same as inactive players" (the former single rule)
            $limit = static fn(bool $on, int $days): int => $on && ($days > 0 ? $days : $inactiveDays) > 0
                ? $now - (($days > 0 ? $days : $inactiveDays) * 86400)
                : 0;
            $del_deleted    = $now - ($config->del_user_manually * 86400);
            $sql = 'SELECT `id` FROM %%USERS%% WHERE `authlevel` = :authlevel AND universe = :uni
                AND ((`db_deaktjava` != 0 AND `db_deaktjava` < :timeDeleted)
                OR (`bana` = 0 AND `urlaubs_modus` = 0 AND `onlinetime` < :timeInactive)
                OR (`bana` = 0 AND `urlaubs_modus` = 1 AND `onlinetime` < :timeVacation)
                OR (`bana` != 0 AND `onlinetime` < :timeBanned));';

            $deleteUserIds = $db->select($sql, [
                ':authlevel'    => AuthLevel::User->value,
                ':timeDeleted'  => $del_deleted,
                ':timeInactive' => $limit((bool) $config->del_user_automatic_on, $inactiveDays),
                ':timeVacation' => $limit((bool) $config->del_user_vacation_on, (int) $config->del_user_vacation),
                ':timeBanned'   => $limit((bool) $config->del_user_banned_on, (int) $config->del_user_banned),
                ':uni'          => $uni,
            ]);

            if (!empty($deleteUserIds)) {
                foreach ($deleteUserIds as $dataRow) {
                    $this->playerDeletion()->deletePlayer($dataRow['id']);
                }
            }
        }
    }

    /**
     * Deletes the accounts that requested their deletion, and the accounts without any player that were inactive for a
     * month.
     */
    private function deleteAccounts(): void
    {
        $db = $this->db;
        //delete accounts set to delete
        $config = $this->configs->get();
        $del_deleted  = $this->state->runtime->timestamp - ($config->del_user_manually * 86400);
        $sql = 'SELECT `id` FROM %%ACCOUNTS%% WHERE `authlevel` = :authlevel AND 
            (`db_deaktjava` != 0 AND `db_deaktjava` < :timeDeleted);';

        $deleteAccountIds = $db->select($sql, [
            ':authlevel'    => AuthLevel::User->value,
            ':timeDeleted'  => $del_deleted,
        ]);

        if (!empty($deleteAccountIds)) {
            foreach ($deleteAccountIds as $dataRow) {
                $this->accountUtil()->deleteAccount($dataRow['id']);
            }
        }

        //delete accounts without users without activity for 28 days
        $sql = "SELECT * FROM %%ACCOUNTS%% WHERE `id` NOT IN (SELECT DISTINCT(account_id) FROM %%ACCOUNTS_TO_USERS%%) AND `onlinetime` < :inactive AND `authlevel` = :authlevel;";
        $deleteAccountIds = $db->select($sql, [
            ':authlevel'    => AuthLevel::User->value,
            ':inactive'     => $this->state->runtime->timestamp - TIME_1_MONTH,
        ]);

        if (!empty($deleteAccountIds)) {
            foreach ($deleteAccountIds as $dataRow) {
                $this->accountUtil()->deleteAccount($dataRow['id']);
            }
        }
    }
}
