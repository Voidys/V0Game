<?php

namespace App\Runtime;

use App\Game\Core\Database;
use App\Game\Core\Language;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use App\Game\Core\Universe;
use Exception;

/**
 * The installer and database upgrade tool (formerly install/), unchanged in behaviour.
 * Served at /install/ by App\Controller\GameEntryController::install(), which bootstraps the
 * game in MODE INSTALL or UPGRADE first. Data files stay in install/ (install.sql, migrations/).
 */
final class Installer
{
    use \App\Game\Core\GameUtilities;

    /**
     * @param array<string, string|null> $defaults prefilled form values for unattended installs
     *                                              (DB_HOST, DB_USER, ..., ADMIN_MAIL from the environment)
     */
    public function __construct(
        private Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private Singleton $state,
        private InstallService $installer,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire([
            'DB_HOST' => '%env(default::DB_HOST)%',
            'DB_USER' => '%env(default::DB_USER)%',
            'DB_PASSWORD' => '%env(default::DB_PASSWORD)%',
            'DB_NAME' => '%env(default::DB_NAME)%',
            'ADMIN_NAME' => '%env(default::ADMIN_NAME)%',
            'ADMIN_PASSWORD' => '%env(default::ADMIN_PASSWORD)%',
            'ADMIN_MAIL' => '%env(default::ADMIN_MAIL)%',
        ])]
        private array $defaults = [],
    ) {}

    /** @var array<string, mixed> template variables collected by assign() */
    private array $viewData = [];

    /**
     * Entry of the installer/upgrader (`/install/`): loads the config (if any), sets up the installer language, guards
     * the install tool (tool file, 1 hour expiry), authorizes the upgrade mode with the migration password and
     * dispatches the mode (intro, install steps, upgrade page, doupgrade).
     */
    public function run(): void
    {
        $mode = $this->input()->string('mode');
        $THEME = $this->state->THEME;
        $configAvailable = file_exists('config/game.php') && filesize('config/game.php') !== 0;
        if ($configAvailable) {
            require 'config/game.php';
            if (($mode === 'upgrade' || $mode === 'doupgrade') && !$this->maintenance()->checkDBUpgrade()) {
                $this->http()->redirectTo('../game', true);
            }
        }
        $LNG = $this->setupLanguage();

        $this->assign([
            'lang'       => $LNG->getLanguage(),
            'Selector'   => $LNG->getAllowedLangs(false),
            'title'      => $LNG['title_install'] . ' · V0Game',
            'header'     => $LNG['menu_install'],
            'canUpgrade' => $configAvailable,
        ]);

        $enableInstallToolFile = $this->guardInstallTool($mode, $configAvailable);

        $language = $this->input()->string('lang');
        if (!empty($language) && in_array($language, $LNG->getAllowedLangs())) {
            $this->http()->sendCookie('lang', $language);
        }

        if ($mode === 'upgrade' || $mode === 'doupgrade') {
            $session = $this->authorizeUpgrade($database, $enableInstallToolFile);
        }

        switch ($mode) {
            case 'upgrade':
                $this->showUpgrade($LNG);
                break;
            case 'doupgrade':
                $this->doUpgrade($LNG, $session, $enableInstallToolFile);
                break;
            case 'install':
                $this->install($LNG, $enableInstallToolFile);
                break;
            default:
                $this->assign(['currentStep' => 0]);
                $this->show('ins_intro');
                break;
        }
    }

    /**
     * Creates the installer language (`?lang=`, else the `lang` cookie, else English), uses the nova theme and loads
     * the installer texts into `state->LNG`.
     */
    private function setupLanguage(): Language
    {
        $THEME = $this->state->THEME;
        $THEME->setUserTheme('nova');
        // installer language: ?lang= (also remembered as cookie), the cookie, else English
        $LNG = new Language($this->configs, 'en');
        $language = (string) ($this->state->requestQuery['lang'] ?? $this->state->requestCookies['lang'] ?? 'en');
        if ($language !== 'en' && in_array($language, $LNG->getAllowedLangs(), true)) {
            $LNG->setLanguage($language);
        }
        $this->state->LNG = $LNG;
        $LNG->includeData(['L18N', 'INGAME', 'INSTALL', 'CUSTOM']);

        return $LNG;
    }

    /**
     * Install tool access: a fresh `var/FIRST_INSTALL` or a project without config opens the tool (touches
     * `var/ENABLE_INSTALL_TOOL`), a tool file older than one hour (without KEEP_FILE) is removed, and a missing tool
     * file shows the "tool disabled" page (ends the request) outside the upgrade modes.
     *
     * @return string path of the tool file
     */
    private function guardInstallTool(string $mode, bool $configAvailable): string
    {
        $enableInstallToolFile = 'var/ENABLE_INSTALL_TOOL';
        $quickStartFile        = 'var/FIRST_INSTALL';
        // If include/FIRST_INSTALL is present and can be deleted, automatically create include/ENABLE_INSTALL_TOOL
        if (is_file($quickStartFile) && is_writeable($quickStartFile) && unlink($quickStartFile)) {
            SafeFile::touch($enableInstallToolFile);
        }
        // fresh project (no config/game.php yet): the installer opens by itself; the tool file keeps it open
        // for the steps after the database step, until the administrator is created (or for an hour)
        if (!$configAvailable && $mode !== 'upgrade' && $mode !== 'doupgrade') {
            SafeFile::touch($enableInstallToolFile);
        }
        // Only allow Install Tool access if the file "include/ENABLE_INSTALL_TOOL" is found
        if (is_file($enableInstallToolFile) && (time() - filemtime($enableInstallToolFile) > 3600)) {
            $content      = file_get_contents($enableInstallToolFile);
            $verifyString = 'KEEP_FILE';
            if (trim($content) !== $verifyString) {
                // Delete the file if it is older than 3600s (1 hour)
                if (is_file($enableInstallToolFile)) {
                    unlink($enableInstallToolFile);
                }
            }
        }
        if (!is_file($enableInstallToolFile) && !($mode === 'upgrade' || $mode === 'doupgrade')) {
            $this->show('ins_install');
        }

        return $enableInstallToolFile;
    }

    /**
     * Upgrade modes only: requires the migration password from the config (else removes the tool file and ends with an
     * error), grants the session migration access on the correct posted password and shows the password form
     * otherwise.
     *
     * @param array<mixed> $database the `$database` array of config/game.php
     * @return \App\Game\Core\Session the session with migration access
     */
    private function authorizeUpgrade(array $database, string $enableInstallToolFile): \App\Game\Core\Session
    {
        if (!array_key_exists('migrationpw', $database) || empty($database['migrationpw'])) {
            if (is_file($enableInstallToolFile)) {
                unlink($enableInstallToolFile);
            }
            throw \App\Game\Core\EndOfRequest::withResponse(
                $this->gameView()->messagePage('Migration password is not set. Please set migration password in config.php and try again.', false, 3, true),
            );
        }
        $migrationpw = $this->input()->string('migrationpw');
        if (!empty($migrationpw) && $migrationpw === $database['migrationpw']) {
            $session = $this->sessions()->create();
            $session->migrationAccess = 1;
            $session->save();
        }

        $session = $this->sessions()->load();
        if ($session->migrationAccess != 1) {
            $this->show('ins_migration');
        }

        return $session;
    }

    /**
     * Upgrade page: lists the pending migrations (SQL shown with prefix and database name filled in) with the file
     * revision and the current database version.
     */
    private function showUpgrade(Language $LNG): void
    {
        try {
            $sql    = "SELECT max(dbVersion) as dbVersion FROM %%SYSTEM%%;";
            $dbVersion  = $this->db->selectSingle($sql, [], 'dbVersion');
        } catch (Exception $e) {
            $dbVersion  = 0;
        }

        $updates = [];
        $fileRevision = 0;
        $migrationFileList = $this->maintenance()->listMigrations();
        foreach ($migrationFileList as $fileRevision => $fileInfo) {
            $updates[$fileInfo['pathName']] = makebr(
                str_replace(
                    '%PREFIX%',
                    $this->db->getTablePrefix(),
                    str_replace(
                        '%DB_NAME%',
                        $this->db->getDatabaseName(),
                        $fileContents = file_get_contents($fileInfo['pathName']),
                    ),
                ),
            );
        }

        $revision = end($migrationFileList);

        $this->assign([
            'file_revision' => $revision['fileRevision'],
            'sql_revision'  => $dbVersion,
            'updates'       => $updates,
            'header'        => $LNG['menu_upgrade'],
        ]);

        $this->show('ins_update');
    }

    /**
     * Runs the upgrade: checks that the game tables exist, applies every migration (PHP or SQL), records its version,
     * clears the caches, removes the tool file, ends the session and shows the result page.
     */
    private function doUpgrade(Language $LNG, \App\Game\Core\Session $session, string $enableInstallToolFile): void
    {
        // TODO:Need a rewrite!
        // Create a Backup
        $sqlTableRaw  = $this->db->nativeQuery("SHOW TABLE STATUS FROM `" . $this->db->getDatabaseName() . "`;");
        $prefixCounts = strlen($this->db->getTablePrefix());
        $dbTables     = [];
        foreach ($sqlTableRaw as $table) {
            if ($this->db->getTablePrefix() == substr($table['Name'], 0, $prefixCounts)) {
                $dbTables[] = $table['Name'];
            }
        }

        if (empty($dbTables)) {
            throw new Exception('No tables found for dump.');
        }

        $results = $this->migrations()->run();
        foreach ($results as $result) {
            if (!$result['ok']) {
                if (is_file($enableInstallToolFile)) {
                    unlink($enableInstallToolFile);
                }
                $session->delete();
                throw new Exception(
                    sprintf(
                        'Update error.<br><br>Error on update %s:<br><pre>%s</pre><br><br>'
                        . '<b><i>You should restore with a backup.</i></b>',
                        htmlspecialchars($result['file']),
                        htmlspecialchars($result['message']),
                    ),
                );
            }
        }
        $fileList = $results;
        $revision = end($fileList);

        $this->assign([
            'update'   => !empty($fileList),
            'revision' => $revision['revision'] ?? 0,
            'header'   => $LNG['menu_upgrade'],
        ]);
        if (is_file($enableInstallToolFile)) {
            unlink($enableInstallToolFile);
        }
        $session->delete();
        $this->show('ins_doupdate');
    }

    /** @param array<string, mixed> $variables */
    private function assign(array $variables): void
    {
        $this->viewData = array_replace($this->viewData, $variables);
    }

    /** renders templates/install/<name>.html.twig and ends the request with it */
    private function show(string $name): never
    {
        $context = $this->viewData + [
            'step' => $this->state->requestQuery['step'] ?? null,
            'scripts' => [],
            'execscript' => '',
        ];

        throw \App\Game\Core\EndOfRequest::withResponse(
            $this->gameView()->response('install/' . $name . '.html.twig', $context),
        );
    }

    /**
     * Fresh install, one step per request (the steps themselves are App\Runtime\InstallService, shared with
     * `bin/console app:install`). Five stages in the progress bar:
     * 1 requirements + licence, 2 database (form, connect + create + write the settings; 3 = build the tables, still
     * stage 2), 4 game + administrator (stage 3), 5 options: chat, bots, e-mail, legal (stage 4); the options form
     * ends with the summary page (stage 5) and locks the tool.
     */
    private function install(Language $LNG, string $enableInstallToolFile): void
    {
        $step = (int) $this->input()->int('step');
        // the build and setup steps never run against a game that already has an administrator; the options step
        // only for the browser session that just created it
        if (($step === 3 || $step === 4) && $this->installer->hasAdmin()) {
            $this->http()->redirectTo('./');
        }
        if ($step === 5 && !($this->installer->hasAdmin() && $this->sessions()->load()->adminAccess == 1)) {
            $this->http()->redirectTo('./');
        }
        $this->assign(['currentStep' => self::STAGE[$step] ?? 0]);
        match ($step) {
            1 => $this->stepCheck($LNG),
            2 => $this->stepDatabase($LNG),
            3 => $this->stepBuild($LNG),
            4 => $this->stepSetup($LNG),
            5 => $this->stepOptions($LNG),
            default => $this->http()->redirectTo('./'),
        };
    }

    /** progress stage (1-5) of every install step */
    private const STAGE = [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 4];

    /**
     * Step 1: requirements (a one-line "all good" with the list folded away, the full list when something fails) and
     * the licence; accepting continues with the database.
     */
    private function stepCheck(Language $LNG): void
    {
        $requirements = $this->installer->requirements($LNG->getLanguage());
        $accept = true;
        if (isset($this->state->requestPost['post']) && $requirements['passed']) {
            if (isset($this->state->requestPost['accept'])) {
                $this->http()->redirectTo('./?mode=install&step=2');
            }
            $accept = false;
        }
        $this->assign($requirements + [
            'accept' => $accept,
            // the project's licence file (GNU AGPL v3), shown as is
            'licenceText' => (string) SafeFile::read($this->state->runtime->rootPath . 'LICENSE'),
        ]);
        $this->show('ins_check');
    }

    /**
     * Step 2: database form (prefilled from the unattended-install defaults). Posting it connects, creates the
     * database and writes config/game.php and .env.local (see InstallService::prepareDatabase()); the result is
     * shown above the form and next to the failing field, success continues with the build.
     */
    private function stepDatabase(Language $LNG): void
    {
        $post = $this->state->requestPost;
        $posted = isset($post['post']);
        $form = [
            'host' => $posted ? trim((string) $this->input()->string('host')) : (($this->defaults['DB_HOST'] ?? null) ?: 'localhost'),
            'port' => $posted ? (string) (int) $this->input()->int('port', 3306) : '3306',
            'user' => $posted ? (string) $this->input()->string('user', '', true) : ($this->defaults['DB_USER'] ?? ''),
            'dbname' => $posted ? trim((string) $this->input()->string('dbname', '', true)) : ($this->defaults['DB_NAME'] ?? ''),
            'prefix' => $posted ? trim((string) $this->input()->typed('prefix', 'uni1_')) : 'uni1_',
            'createdb' => $posted ? (!empty($post['createdb']) ? '1' : '') : '1',
            'migrationpw' => $posted ? (string) $this->input()->string('migrationpw', '', true) : '',
        ];
        $checks = [];
        $fieldErrors = [];
        if ($posted) {
            // the address the installer is opened at becomes DEFAULT_URI (links in mails are built from it)
            $request = $this->state->request ?? null;
            $baseUrl = $request instanceof \Symfony\Component\HttpFoundation\Request ? rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/') : '';
            $result = $this->installer->prepareDatabase($form, (string) ($post['password'] ?? ''), $LNG->getLanguage(), $baseUrl);
            if ($result['passed']) {
                $this->http()->redirectTo('./?mode=install&step=3&connected=1');
            }
            $checks = $result['checks'];
            foreach ($checks as $check) {
                if (!$check['ok']) {
                    $fieldErrors[$check['field'] ?: 'general'] = $check['detail'];
                }
            }
        }
        $this->assign([
            'form' => $form + ['password' => $posted ? '' : ($this->defaults['DB_PASSWORD'] ?? '')],
            'checks' => $checks,
            'fieldErrors' => $fieldErrors,
            'retype' => $posted,
        ]);
        $this->show('ins_database');
    }

    /**
     * The progress page runs the tasks one request each (`&task=<name>`, JSON answer) and ticks them off;
     * `&task=all` (no JavaScript) runs everything and continues. A failed task removes config/game.php so
     * the database step can be repeated.
     */
    private function stepBuild(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $tasks = $this->installer->buildTasks($locale);
        $task = (string) $this->input()->string('task');
        if ($task === '') {
            $this->assign(['tasks' => $tasks, 'connected' => !empty($this->state->requestQuery['connected'])]);
            $this->show('ins_build');
        }

        $run = $task === 'all' ? array_keys($tasks) : [$task];
        try {
            foreach ($run as $name) {
                if (!isset($tasks[$name])) {
                    throw new Exception('Unknown task: ' . $name);
                }
                $this->installer->runBuildTask($name, $locale);
            }
        } catch (\Throwable $e) {
            error_log('installer task ' . $task . ': ' . $e->getMessage());
            SafeFile::remove($this->state->runtime->rootPath . 'config/game.php');
            if ($task === 'all') {
                $this->assign(['tasks' => $tasks, 'error' => $LNG['step3_db_error'] . ' ' . $e->getMessage()]);
                $this->show('ins_build');
            }
            $this->sendJson(['ok' => false, 'message' => $LNG['step3_db_error'] . ' ' . $e->getMessage()]);
        }

        if ($task === 'all') {
            $this->http()->redirectTo('./?mode=install&step=4');
        }
        $this->sendJson(['ok' => true]);
    }

    /**
     * JSON answer of an AJAX build task; ends the request.
     *
     * @param array<string, mixed> $data
     */
    private function sendJson(array $data): never
    {
        $this->http()->sendHeader('Content-Type', 'application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        throw new \App\Game\Core\EndOfRequest();
    }

    /**
     * Step 4: game name, universe name, language and the administrator; speed, registration, time zone, theme and the
     * rest sit in a folded "more settings" block. Posting validates everything (errors next to the fields), writes the
     * game settings, creates the administrator, logs it in (Symfony login plus a game session with admin access) and
     * continues with the options. The tool stays open until the options step is done.
     */
    private function stepSetup(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $post = $this->state->requestPost;
        $posted = isset($post['post']);
        $values = [
            'game_name' => $post['game_name'] ?? 'V0Game',
            'default_theme' => $post['default_theme'] ?? DEFAULT_THEME,
            'uni_name' => $post['uni_name'] ?? '',
            'language' => $post['language'] ?? $locale,
            'timezone' => $post['timezone'] ?? $this->installer->defaultTimezone(),
            'speed' => $post['speed'] ?? 'classic',
            'registration' => $post['registration'] ?? 'open',
            'mail' => false,
            'bots' => 0,
            'update_check' => $posted ? !empty($post['update_check']) : true,
            'discord_url' => $this->input()->string('discord_url'),
        ];
        $admin = [
            'username' => $posted ? (string) $this->input()->string('username', '', UTF8_SUPPORT) : ($this->defaults['ADMIN_NAME'] ?? ''),
            'email' => $posted ? (string) $this->input()->string('email') : ($this->defaults['ADMIN_MAIL'] ?? ''),
        ];

        $fieldErrors = [];
        if ($posted) {
            $checked = $this->installer->validateGameSettings($values, $locale);
            $values = $checked['values'];
            if ($checked['error'] !== null) {
                $fieldErrors[$checked['field']] = $checked['error'];
            }
            $password = (string) $this->input()->rawString('password');
            $fieldErrors += $this->installer->validateAdmin($admin['username'], $password, (string) $this->input()->rawString('password_confirm'), $admin['email'], $locale);
            if ($fieldErrors === []) {
                $this->createGameAndAdmin($values, $admin, $password);
                $this->http()->redirectTo('./?mode=install&step=5&lang=' . $values['language']);
            }
        }

        $this->assign([
            'values' => $values,
            'admin' => $admin + ['password' => $posted ? '' : ($this->defaults['ADMIN_PASSWORD'] ?? '')],
            'fieldErrors' => $fieldErrors,
            'minLength' => InstallService::MIN_PASSWORD_LENGTH,
            'themes' => Theme::getAvailableCssThemes(),
            'languages' => $this->installer->allowedLanguages(),
            'timezones' => $this->installer->timezones(),
            'speeds' => array_keys($this->installer->speedPresets()),
            'presets' => $this->installer->speedPresets(),
            'moreOpen' => array_intersect_key($fieldErrors, array_flip(['default_theme', 'timezone', 'speed', 'registration', 'discord_url'])) !== [],
        ]);
        $this->show('ins_setup');
    }

    /**
     * Writes the validated game settings, creates the administrator in the new game and logs it in.
     *
     * @param array<string, mixed> $values validated game settings
     * @param array{username: string, email: string} $admin
     */
    private function createGameAndAdmin(array $values, array $admin, string $password): void
    {
        $this->installer->applyGameSettings($values);
        [$accountId, $userId] = $this->installer->createAdmin($admin['username'], $password, $admin['email'], $values['language']);

        $this->state->auth->login($accountId);
        $session = $this->sessions()->create();
        $session->accountId = $accountId;
        $session->userId = $userId;
        $session->universe = $this->universe()->current();
        $session->adminAccess = 1;
        $session->save();
    }

    /**
     * Step 5: optional settings in one form: chat (module, global channel, link blocking), bots, the optional engagement
     * features (all off by default), e-mail (folded; with a
     * test mail button that re-shows the page) and legal/privacy (folded). Saving applies everything, spawns the bots,
     * locks the tool and shows the summary with the cron line and the generated migration password.
     */
    private function stepOptions(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $post = $this->state->requestPost;
        $posted = isset($post['post']);
        $flag = static fn(string $key, bool $default): bool => $posted ? !empty($post[$key]) : $default;
        $options = [
            'chat' => $flag('chat', InstallService::CHAT_DEFAULTS['chat']),
            'chat_global' => $flag('chat_global', InstallService::CHAT_DEFAULTS['chat_global']),
            'chat_block_links' => $flag('chat_block_links', InstallService::CHAT_DEFAULTS['chat_block_links']),
            'bots' => (int) ($post['bots'] ?? 0),
            'features' => array_map(static fn(string $key): bool => false, InstallService::ENGAGEMENT_FEATURES),
        ];
        foreach (array_keys($options['features']) as $code) {
            $options['features'][$code] = $flag('feature_' . $code, false);
        }
        $mail = $this->installer->validateMailSettings([
            'active' => $flag('active', false),
            'method' => $post['method'] ?? 2,
            'from' => $post['from'] ?? '',
            'host' => $post['host'] ?? '',
            'port' => $post['port'] ?? '587',
            'encryption' => $post['encryption'] ?? 'tls',
            'user' => $post['user'] ?? '',
            'password' => $post['password'] ?? '',
            'user_valid' => !empty($post['user_valid']),
        ], $locale);
        $legal = $this->installer->validateLegalSettings([
            'privacy_region' => $post['privacy_region'] ?? 'eu',
            'rules_default' => $post['rules_default'] ?? 'default',
            'cookie_consent' => !empty($post['cookie_consent']),
            // stored HTML-encoded like the admin form does; the administrator's address (set up in step 4) is the default
            'disclamer_mail' => $this->input()->string('disclamer_mail', $posted ? '' : (string) $this->db->selectSingle('SELECT `value` FROM %%CONFIG%% WHERE `key` = \'disclamer_mail\'', [], 'value')),
            'disclamer_address' => $this->input()->string('disclamer_address', '', true),
        ], $locale);
        $testTo = trim((string) ($post['test_to'] ?? ''));
        $errors = [];
        $testOk = null;

        if (isset($post['test'])) {
            $result = $this->testMail($mail['values'], $testTo, $locale);
            $errors['mail'] = $result['error'] ?? null;
            $testOk = $result['testOk'] ?? null;
        } elseif ($posted) {
            $errors = array_filter([
                'bots' => $options['bots'] < 0 || $options['bots'] > 50 ? $LNG['ins_bots_invalid'] : null,
                'mail' => $mail['error'],
                'legal' => $legal['error'],
            ]);
            if ($errors === []) {
                $this->finishInstall($options, $mail['values'], $legal['values']);
            }
        }

        $mail['values']['password'] = '';
        $this->assign([
            'options' => $options,
            'mail' => $mail['values'],
            'legal' => $legal['values'],
            'testTo' => $testTo,
            'testOk' => $testOk,
            'errors' => array_filter($errors),
        ]);
        $this->show('ins_options');
    }

    /**
     * Applies the options, spawns the bots (a failure only sets a flag), locks the installer and shows the summary.
     *
     * @param array{chat: bool, chat_global: bool, chat_block_links: bool, bots: int, features: array<string, bool>} $options
     * @param array<string, mixed> $mail validated mail values
     * @param array<string, mixed> $legal validated legal values
     */
    private function finishInstall(array $options, array $mail, array $legal): never
    {
        // with the chat off the filter keys keep their defaults (the member switches are disabled and not posted)
        $chat = $options['chat'];
        $this->installer->applyChatSettings($chat, !$chat || $options['chat_global'], !$chat || $options['chat_block_links']);
        $this->installer->applyMailSettings($mail);
        $this->installer->applyLegalSettings($legal);
        $this->installer->applyBotsEnabled($options['bots']);
        $this->installer->applyEngagementSettings($options['features']);

        $botsCreated = 0;
        $botError = false;
        try {
            $botsCreated = $this->installer->spawnBots($options['bots']);
        } catch (\Throwable $e) {
            error_log('installer bots: ' . $e->getMessage());
            $botError = true;
        }

        $this->installer->lock();
        $this->assign([
            'currentStep' => 5,
            'gameName' => (string) $this->db->selectSingle('SELECT `value` FROM %%CONFIG%% WHERE `key` = \'game_name\'', [], 'value'),
            'adminName' => (string) $this->db->selectSingle('SELECT `username` FROM %%USERS%% WHERE `authlevel` = ' . \App\Game\Enum\AuthLevel::Admin->value . ' LIMIT 1', [], 'username'),
            'adminUrl' => $this->state->runtime->protocol . $this->state->runtime->httpHost . preg_replace('#install/?$#', '', $this->state->runtime->httpRoot) . 'admin',
            'cronLine' => $this->installer->cronLine(),
            'migrationPw' => $this->installer->takeGeneratedSecret(),
            'options' => $options,
            'mailOn' => !empty($mail['active']),
            'botsCreated' => $botsCreated,
            'botError' => $botError,
            'docsUrl' => InstallService::DOCS_URL,
            'cronDocsUrl' => InstallService::CRON_DOCS_URL,
            'https' => $this->state->runtime->https,
        ]);
        $this->show('ins_done');
    }

    /**
     * Test mail of the options step: checks the form values and the target address, then sends.
     *
     * @param array<string, mixed> $values validated mail values
     * @return array<string, string> template variables `error` or `testOk`
     */
    private function testMail(array $values, string $to, string $locale): array
    {
        $LNG = $this->installer->lng($locale);
        $invalid = $this->installer->validateMailSettings(['active' => true] + $values, $locale)['error'];
        if ($invalid !== null) {
            return ['error' => $invalid];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['error' => $LNG['ins_mail_test_invalid']];
        }
        $error = $this->installer->sendTestMail($values, $to, $locale);
        if ($error !== null) {
            return ['error' => str_replace('%s', $error, $LNG['ins_mail_test_fail'])];
        }

        return ['testOk' => str_replace('%s', $to, $LNG['ins_mail_test_ok'])];
    }
}
