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

namespace App\Controller\Admin;

use App\Game\Bots\BotPresets;
use App\Game\Core\Config;
use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Log;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\StoredData;
use App\Game\Core\Universe;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Universe settings of the emulated universe (schema driven form). */
class ConfigUniController extends AbstractAdminController
{
    /**
     * Saves the posted settings of the emulated universe (Config::save), writes an admin log entry (type 3) with before/after values and
     * shows the form again. Side effects of a status change (`uni_status`): opening a universe that was registration-only or
     * closed resets `last_update` of all planets (and, from registration-only, `onlinetime` of all players) so no production or inactivity accrues while
     * closed; closing an open universe first calculates the resources of all home planets. Setting `adm_attack` to 0 clears `authattack` of all players.
     */
    #[GameRoute('admin', 'admin/config/universe', 'configUni', 'save', ['POST'])]
    public function save(): Response
    {
        $config = $this->configs->get($this->universe()->getEmulated());
        $schema = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this->configs);
        $this->saveConfig($config, $schema);

        return $this->show();
    }

    /**
     * Universe settings of the emulated universe as a schema driven form (ConfigFieldSchema).
     */
    #[GameRoute('admin', 'admin/config/universe', 'configUni')]
    public function show(): Response
    {
        $config = $this->configs->get($this->universe()->getEmulated());

        // Schema laden
        $schema = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this->configs);

        $configValues = ConfigFieldProcessor::prepareTemplateData($config, $schema);

        // Selector Options + Config Values
        return $this->render('admin/ConfigUni.html.twig', [
            'configValues' => $configValues,
            'configSchema' => $schema->getSchema(),
            'flightTimePreview' => [
                'shipSpeed' => 45000,
                'speedFactor' => 10,
                'gameSpeed' => 1,
                'fleetSpeedFactor' => 1,
                'minDuration' => MIN_FLEET_TIME,
            ],
        ]);
    }

    /**
     * Saves the posted settings: snapshots the old values, processes the POST data, runs the side effects of a status
     * change, writes the config, logs the change (type 3, old/new values) and clears `authattack` when `adm_attack`
     * is switched off.
     */
    private function saveConfig(object $config, ConfigFieldSchema $schema): void
    {
        $db = $this->db;
        $config_before = ConfigFieldProcessor::createSnapshot($config, $schema);

        $config_after = ConfigFieldProcessor::processPostData($schema->getAllFields(), $this->input());
        // a newly chosen bot difficulty preset fills its knobs; editing a preset knob without changing the preset makes it "custom"
        $config_after = BotPresets::resolveOnSave(['bots_preset' => $config->bots_preset ?? null], $config_after);

        $uni_status = $config_after['uni_status'] ?? $config->uni_status;
        $this->applyStatusChange($uni_status, $config);

        // Apply all config changes at once
        foreach ($config_after as $key => $value) {
            $config->$key = $value;
        }
        $config->save();

        // Logging
        $LOG = new Log($this->db, $this->configs, $this->state, 3);
        $LOG->target = 1;
        $LOG->old = $config_before;
        $LOG->new = $config_after;
        $LOG->save();

        // Special: Disable admin_attack
        if (($config_after['adm_attack'] ?? 0) == 0) {
            $db->update("UPDATE %%USERS%% SET `authattack` = '0' WHERE `universe` = :universe;", [
                ':universe' => $this->universe()->getEmulated(),
            ]);
        }
    }

    /**
     * Side effects of a universe status change: opening after registration-only or closed resets the planets'
     * `last_update` (and, from registration-only, the players' `onlinetime`) so nothing accrues while it was closed;
     * closing an open universe first settles the resources of all home planets.
     */
    private function applyStatusChange(mixed $uni_status, object $config): void
    {
        $db = $this->db;

        if (($uni_status == STATUS_OPEN || $uni_status == STATUS_LOGIN_ONLY) && ($config->uni_status == STATUS_REG_ONLY)) {
            // If login is opened after register only, update all planet timestamps to avoid resource production during closed login times.
            $db->update("UPDATE %%PLANETS%% SET `last_update` = :newTime, `eco_hash` = '' WHERE `universe` = :universe;", [
                ':newTime'  => time(),
                ':universe' => $this->universe()->getEmulated(),
            ]);
            // And update all player online times to avoid inactives at universe start.
            $db->update("UPDATE %%USERS%% SET `onlinetime` = :newTime WHERE `universe` = :universe;", [
                ':newTime'  => time(),
                ':universe' => $this->universe()->getEmulated(),
            ]);
        } elseif (($uni_status == STATUS_OPEN || $uni_status == STATUS_LOGIN_ONLY) && ($config->uni_status == STATUS_CLOSED)) {
            // Queues did not run while closed (like vacation mode): postpone them by the closed time, taken before the timestamps are reset.
            $this->shiftQueuesByClosedTime();
            // If login is opened, update all planet timestamps to avoid resource production during closed login times.
            $db->update("UPDATE %%PLANETS%% SET `last_update` = :newTime, `eco_hash` = '' WHERE `universe` = :universe;", [
                ':newTime'  => time(),
                ':universe' => $this->universe()->getEmulated(),
            ]);
        } elseif (($uni_status == STATUS_CLOSED) && ($config->uni_status == STATUS_OPEN)) {
            // Calc ress for all planets when uni login gets closed. Otherwise ress will be lost when reopened.
            $ecoObj = new ResourceUpdate($this->db, $this->configs, $this->state);
            $db->startTransaction();
            $timestamp = time();
            $sql = "SELECT u.*, us.* FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE universe = :universe FOR UPDATE;";
            $users = $db->select($sql, [':universe' => $this->universe()->getEmulated()]);
            foreach ($users as $user) {
                $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :ownerID AND universe = :universe AND planet_type = 1 FOR UPDATE;";
                $planets = $db->select($sql, [
                    ':ownerID'  => $user['id'],
                    ':universe' => $this->universe()->getEmulated(),
                ]);
                foreach ($planets as $planet) {
                    $ecoObj->CalcResource($user, $planet, true, $timestamp);
                }
            }
            $db->commit();
        }
    }

    /**
     * Postpones the building queues of all planets and the research queues of all players that are not on vacation by the time the
     * universe was closed. Closing settled every owned planet (last_update = closing time); fleet events processed while closed can
     * only move single planets later, so the oldest owned planet `last_update` is the closing time.
     */
    private function shiftQueuesByClosedTime(): void
    {
        $db = $this->db;
        $universe = $this->universe()->getEmulated();
        $closedAt = (int) $db->selectSingle('SELECT MIN(p.`last_update`) AS closed FROM %%PLANETS%% p INNER JOIN %%USERS%% u ON u.`id` = p.`id_owner` WHERE p.`universe` = :universe AND p.`planet_type` = 1 AND p.`destruyed` = 0 AND p.`last_update` > 0;', [':universe' => $universe], 'closed');
        $delta = time() - $closedAt;
        if ($closedAt <= 0 || $delta <= 0) {
            return;
        }

        $planets = $db->select('SELECT p.`id`, p.`b_building_id` FROM %%PLANETS%% p INNER JOIN %%USERS%% u ON u.`id` = p.`id_owner` WHERE p.`universe` = :universe AND p.`b_building` != 0 AND p.`b_building_id` != \'\' AND u.`urlaubs_modus` = 0;', [':universe' => $universe]);
        foreach ($planets as $planet) {
            $queue = StoredData::decode($planet['b_building_id']);
            foreach ($queue as &$entry) {
                $entry[3] += $delta;
            }
            unset($entry);
            $db->update('UPDATE %%PLANETS%% SET `b_building` = `b_building` + :delta, `b_building_id` = :queue WHERE `id` = :id;', [
                ':delta' => $delta,
                ':queue' => StoredData::encode($queue),
                ':id' => $planet['id'],
            ]);
        }

        $users = $db->select('SELECT `id`, `b_tech_queue` FROM %%USERS%% WHERE `universe` = :universe AND `b_tech` != 0 AND `b_tech_queue` != \'\' AND `urlaubs_modus` = 0;', [':universe' => $universe]);
        foreach ($users as $user) {
            $queue = StoredData::decode($user['b_tech_queue']);
            foreach ($queue as &$entry) {
                $entry[3] += $delta;
            }
            unset($entry);
            $db->update('UPDATE %%USERS%% SET `b_tech` = `b_tech` + :delta, `b_tech_queue` = :queue WHERE `id` = :id;', [
                ':delta' => $delta,
                ':queue' => StoredData::encode($queue),
                ':id' => $user['id'],
            ]);
        }
    }
}
