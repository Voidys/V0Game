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

use App\Security\SecretAudit;
use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use App\Runtime\InstallService;
use App\Runtime\UpdateCheck;
use Symfony\Component\HttpFoundation\Response;

/** Admin dashboard: security notices, player and universe statistics, system and cron status. */
class OverviewController extends AbstractAdminController
{
    public function __construct(
        private readonly UpdateCheck $updateCheck,
        private readonly SecretAudit $secretAudit,
    ) {}

    /**
     * Admin dashboard. Notices for ADM (leftover update.php / webinstall.php / var/ENABLE_INSTALL_TOOL, var/ directories that are not
     * writable), figures of the emulated universe (players online within the last 15 minutes, players, fleets), active cronjobs
     * and a warning for jobs whose lock is still set (stuck runs). Read-only.
     */
    #[GameRoute('admin', 'admin/overview', 'overview')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $Message = $USER['authlevel'] >= AuthLevel::Admin->value ? $this->setupNotices() : [];

        // dashboard figures of the emulated universe
        $universe = $this->universe()->getEmulated();
        $now = $this->state->runtime->timestamp;
        $db = $this->db;
        $stats = [
            'online' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE universe = :uni AND onlinetime >= :since;', [':uni' => $universe, ':since' => $now - 15 * 60], 'c'),
            'players' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE universe = :uni;', [':uni' => $universe], 'c'),
            'fleets' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE universe = :uni;', [':uni' => $universe], 'c'),
        ];
        $cronjobs = $db->select('SELECT name, nextTime, `lock` FROM %%CRONJOBS%% WHERE isActive = 1 ORDER BY nextTime;');
        $stuckCronjobs = array_values(array_filter($cronjobs, static fn(array $job): bool => !empty($job['lock'])));
        if ($USER['authlevel'] >= AuthLevel::Admin->value && $stuckCronjobs !== []) {
            $Message[] = $LNG->format('adm_dash_cron_locked', implode(', ', array_column($stuckCronjobs, 'name')));
        }
        $cronWarning = $USER['authlevel'] >= AuthLevel::Admin->value ? $this->cronWarning() : null;
        $config = $this->configs->get($universe);
        $uniStatusLabels = [0 => 'se_uni_status_regopen_gameopen', 1 => 'se_uni_status_regclosed_gameclosed', 2 => 'se_uni_status_regopen_gameclosed', 3 => 'se_uni_status_regclosed_gameopen'];
        $system = [
            'version' => $config->version,
            'php' => PHP_VERSION,
            'uniStatus' => $LNG[$uniStatusLabels[(int) $config->uni_status] ?? ''] ?? (string) $config->uni_status,
            'uniStatusOpen' => (int) $config->uni_status === 0,
            'nextCron' => $cronjobs[0] ?? null,
            'cronjobs' => count($cronjobs),
        ];

        $unprotected = $USER['authlevel'] >= AuthLevel::Admin->value ? $this->unprotectedWarnings($config) : [];

