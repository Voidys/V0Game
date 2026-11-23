<?php

namespace App\Runtime;

use App\Game\Enum\AuthLevel;
use App\Routing\LeanBoot;
use Exception;
use App\Game\Core\StoredData;
use App\Game\Core\Database;
use App\Game\Core\Language;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\Session;
use App\Game\Core\Theme;
use App\Game\Core\Universe;
use App\Controller\Login\ErrorController as ShowErrorPage;

/**
 * Per-request bootstrap of the game code (formerly includes/common.php), unchanged in
 * behaviour: error handling, theme, session, user/account/planet and language for the
 * current mode, fleet event processing, closed-universe checks. Called once per request by GameKernel::boot().
 *
 * Requires a started RuntimeContext ($state->runtime) and the project root as working
 * directory (see GameKernel::boot()). Early returns for INSTALL and UPGRADE.
 */
final class GameBootstrap
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        private \App\Game\Core\Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private \App\Game\Core\Singleton $state,
        private \App\Game\Core\FlyingFleetHandler $fleetHandler,
        private \App\Game\Core\ErrorReporter $errorReporter,
        private \App\Security\GameAuth $auth,
    ) {}

    /**
     * Builds the per-request game state for the current mode (`state->runtime->mode`).
     *
     * Common: registers the error reporter, creates the theme, sends the HTML content type, makes sure the cache and
     * session directories exist. INSTALL/UPGRADE return early; a missing config/game.php redirects to the installer
     * and pending DB migrations to the upgrade page.
     * INGAME/ADMIN/CRON: loads the game session, loads game data (GameData), runs due fleet events (INGAME,
     * module MODULE_FLEET_EVENTS), loads USER (with unread message count and points), lifts an expired ban,
     * applies language, theme and timezone, then gates on bans (error page), universe status (closed/registration
     * only block normal players) and, for INGAME, locks the active planet row (FOR UPDATE) and loads PLANETS.
     * Optionally logs analytics.
     * A battle report link (/report/<id>, route game_raport_show) works without session or user.
     * LOGIN: only the language. ACCOUNT: loads the account row (or a guest stub for the public board).
     *
     * Side effects: DB writes (ban reset, analytics, fleet events), game session writes, redirects and
     * error pages that end the request via EndOfRequest.
     */
    public function run(): void
    {
        // Symfony Security for the game code (logged-in account, login, logout)
        $this->state->auth = $this->auth;

        // keep processing (fleet events, builds) when the client disconnects mid-request
        ignore_user_abort(true);
        $this->errorReporter->register();
        $this->http()->sendHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->registerModules();

        // runtime data lives in var/ (game caches in var/game/, sessions shared with Symfony in var/sessions/)
        foreach ([$this->state->runtime->cachePath, $this->state->runtime->rootPath . 'var/sessions/'] as $runtimeDir) {
            SafeFile::mkdir($runtimeDir, 0777);
        }

        $THEME      = new Theme();
        $this->state->THEME = $THEME;

        if ($this->state->runtime->mode === 'INSTALL') {
            return;
        }

        if (!file_exists('config/game.php') || filesize('config/game.php') === 0) {
            $this->http()->redirectTo('install/');
        }

        if ($this->state->runtime->mode === 'UPGRADE') {
            return;
        }

        // the admin migration page stays reachable while migrations are pending
        $migrationPage = $this->state->runtime->mode === 'ADMIN' && in_array($this->state->request?->attributes->get('_page'), ['migrations', 'maintenance'], true);
        if (!$migrationPage && $this->maintenance()->checkDBUpgrade()) {
            $this->http()->redirectTo('install/?mode=upgrade');
        }
        $this->initGuestState();

        // loading the configuration registers the universes (needed by Universe::current() below);
        // the former native session setup did this as a side effect
        $this->configs->get(ROOT_UNI);
        $THEME->setUserTheme(\App\Game\Core\Theme::resolveDefaultTheme($this->configs->get(ROOT_UNI)->default_theme ?? null));

        $LNG = null;
        if ($this->state->runtime->mode === 'INGAME' || $this->state->runtime->mode === 'ADMIN' || $this->state->runtime->mode === 'CRON') {
            $LNG = $this->bootPlayerMode($THEME);
        } elseif ($this->state->runtime->mode === 'LOGIN') {
            $LNG    = new Language($this->configs);
            $this->state->LNG = $LNG;
            $LNG->includeData(['L18N', 'INGAME', 'PUBLIC', 'CUSTOM']);
        } elseif ($this->state->runtime->mode === 'ACCOUNT' && $this->auth->accountId() === 0 && $this->state->request?->attributes->get('_page') === 'board') {
            $this->bootAccountGuest();
        } elseif ($this->state->runtime->mode === 'ACCOUNT') {
            $LNG = $this->bootAccount($THEME);
        }

        // Guests have no users_settings row. The stub above uses DEFAULT_LANG, which
        // is not the language login/account pages actually render (cookie / account
        // lang). Logged-in users keep their stored decimal_separator.
        if ($LNG !== null && $this->state->USER !== [] && empty($this->state->USER['id'])) {
            $this->state->USER['decimal_separator'] = (new NumberAbbreviation())->defaultDecimalSeparatorForLang($LNG->getLanguage());
        }
    }

    /**
     * Fills `state->ACCOUNT` and `state->USER` with the guest defaults (language, timezone, no bans, authlevel 0,
     * decimal separator and the colour/STB settings of an anonymous player); real rows replace them later.
     */
    private function initGuestState(): void
    {
        $this->state->ACCOUNT = [];
        $this->state->ACCOUNT['lang'] = 'en';
        $this->state->ACCOUNT['bana'] = 0;
        $this->state->ACCOUNT['timezone'] = date_default_timezone_get() ?: 'Europe/London';
        $this->state->ACCOUNT['urlaubs_modus'] = 0;
        $this->state->ACCOUNT['authlevel'] = 0;
        $this->state->ACCOUNT['id'] = 0;

        $this->state->USER = [];
        $this->state->USER['lang'] = 'en';
        $this->state->USER['bana'] = 0;
        $this->state->USER['timezone'] = date_default_timezone_get() ?: 'Europe/London';
        $this->state->USER['urlaubs_modus'] = 0;
        $this->state->USER['authlevel'] = 0;
        $this->state->USER['id'] = 0;
        $this->state->USER['decimal_separator'] = (new NumberAbbreviation())->defaultDecimalSeparatorForLang(
            DEFAULT_LANG,
        );
        $this->state->USER['signalColors'] = $this->playerLookup()->player_signal_colors();
        $this->state->USER['colors'] = $this->playerLookup()->player_colors();
        $this->state->USER['stb_settings'] = $this->playerLookup()->player_stb_settings();
    }

    /**
     * Boot of INGAME, ADMIN and CRON requests after the session is known: loads the session, game data and due fleet
     * events, the player (USER) and his language/theme/timezone, gates on bans and universe status, loads the planet
     * (INGAME) or admin rights (ADMIN), logs analytics. A battle report link
     * (`raportWithoutSession`) skips the player and planet loading.
     *
     * @return Language the language of the player (also stored in `state->LNG`)
     */
    private function bootPlayerMode(Theme $THEME): Language
    {
        $raportWithoutSession = $this->auth->account() === null && $this->state->request?->attributes->get('_route') === 'game_raport_show' && $this->state->runtime->mode === 'INGAME';
        // a battle report link without login needs no game session (and so sets no cookie: the page is publicly cacheable)
        $session    = $raportWithoutSession ? null : $this->sessions()->load();

        if (!$raportWithoutSession) {
            $this->syncGameSession($session);
        }

        // lean polls load the element catalog only when due fleet events have to be processed
        $lean = $this->state->lean;
        $leanData = in_array(LeanBoot::DATA, $lean, true);
        if (!$leanData) {
            (new \App\Game\Core\GameData($this->db, $this->configs, $this->state))->load();
        }

        if ($this->state->runtime->mode === 'INGAME' && $this->viewHelpers()->isModuleAvailable(MODULE_FLEET_EVENTS)) {
            if (!$leanData || $this->fleetHandler->hasDue((int) $this->universe()->current())) {
                if ($leanData) {
                    (new \App\Game\Core\GameData($this->db, $this->configs, $this->state))->load();
                }
                $this->processFleetEvents();
            }
        }

        if (!$raportWithoutSession) {
            $this->loadSessionUser($session);
        }

        $LNG    = new Language($this->configs, $this->state->USER['lang']);
        $this->state->LNG = $LNG;
        if ($lean !== []) {
            $this->state->completeLean = $this->leanCompletion($session, $LNG, $raportWithoutSession);
        }
        if (in_array(LeanBoot::LANGUAGE, $lean, true)) {
            $LNG->includeDataLazily(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        } else {
            $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        }
        if (!empty($this->state->USER['dpath'])) {
            $THEME->setUserTheme($this->state->USER['dpath'], $this->state->USER['css_dpath'] ?? null);
        }

        $config = $this->configs->get();
        date_default_timezone_set($config->timezone);

        $this->gateBannedUser($LNG, $config);

        if ($session !== null) {
            $this->loadModeContext($session, $LNG);
        }

        $this->gateUniverseStatus($LNG, $config, $raportWithoutSession);
        $this->logAnalytics();

        return $LNG;
    }

    /**
     * Closure that loads what a lean boot skipped, for the rare paths that render a full page or an error page (module
     * switched off, ban, closed universe): the element catalog and, with `$withPlanet`, the locked planet and the planet
     * list. Idempotent.
     */
    private function leanCompletion(?\App\Game\Core\Session $session, Language $LNG, bool $raportWithoutSession): \Closure
    {
        return function (bool $withPlanet) use ($session, $LNG, $raportWithoutSession): void {
            if ($this->state->elementCatalog === null) {
                (new \App\Game\Core\GameData($this->db, $this->configs, $this->state))->load();
            }
            if ($withPlanet && in_array(LeanBoot::PLANET, $this->state->lean, true)) {
                $this->state->lean = array_values(array_diff($this->state->lean, [LeanBoot::PLANET]));
                if (!$raportWithoutSession) {
                    $this->loadModeContext($session, $LNG);
                }
            }
        };
    }

    /** Loads the element catalog skipped by a lean boot (before an error page that may need it). */
    private function completeLeanData(): void
    {
        if ($this->state->completeLean !== null) {
            ($this->state->completeLean)(false);
        }
    }

    /**
     * Loads the logged-in player (USERS + settings + points + unread message count) into `state->USER`: no player of
     * this universe sends the visitor to the account page; an expired ban is reset in the database; signal colours and
     * colours are derived from the row.
     */
    private function loadSessionUser(\App\Game\Core\Session $session): void
    {
        $db     = $this->db;

        $sql    = "SELECT
    	u.*, us.*, s.total_points, s.total_rank,
    	COUNT(um.message_id) as messages,
        CASE
            WHEN COUNT(DISTINCT message.message_type) = 1 THEN MIN(message.message_type)
            ELSE ''
        END AS message_type
    	FROM %%USERS%% u
        INNER JOIN %%ACCOUNTS_TO_USERS%% atu ON atu.user_id = u.id AND atu.account_id = :accountId AND atu.end_time = 0
        INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
        LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = :statTypeUser
    	LEFT JOIN %%USERS_TO_MESSAGES%% as um ON um.user_id = u.id AND um.message_unread = :unread AND um.deleted_at is null
        LEFT JOIN %%MESSAGES%% as message ON message.message_id = um.message_id
    	WHERE u.id = :userId
    	GROUP BY um.user_id;";

        $this->state->USER   = $db->selectSingle($sql, [
            ':statTypeUser'     => 1,
            ':unread'           => 1,
            ':userId'           => $session->userId,
            ':accountId'        => $this->auth->accountId(),
        ]);

        if (empty($this->state->USER)) {
            // logged in, but no player chosen yet, or the player no longer belongs to this account (account swap)
            $this->http()->redirectTo('account');
        } else {
            if ($this->state->USER['banaday'] <= $this->state->runtime->timestamp) {
                // only write when a ban actually ran out (otherwise bana/banaday are 0 already)
                if ((int) $this->state->USER['bana'] !== 0 || (int) $this->state->USER['banaday'] !== 0) {
                    $sql = "UPDATE %%USERS%% SET bana = 0, banaday = 0 WHERE id = :userId";
                    $db->update($sql, [
                        ':userId'   => $session->userId,
                    ]);
                }
                $this->state->USER['bana'] = 0;
                $this->state->USER['banaday'] = 0;
            }

            $this->state->USER['signalColors'] = $this->playerLookup()->player_signal_colors($this->state->USER);
            $this->state->USER['colors'] = $this->playerLookup()->player_colors($this->state->USER);
        }
    }

    /**
     * Ends the request with the "account banned" error page when the player is banned (reason from USERS_BANNED).
     */
    private function gateBannedUser(Language $LNG, $config): void
    {
        $db     = $this->db;

        $banUntil = (int) $this->state->USER['banaday'];
        $reason = null;
        $banned = $this->state->USER['bana'] == 1;
        if ($banned) {
            $sql = 'SELECT theme FROM %%USERS_BANNED%% WHERE user_id = :user_id';
            $reason = $db->selectSingle($sql, [
                ':user_id' => $this->state->USER['id'],
            ], 'theme');
        } else {
            // an account ban applies to all players of the account (a ban that ran out no longer counts)
            // the row of the request's user refresh is current here; only a ban needs the reason
            $accountRow = $this->auth->loadedAccountRow();
            if ($accountRow !== null && (int) $accountRow['bana'] === 1 && (int) $accountRow['banaday'] > $this->state->runtime->timestamp) {
                $banned = true;
                $banUntil = (int) $accountRow['banaday'];
                $reason = $db->selectSingle('SELECT `theme` FROM %%ACCOUNTS_BANNED%% WHERE `account_id` = :accountId;', [':accountId' => $accountRow['id']], 'theme');
                if ($reason === false) {
                    $reason = null;
                }
            } elseif ($accountRow === null) {
                $account = $db->selectSingle(
                    'SELECT a.`banaday`, b.`theme` FROM %%ACCOUNTS%% a LEFT JOIN %%ACCOUNTS_BANNED%% b ON b.`account_id` = a.`id` WHERE a.`id` = :accountId AND a.`bana` = 1 AND a.`banaday` > :now;',
                    [':accountId' => $this->auth->accountId(), ':now' => $this->state->runtime->timestamp],
                );
                if (!empty($account)) {
                    $banned = true;
                    $banUntil = (int) $account['banaday'];
                    $reason = $account['theme'];
                }
            }
        }

        if ($banned) {
            $this->completeLeanData();
            (new ShowErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError("<font size=\"6px\">" . $LNG['css_account_banned_message'] . "</font><br><br>"
            . $this->bannedExpireText($LNG, $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banUntil, $this->state->USER['timezone']), $config->forum_url) . "<br><br>"
            . $LNG->format('css_account_banned_reason', $reason) . "<br><br>"
            . $LNG['css_goto_homeside'], false);
        }
    }

    /**
     * Mode specific context: INGAME redirects to the player's universe, selects the active planet and loads it with a
     * row lock (falling back to the main planet), plus the player's planet list; ADMIN raises the error reporting,
     * decodes the admin rights and loads the admin texts.
     */
    private function loadModeContext(\App\Game\Core\Session $session, Language $LNG): void
    {
        $db     = $this->db;

        if ($this->state->runtime->mode === 'INGAME') {
            $universeAmount = count($this->universe()->availableUniverses());
            if ($this->universe()->current() != $this->state->USER['universe'] && $universeAmount > 1) {
                $this->http()->redirectToUniverse($this->state->USER['universe']);
            }

            if (in_array(LeanBoot::PLANET, $this->state->lean, true)) {
                // lean poll: no planet lock, no planet list
                $this->state->PLANET = [];

                return;
            }

            $session->selectActivePlanet();

            $sql    = "SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;";
            $this->state->PLANET = $db->selectSingle($sql, [
                ':planetId' => $session->planetId,
            ]);

            if (empty($this->state->PLANET) || !empty($this->state->PLANET['destruyed'])) {
                $sql    = "SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;";
                $this->state->PLANET = $db->selectSingle($sql, [
                    ':planetId' => $this->state->USER['id_planet'],
                ]);

                if (empty($this->state->PLANET)) {
                    throw new Exception("Main Planet does not exist!");
                }

                $session->planetId = $this->state->USER['id_planet'];
            }

            $this->state->USER['PLANETS']    = $this->planetQueries()->getPlanets($this->state->USER);
        } elseif ($this->state->runtime->mode === 'ADMIN') {
            error_reporting(E_ERROR | E_WARNING | E_PARSE);

            $this->state->USER['rights']     = StoredData::tryDecode($this->state->USER['rights']);
            $LNG->includeData(['ADMIN', 'CUSTOM']);
        }
    }

    /**
     * Shows the "closed" or "registration only" error page (ends the request) to normal players; admins, logout and
     * battle report links pass.
     */
    private function gateUniverseStatus(Language $LNG, $config, bool $raportWithoutSession): void
    {
        if ($config->uni_status != STATUS_OPEN) {
            $this->completeLeanData();
        }
        if (
            ($config->uni_status == STATUS_CLOSED) && $this->state->USER['authlevel'] == AuthLevel::User->value
            && $this->state->request?->attributes->get('_page') !== 'logout' && !$raportWithoutSession
        ) {
            (new ShowErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($LNG['sys_closed_game'] . '<br><br>' . $config->close_reason, false);
        }
        if (
            ($config->uni_status == STATUS_REG_ONLY) && $this->state->USER['authlevel'] == AuthLevel::User->value
            && $this->state->request?->attributes->get('_page') !== 'logout' && !$raportWithoutSession
        ) {
            (new ShowErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($LNG['sys_game_is_setup'] . '<br><br>' . $config->prepare_text, false);
        }
    }

    /**
     * Writes one USERS_ANALYTICS row (user, time, requested URI) for players with analytics enabled.
     */
    private function logAnalytics(): void
    {
        $db     = $this->db;

        if (isset($this->state->USER['enable_analytics']) && $this->state->USER['enable_analytics'] == 1) {
            $sql = 'INSERT INTO %%USERS_ANALYTICS%% (`userID`, `onlinetime`, `url`)
                VALUES (:userID, :onlinetime, :requestedUri);';
            $db->insert($sql, [
                ':userID'       => $this->state->USER['id'],
                ':onlinetime'   => $this->state->runtime->timestamp,
                ':requestedUri' => $this->state->requestServer['REQUEST_URI'],
            ]);
        }
    }

    /**
     * Boot of ACCOUNT requests: loads the account row (missing row: logout and redirect to the login), language, theme,
     * colours and the ban gate (error page for banned accounts). Also syncs the game session.
     *
     * @return Language the account's language (also stored in `state->LNG`)
     */
    private function bootAccount(Theme $THEME): Language
    {
        $sql    = "SELECT * FROM %%ACCOUNTS%% WHERE id = :id;";
        $db     = $this->db;
        $session    = $this->sessions()->load();
        $this->syncGameSession($session);
        $this->state->ACCOUNT   = $db->selectSingle($sql, [
            ':id'           => $this->auth->accountId(),
        ]);
        if (empty($this->state->ACCOUNT)) {
            $this->auth->logout();
            $this->http()->redirectTo('./?code=3');
        }
        $LNG    = new Language($this->configs, $this->state->ACCOUNT['lang']);
        $this->state->LNG = $LNG;
        $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        if (!empty($this->state->ACCOUNT['dpath'])) {
            $THEME->setUserTheme($this->state->ACCOUNT['dpath'], $this->state->ACCOUNT['css_dpath'] ?? null);//?
        }
        $this->state->ACCOUNT['signalColors'] = $this->playerLookup()->player_signal_colors();
        $this->state->ACCOUNT['colors'] = $this->playerLookup()->player_colors();
        if ($this->state->ACCOUNT['bana'] == 1) {
            $sql = 'SELECT theme FROM %%ACCOUNTS_BANNED%% WHERE id = :account_id';
            $reason = $db->selectSingle($sql, [
                ':account_id' => $this->state->ACCOUNT['id'],
            ], 'theme');

            (new ShowErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError("<font size=\"6px\">" . $LNG['css_account_banned_message'] . "</font><br><br>"
            . $this->bannedExpireText($LNG, $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->state->ACCOUNT['banaday'], $this->state->ACCOUNT['timezone']), $this->configs->get()->forum_url) . "<br><br>"
            . $LNG->format('css_account_banned_reason', $reason) . "<br><br>"
            . $LNG['css_goto_homeside'], false);
        }

        return $LNG;
    }

    /**
     * Visitor without login on the public board (/account/board): an account stub with id 0,
     * language from the start page cookie or the browser, server timezone, default theme; PUBLIC
     * texts for the start page frame (account/layout.site).
     */
    private function bootAccountGuest(): void
    {
        $cookieLang = $this->state->requestCookies['lang'] ?? null;
        $LNG = new Language($this->configs, is_string($cookieLang) && $cookieLang !== '' ? $cookieLang : null);
        $LNG->includeData(['L18N', 'INGAME', 'PUBLIC', 'TECH', 'CUSTOM']);
        $this->state->LNG = $LNG;
        $this->state->ACCOUNT = [
            'id' => 0,
            'guest' => true,
            'accountname' => '',
            'authlevel' => AuthLevel::User->value,
            'lang' => $LNG->getLanguage(),
            'timezone' => $this->configs->get()->timezone,
            'db_deaktjava' => 0,
            'bana' => 0,
            'signalColors' => $this->playerLookup()->player_signal_colors(),
            'colors' => $this->playerLookup()->player_colors(),
        ];
    }

    /**
     * The game session object follows the Symfony login: a fresh login (or another account) starts a
     * new game session (no player chosen, no admin access); an idle session past the setting session_lifetime
     * ends the login (with a "stay logged in" cookie only the session: the next request logs in again), and so does
     * a missing session row (revoked or expired). Visitors without login were already
     * stopped by access_control.
     */
    private function syncGameSession(\App\Game\Core\Session $session): void
    {
        $accountId = $this->auth->accountId();
        if ($accountId === 0) {
            $this->http()->redirectTo('./?code=3');
        }

        if ((int) ($session->accountId ?? 0) !== $accountId) {
            $session->accountId = $accountId;
            $session->userId = 0;
            $session->adminAccess = 0;
            $session->save();
        } elseif ((int) ($session->lastActivity ?? 0) < $this->state->runtime->timestamp - \App\Security\SessionPolicy::idleSeconds($this->configs->get(ROOT_UNI)->session_lifetime ?? null)) {
            if ($this->auth->restartRememberedLogin($session)) {
                // "stay logged in": drop the idle session, the next request logs in again with the remember-me cookie
                $this->http()->redirectTo((string) $this->state->request?->getRequestUri(), true);
            }
            $this->auth->logout();
            $this->http()->redirectTo('./?code=3');
        } elseif (!$session->isValidSession()) {
            // the session row is gone (password reset, expired lifetime, removed by an admin): the login is over
            $this->auth->logout();
            $this->http()->redirectTo('./?code=3');
        }
    }

    /**
     * Claims and runs the due fleet events of the current universe (FlyingFleetHandler::processDue()), also on
     * AJAX/fragment requests (a recall must never see an arrived fleet whose mission was not processed yet).
     * A MySQL deadlock (1213) while claiming is reported to Discord instead of failing the page.
     * (Formerly src/Game/Support/fleet_events.php.)
     */
    private function processFleetEvents(): void
    {
        try {
            $this->fleetHandler->processDue((int) $this->universe()->current());
        } catch (Exception $e) {
            // still deadlocked after all attempts: the next request claims the due events; only report it
            if (Database::errorNumber($e) !== Database::ERROR_DEADLOCK) {
                throw $e;
            }
            $ex = new Exception('Catched FleetHandler excepion:<br>' . $e->getMessage());
            $this->discord()->sendException($ex);
        }
    }

    /** module id => MODULE_* name map (game state "modules"), from the MODULE_* consts (game_constants.php) */
    private function registerModules(): void
    {
        $modules = [];
        foreach (get_defined_constants(true)['user'] as $key => $value) {
            if (str_starts_with($key, 'MODULE_')) {
                $modules[$value] = $key;
            }
        }
        $this->state->modules = $modules;
    }

    /**
     * The "you are banned until ..." text with the contact link (the configurable forum_url setting, %2$s of
     * css_account_banned_expire). Without a forum_url the contact sentence (everything after the first <br>
     * of the text) is left out, so no empty link is shown.
     */
    private function bannedExpireText(\App\Game\Core\Language $LNG, string $until, ?string $forumUrl): string
    {
        $forumUrl = trim((string) $forumUrl);
        $text = $LNG->formatHtml('css_account_banned_expire', $until, $forumUrl);

        return $forumUrl === '' ? explode('<br>', $text, 2)[0] : $text;
    }
}