        // update notice (admins only, off with the global setting update_check: then no request at all)
        $update = null;
        if ($USER['authlevel'] >= AuthLevel::Admin->value && ($config->update_check ?? true)) {
            $update = $this->updateCheck->status();
            if ($update['ok']) {
                $update['publishedText'] = !empty($update['published']) ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $update['published'], $USER['timezone']) : '';
                $update['checkedText'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $update['checkedAt'], $USER['timezone']);
            } else {
                $update = null;
            }
        }

        $this->assign([
            'update'            => $update,
            'updateDocsUrl'     => UpdateCheck::UPDATE_DOCS_URL,
            'gitVersion'        => is_dir($this->state->runtime->rootPath . '.git') ? $this->state->runtime->gitVersion : '',
            'unprotected'       => $unprotected,
            'Messages'          => $Message,
            'cronWarning'       => $cronWarning,
            'cronDocsUrl'       => InstallService::CRON_DOCS_URL,
            'stats'             => $stats,
            'system'            => $system,
            'nextCronTime'      => $system['nextCron'] ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $system['nextCron']['nextTime'], $USER['timezone']) : '–',
            'date'              => date('m\_Y', $this->state->runtime->timestamp),
        ]);

        return $this->render('admin/OverviewBody.html.twig');
    }

    /** POST: forgets the cached update check and looks again. */
    #[GameRoute('admin', 'admin/overview/update-check', 'overview', 'updateCheck', ['POST'])]
    public function updateCheck(): Response
    {
        if ($this->state->USER['authlevel'] >= AuthLevel::Admin->value && ($this->configs->get($this->universe()->getEmulated())->update_check ?? true)) {
            $this->updateCheck->status(true);
        }

        return $this->redirectToRoute('admin_overview_show');
    }

    /**
     * Warning when the cron job is not running: it never ran, or its last run is more than 5 minutes ago (it should run
     * every minute). Null when it runs.
     */
    private function cronWarning(): ?string
    {
        $LNG = $this->state->LNG;
        $last = $this->db->selectSingle('SELECT MAX(executionTime) AS t FROM %%CRONJOBS_LOG%%;', [], 't');
        $lastTs = $last ? strtotime((string) $last) : false;
        if ($lastTs === false) {
            return $LNG['adm_dash_cron_never'];
        }
        if ($this->state->runtime->timestamp - $lastTs > 300) {
            return $LNG->format('adm_dash_cron_stale', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $lastTs, $this->state->USER['timezone']));
        }

        return null;
    }

    /**
     * Setup notices for admins: leftover update.php / webinstall.php / var/ENABLE_INSTALL_TOOL, missing canonical URL
     * and var/ directories that are not writable.
     *
     * @return list<string>
     */
    private function setupNotices(): array
    {
        $LNG = $this->state->LNG;
        $Message = [];
        if (file_exists($this->state->runtime->rootPath . 'update.php')) {
            $Message[] = $LNG->format('ow_file_detected', 'update.php');
        }

        if (file_exists($this->state->runtime->rootPath . 'webinstall.php')) {
            $Message[] = $LNG->format('ow_file_detected', 'webinstall.php');
        }

        if (file_exists('var/ENABLE_INSTALL_TOOL')) {
            $Message[] = $LNG->format('ow_file_detected', 'var/ENABLE_INSTALL_TOOL');
        }

        if (!$this->canonicalUrl->canBuildLinks()) {
            $Message[] = $LNG['ow_default_uri_missing'];
        }

        // a public or short APP_SECRET lets anyone forge remember-me cookies; dev mode leaks errors and slows the game
        if ($this->secretAudit->weakSecret()) {
            $Message[] = $LNG['ow_app_secret_weak'];
        }
        if ($this->secretAudit->developmentMode()) {
            $Message[] = $LNG['ow_app_env_dev'];
        }

        // runtime data lives in var/ (game caches, sessions, logs)
        foreach (['var/game', 'var/sessions', 'var/log'] as $dir) {
            if (!is_writable($this->state->runtime->rootPath . $dir)) {
                $Message[] = $LNG->format('ow_dir_not_writable', $dir);
            }
        }

        return $Message;
    }

    /**
     * Warnings for disabled or weakened abuse protections of the universe config.
     *
     * @return list<string>
     */
    private function unprotectedWarnings(object $config): array
    {
        $LNG = $this->state->LNG;
        $unprotected = [];
        if (!$config->transport_push_check || (float) $config->transport_push_limit <= 0) {
            $unprotected[] = $LNG['adm_dash_unprotected_push'];
        }
        if ((int) $config->honor_pair_cap === 0) {
            $unprotected[] = $LNG['adm_dash_unprotected_honor'];
        }
        if ($config->bash_war_exempt) {
            $unprotected[] = $LNG['adm_dash_unprotected_bash'];
        }
        if (!$config->depot_cost_real_consumption) {
            $unprotected[] = $LNG['adm_dash_unprotected_depot'];
        }
        if ((int) $config->market_push_tolerance > 10) {
            $unprotected[] = $LNG['adm_dash_unprotected_market'];
        }

        return $unprotected;
    }
}
