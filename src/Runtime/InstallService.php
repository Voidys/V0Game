<?php

declare(strict_types=1);

namespace App\Runtime;

use App\Game\Enum\AuthLevel;
use App\Game\Bots\BotSpawner;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameData;
use App\Game\Core\GameUtilities;
use App\Game\Core\Language;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use Exception;
use PDO;
use PDOException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The steps of a fresh installation, independent of the request: used by the web wizard (App\Runtime\Installer)
 * and by `bin/console app:install` (App\Command\InstallCommand).
 *
 * Order: requirements -> prepareDatabase() (connect, create the database, write config/game.php and .env.local)
 * -> runBuildTask() for every buildTasks() entry -> applyGameSettings() -> createAdmin() -> applyChatSettings() ->
 * spawnBots() -> lock().
 * Everything after prepareDatabase() needs a process that started after .env.local was written (the game's
 * connection reads DATABASE_URL at start-up).
 */
final class InstallService
{
    use GameUtilities;

    /** Speed presets: multipliers of the universe settings (stored x 2500 for the "int_multiply_2500" fields). */
    public const SPEED_PRESETS = [
        'classic' => ['building_speed' => 1, 'shipyard_speed' => 1, 'research_speed' => 1, 'fleet_speed' => 1, 'resource_multiplier' => 1, 'storage_multiplier' => 1],
        'fast' => ['building_speed' => 3, 'shipyard_speed' => 3, 'research_speed' => 3, 'fleet_speed' => 2, 'resource_multiplier' => 3, 'storage_multiplier' => 2],
        'blitz' => ['building_speed' => 10, 'shipyard_speed' => 10, 'research_speed' => 10, 'fleet_speed' => 5, 'resource_multiplier' => 10, 'storage_multiplier' => 5],
    ];

    public const MIN_PASSWORD_LENGTH = 8;
    private const README_URL = 'https://github.com/Voidys/V0Game';

    /** the install guide (Quick start) in the README */
    public const DOCS_URL = self::README_URL . '#quick-start';

    /** step-by-step cron setup (hosting panel and crontab) in the README */
    public const CRON_DOCS_URL = self::README_URL . '#cron-job';

    /** updating an existing install, in the README */
    public const UPDATE_DOCS_URL = self::README_URL . '#updating';

    /** file (in var/) that carries a generated migration password from the database step to the last page */
    private const SECRET_FILE = 'var/INSTALL_SECRET';

    /** @var array<string, Language> */
    private array $languages = [];

    private readonly string $root;

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
        #[Autowire(lazy: true)]
        private BotSpawner $botSpawner,
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->root = rtrim($projectDir, '/') . '/';
    }

    /** Project root with trailing slash. */
    public function root(): string
    {
        return $this->root;
    }

    /** True when config/game.php exists and is not empty (the installer's finish marker). */
    public function isInstalled(): bool
    {
        return is_file($this->root . 'config/game.php') && filesize($this->root . 'config/game.php') > 0;
    }

    /**
     * True when the database already holds an administrator (authlevel ADM): the game is installed, whatever the tool
     * file says. False while the tables do not exist yet.
     */
    public function hasAdmin(): bool
    {
        try {
            return (int) $this->db->selectSingle('SELECT COUNT(*) AS n FROM %%USERS%% WHERE `authlevel` = :level;', [':level' => AuthLevel::Admin->value], 'n') > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** translations of the installer (domain install, plus the shared ones) in the given language */
    public function lng(string $locale): Language
    {
        if (!isset($this->languages[$locale])) {
            // the language cache lives in var/game/, which a fresh checkout does not have yet
            foreach (['var/game/', 'var/sessions/', 'var/log/'] as $dir) {
                SafeFile::mkdir($this->root . $dir, 0777);
            }
            $LNG = new Language($this->configs, 'en');
            if ($locale !== 'en' && in_array($locale, $LNG->getAllowedLangs(), true)) {
                $LNG->setLanguage($locale);
            }
            $LNG->includeData(['L18N', 'INGAME', 'INSTALL', 'CUSTOM']);
            $this->languages[$locale] = $LNG;
        }

        return $this->languages[$locale];
    }

    /** makes the game state use this language (the console has no request that would set it) */
    public function useLanguage(string $locale): void
    {
        $this->state->LNG = $this->lng($locale);
    }

    /** @return array<string, string> language key => name */
    public function allowedLanguages(): array
    {
        return $this->lng('en')->getAllowedLangs(false);
    }

    /** @return list<string> */
    public function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /** The server's PHP timezone if it is a valid identifier, else UTC. */
    public function defaultTimezone(): string
    {
        $zone = date_default_timezone_get() ?: 'UTC';

        return in_array($zone, $this->timezones(), true) ? $zone : 'UTC';
    }

    /** @return array<string, array<string, int>> */
    public function speedPresets(): array
    {
        return self::SPEED_PRESETS;
    }

    /** @return array{checks: list<array{label: string, ok: bool, detail: string, required: bool}>, passed: bool} */
    public function requirements(string $locale): array
    {
        $LNG = $this->lng($locale);
        $checks = [];
        $add = static function (string $label, bool $ok, string $detail = '', bool $required = true) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'required' => $required];
        };

        $add('PHP ≥ 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), 'v' . PHP_VERSION);
        $add('PDO MySQL', class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true));
        $add('JSON', function_exists('json_encode'));
        $add('ini_set()', function_exists('ini_set'));
        $add('GD', extension_loaded('gd'), extension_loaded('gd') ? (gd_info()['GD Version'] ?? '') : '', false);

        clearstatcache();
        foreach (['config/game.php', '.env.local'] as $file) {
            $exists = file_exists($this->root . $file);
            $writable = $exists ? is_writable($this->root . $file) : is_writable(dirname($this->root . $file));
            $add($LNG->format('reg_file', $file), $writable, $writable ? $LNG['reg_writable'] : $LNG['reg_not_writable']);
        }
        foreach (['var/', 'var/game/', 'var/sessions/', 'var/backups/', 'var/log/', 'public/stats/', 'config/'] as $dir) {
            $exists = SafeFile::mkdir($this->root . $dir, 0777);
            $add(
                $LNG->format('reg_dir', $dir),
                $exists && is_writable($this->root . $dir),
                !$exists ? $LNG['reg_not_found'] : (is_writable($this->root . $dir) ? $LNG['reg_writable'] : $LNG['reg_not_writable']),
            );
        }

        return [
            'checks' => $checks,
            'passed' => array_filter($checks, static fn(array $check): bool => $check['required'] && !$check['ok']) === [],
        ];
    }

    /** a strong random migration password (24 hex characters) */
    public function generateMigrationPassword(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** Plain-words explanation of a failed database connection (access denied, unknown database, host unreachable). */
    public function explainConnectionError(PDOException $e, string $host, string $locale): string
    {
        $LNG = $this->lng($locale);
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        $message = $e->getMessage();
        $hint = match (true) {
            $code === 1045 || str_contains($message, 'Access denied') => $LNG['ins_err_access_denied'],
            $code === 1049 || str_contains($message, 'Unknown database') => $LNG['ins_err_unknown_db'],
            $code === 1044 => $LNG['ins_err_no_privilege'],
            $code === 2002 && str_contains($message, 'No such file') => $LNG['ins_err_socket'],
            in_array($code, [2002, 2003, 2005, 2006], true) || str_contains($message, 'getaddrinfo') || str_contains($message, 'Connection refused') || str_contains($message, 'timed out') => $LNG->format('ins_err_unreachable', $host),
            default => $LNG['step2_db_con_fail'],
        };

        return $hint . ' (' . $LNG['ins_err_technical'] . ': ' . $message . ')';
    }

    /**
     * Connects with the form values (not the configured connection), creates the database when asked, makes sure no
     * tables with the prefix exist yet, then writes config/game.php and .env.local (DATABASE_URL + TABLE_PREFIX).
     * The first failure stops the step; every check is returned as a list.
     *
     * @param array{host: string, port: string|int, user: string, dbname: string, prefix: string, createdb: bool|string, migrationpw?: string} $form
     * @return array{checks: list<array{label: string, ok: bool, detail: string}>, passed: bool, migrationpw: string, generated: bool}
     */
    public function prepareDatabase(array $form, string $password, string $locale, string $baseUrl = ''): array
    {
        $root = $this->root;
        $form['host'] = trim((string) $form['host']);
        $form['port'] = (string) (int) ($form['port'] ?: 3306);
        $form['dbname'] = trim((string) $form['dbname']);
        $form['prefix'] = trim((string) ($form['prefix'] === '' ? 'uni1_' : $form['prefix']));
        $form['createdb'] = !empty($form['createdb']);
        $migrationpw = (string) ($form['migrationpw'] ?? '');
        $generated = $migrationpw === '';
        if ($generated) {
            $migrationpw = $this->generateMigrationPassword();
        }

        $checks = [];
        $result = static function (bool $passed) use (&$checks, &$migrationpw, &$generated): array {
            return ['checks' => $checks, 'passed' => $passed, 'migrationpw' => $migrationpw, 'generated' => $generated];
        };
        $fail = static function (string $label, string $detail, string $field = '') use (&$checks, $result): array {
            $checks[] = ['label' => $label, 'ok' => false, 'detail' => $detail, 'field' => $field];

            return $result(false);
        };
        if ($failed = $this->checkDatabaseInput($form, $migrationpw, $locale, $checks, $fail)) {
            return $failed;
        }

        if ($failed = $this->checkServerConnection($form, $password, $locale, $checks, $fail, $pdo, $version)) {
            return $failed;
        }

        if ($failed = $this->checkDatabaseExists($pdo, $form, $locale, $checks, $fail)) {
            return $failed;
        }

        if ($failed = $this->checkNoGameTables($pdo, $form, $locale, $checks, $fail)) {
            return $failed;
        }

        if ($failed = $this->writeGameConfig($form, $password, $migrationpw, $locale, $checks, $fail)) {
            return $failed;
        }

        if ($failed = $this->writeEnvLocalFile($form, $password, $version, $baseUrl, $locale, $checks, $fail)) {
            return $failed;
        }

        SafeFile::touch($root . 'var/log/game.log');
        if ($generated) {
            // shown once on the last page, then removed
            SafeFile::write($root . self::SECRET_FILE, $migrationpw . "\n", 0600);
        }

        return $result(true);
    }

    /**
     * Install step "input": validates the form values (host, database name and prefix format, migration password,
     * not already installed). On success appends the OK check.
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function checkDatabaseInput(array $form, string $migrationpw, string $locale, array &$checks, \Closure $fail): ?array
    {
        $LNG = $this->lng($locale);

        [$field, $invalid] = match (true) {
            $form['host'] === '' => ['host', $LNG['ins_db_no_host']],
            $form['dbname'] === '' => ['dbname', $LNG['step2_db_no_dbname']],
            !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $form['dbname']) => ['dbname', $LNG['ins_db_name_invalid']],
            strlen($form['prefix']) > 36 => ['prefix', $LNG['step2_db_too_long']],
            !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $form['prefix']) => ['prefix', $LNG['step2_prefix_invalid']],
            preg_match('/[\r\n\'"\\\\$]/', $migrationpw) === 1 => ['migrationpw', $LNG['ins_migrationpw_invalid']],
            $this->isInstalled() => ['', $LNG['step2_config_exists']],
            default => ['', null],
        };
        if ($invalid !== null) {
            return $fail($LNG['ins_check_input'], $invalid, $field);
        }
        $checks[] = ['label' => $LNG['ins_check_input'], 'ok' => true, 'detail' => ''];

        return null;
    }

    /**
     * Install step "server": connects with the form values and reads the server version.
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param PDO|null $pdo set to the connection on success (out parameter)
     * @param string|null $version set to the server version on success (out parameter)
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function checkServerConnection(array $form, string $password, string $locale, array &$checks, \Closure $fail, ?PDO &$pdo = null, ?string &$version = null): ?array
    {
        $LNG = $this->lng($locale);

        try {
            $pdo = new PDO('mysql:host=' . $form['host'] . ';port=' . $form['port'] . ';charset=utf8mb4', $form['user'], $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException $e) {
            return $fail($LNG['ins_check_connect'], $this->explainConnectionError($e, $form['host'], $locale), 'user');
        }
        $checks[] = ['label' => $LNG['ins_check_connect'], 'ok' => true, 'detail' => $version];

        return null;
    }

    /**
     * Install step "database": the database must exist or is created when `createdb` is set.
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function checkDatabaseExists(PDO $pdo, array $form, string $locale, array &$checks, \Closure $fail): ?array
    {
        $LNG = $this->lng($locale);

        $label = $LNG->format('ins_check_database', $form['dbname']);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$form['dbname']]);
        if ((int) $exists->fetchColumn() === 0) {
            if (!$form['createdb']) {
                return $fail($label, $LNG['ins_db_missing'], 'dbname');
            }
            try {
                $pdo->exec('CREATE DATABASE `' . $form['dbname'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (PDOException $e) {
                return $fail($label, $LNG['ins_db_create_fail'] . ' ' . $this->explainCreateError($e, $locale), 'dbname');
            }
            $checks[] = ['label' => $label, 'ok' => true, 'detail' => $LNG['ins_db_created']];
        } else {
            $checks[] = ['label' => $label, 'ok' => true, 'detail' => $LNG['ins_db_exists']];
        }

        return null;
    }

    /**
     * Install step "free of game tables": no table with the prefix may exist in the database yet.
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function checkNoGameTables(PDO $pdo, array $form, string $locale, array &$checks, \Closure $fail): ?array
    {
        $LNG = $this->lng($locale);

        $label = $LNG->format('ins_check_tables', $form['prefix']);
        $tables = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?');
        $tables->execute([$form['dbname'], addcslashes($form['prefix'], '_%\\') . '%']);
        $tableCount = (int) $tables->fetchColumn();
        if ($tableCount > 0) {
            return $fail($label, $LNG->format('ins_tables_exist', $tableCount), 'prefix');
        }
        $checks[] = ['label' => $label, 'ok' => true, 'detail' => ''];

        return null;
    }

    /**
     * Install step "config/game.php": writes the config from the sample (database fallback, migration password, random
     * salt; mode 0640 because it holds secrets).
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function writeGameConfig(array $form, string $password, string $migrationpw, string $locale, array &$checks, \Closure $fail): ?array
    {
        $LNG = $this->lng($locale);
        $root = $this->root;

        $salt = substr(str_shuffle('./0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 22);
        $php = sprintf(
            (string) file_get_contents($root . 'config/game.sample.php'),
            addcslashes($form['host'], "'\\"),
            $form['port'],
            addcslashes($form['user'], "'\\"),
            addcslashes($password, "'\\"),
            $form['dbname'],
            $form['prefix'],
            addcslashes($migrationpw, "'\\"),
            $salt,
        );
        $label = $LNG->format('ins_check_write', 'config/game.php');
        if (!SafeFile::write($root . 'config/game.php', $php, 0640)) { // holds the database password and the salt
            return $fail($label, $LNG['step2_conf_op_fail']);
        }
        $checks[] = ['label' => $label, 'ok' => true, 'detail' => ''];

        return null;
    }

    /**
     * Install step ".env.local": writes DATABASE_URL (with the server version for Doctrine), TABLE_PREFIX, the
     * environment defaults and, when given, DEFAULT_URI. A failed write removes config/game.php again.
     *
     * @param list<array{label: string, ok: bool, detail: string}> $checks collected checks (appended to)
     * @param \Closure(string, string, string=): array $fail records a failed check and builds the failure result
     * @param array<string, mixed> $fail
     * @param array<string, mixed> $form
     * @return array<mixed>|null the failure result, null when the step passed
     */
    private function writeEnvLocalFile(array $form, string $password, string $version, string $baseUrl, string $locale, array &$checks, \Closure $fail): ?array
    {
        $LNG = $this->lng($locale);
        $root = $this->root;

        // .env.local: the game's connection (Doctrine) reads DATABASE_URL
        $serverVersion = str_contains(strtolower($version), 'mariadb')
            ? 'mariadb-' . preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version)
            : preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version);
        $url = sprintf(
            'mysql://%s:%s@%s:%s/%s?serverVersion=%s&charset=utf8mb4',
            rawurlencode($form['user']),
            rawurlencode($password),
            $form['host'],
            $form['port'],
            $form['dbname'],
            $serverVersion,
        );
        $env = ['DATABASE_URL' => $url, 'TABLE_PREFIX' => $form['prefix']] + $this->environmentDefaults();
        if ($baseUrl !== '') {
            $env['DEFAULT_URI'] = $baseUrl;
        }
        $label = $LNG->format('ins_check_write', '.env.local');
        if (!$this->writeEnvLocal($env)) {
            SafeFile::remove($root . 'config/game.php');

            return $fail($label, $LNG['reg_not_writable']);
        }
        $checks[] = ['label' => $label, 'ok' => true, 'detail' => ''];

        return null;
    }

    /**
     * Settings a fresh install needs besides the database: a random APP_SECRET (when .env.local has none) and production
     * mode (APP_ENV=prod, APP_DEBUG=0) unless .env.local already sets APP_ENV or the install runs inside DDEV.
     *
     * @return array<string, string>
     */
    private function environmentDefaults(): array
    {
        $file = $this->root . '.env.local';
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $values = [];
        if (preg_match('/^\s*APP_SECRET\s*=\s*["\']?[^"\'\s]+/m', $content) !== 1) {
            $values['APP_SECRET'] = bin2hex(random_bytes(32));
        }
        if (preg_match('/^\s*APP_ENV\s*=/m', $content) !== 1 && getenv('IS_DDEV_PROJECT') === false) {
            $values['APP_ENV'] = 'prod';
            $values['APP_DEBUG'] = '0';
        }

        return $values;
    }

    /** Hint for a failed CREATE DATABASE: missing privilege (MySQL 1044/1227) or the raw message. */
    private function explainCreateError(PDOException $e, string $locale): string
    {
        $LNG = $this->lng($locale);
        $code = (int) ($e->errorInfo[1] ?? 0);

        return ($code === 1044 || $code === 1227 || str_contains($e->getMessage(), 'denied'))
            ? $LNG['ins_err_no_create'] . ' (' . $e->getMessage() . ')'
            : $e->getMessage();
    }

    /**
     * sets the given keys in .env.local (other lines stay), values quoted for Symfony's Dotenv
     *
     * @param array<mixed> $values
     */
    public function writeEnvLocal(array $values): bool
    {
        $file = $this->root . '.env.local';
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : ['# written by the installer'];
        foreach ($values as $key => $value) {
            $line = $key . '="' . addcslashes((string) $value, '"\\$') . '"';
            $found = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*' . preg_quote((string) $key, '/') . '\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }
            if (!$found) {
                $lines[] = $line;
            }
        }

        // holds the database password and the app secret: not readable by others
        return SafeFile::write($file, implode("\n", $lines) . "\n", 0640);
    }

    /** @return array<string, string> task => label, run in this order */
    public function buildTasks(string $locale): array
    {
        $LNG = $this->lng($locale);

        return [
            'tables' => $LNG['ins_task_tables'],
            'migrations' => $LNG['ins_task_migrations'],
            'config' => $LNG['ins_task_config'],
            'universe' => $LNG['ins_task_universe'],
            'images' => $LNG['ins_task_images'],
        ];
    }

    /** Runs one build task; throws on failure (the caller removes config/game.php so the database step can repeat). */
    public function runBuildTask(string $task, string $locale): void
    {
        $LNG = $this->lng($locale);
        $db = $this->db;
        // the tasks fill or replace the config tables
        $this->configs->invalidateCache();
        switch ($task) {
            case 'tables':
                if ($db->selectSingle('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name', [':name' => $db->getTablePrefix() . 'config'], 'n') > 0) {
                    throw new Exception($LNG->format('ins_tables_exist', 1));
                }
                $db->query(str_replace('%PREFIX%', $db->getTablePrefix(), (string) file_get_contents($this->root . 'install/install.sql')));
                break;
            case 'migrations':
                foreach ($this->maintenance()->listMigrations() as $fileRevision => $fileInfo) {
                    $db->insert('INSERT INTO %%SYSTEM%% VALUES (:dbVersion);', [':dbVersion' => $fileRevision]);
                }
                break;
            case 'config':
                $installVersion = trim((string) file_get_contents($this->root . 'install/VERSION'));
                $schemaGlobal = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_BASIC, $this->state, $this->configs);
                foreach ($schemaGlobal->getAllFields() as $fieldName => $fieldConfig) {
                    $db->insert('INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value);', [
                        ':key' => $fieldName,
                        ':value' => $fieldName === 'version' ? $installVersion : ($fieldConfig['default'] ?? ''),
                    ]);
                }
                break;
            case 'universe':
                $schemaUni = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this->configs);
                foreach ($schemaUni->getAllFields() as $fieldName => $fieldConfig) {
                    $db->insert('INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (:uni, :key, :value);', [
                        ':uni' => 1,
                        ':key' => $fieldName,
                        ':value' => $fieldName === 'uni_name' ? $LNG['fcm_universe'] . ' 1' : ($fieldConfig['default'] ?? ''),
                    ]);
                }
                foreach ($this->state->modules as $moduleKey => $moduleName) {
                    $db->insert('INSERT INTO %%CONFIG_UNIVERSE_MODULES%% (`uni`, `module`, `module_name`, `state`) VALUES (:uni, :module, :module_name, :state)', [
                        ':uni' => 1,
                        ':module' => $moduleKey,
                        ':module_name' => $moduleName,
                        // the chat is opt-in (see applyChatSettings())
                        ':state' => $moduleKey === MODULE_CHAT ? 0 : 1,
                    ]);
                }
                break;
            case 'images':
                // WebP copies of the static images (same as bin/console app:images:webp); never fails the install
                set_time_limit(300);
                (new \App\Content\ImageProcessor())->webpTree($this->root . 'public');
                break;
            default:
                throw new Exception('Unknown task: ' . $task);
        }
    }

    /**
     * Validates the game step. Keys of $input: game_name, default_theme, uni_name, language, timezone, speed,
     * registration (open|closed), mail (bool), bots (0..50), update_check (bool, default on), discord_url (empty or
     * an http(s) URL).
     *
     * @param array<string, mixed> $input
     * @return array{error: ?string, field: string, values: array<string, mixed>} field = the form field of the error
     */
    public function validateGameSettings(array $input, string $locale): array
    {
        $LNG = $this->lng($locale);
        $values = [
            'game_name' => trim((string) ($input['game_name'] ?? 'V0Game')),
            'default_theme' => (string) ($input['default_theme'] ?? DEFAULT_THEME),
            'uni_name' => trim((string) ($input['uni_name'] ?? '')),
            'language' => (string) ($input['language'] ?? $locale),
            'timezone' => (string) ($input['timezone'] ?? $this->defaultTimezone()),
            'speed' => (string) ($input['speed'] ?? 'classic'),
            'registration' => (string) ($input['registration'] ?? 'open'),
            'mail' => !empty($input['mail']),
            'bots' => (int) ($input['bots'] ?? 0),
            'update_check' => array_key_exists('update_check', $input) ? !empty($input['update_check']) : true,
            'discord_url' => trim((string) ($input['discord_url'] ?? '')),
        ];
        if ($values['uni_name'] === '') {
            $values['uni_name'] = $LNG['fcm_universe'] . ' 1';
        }

        [$field, $error] = match (true) {
            $values['game_name'] === '' || mb_strlen($values['game_name']) > 64 => ['game_name', $LNG['ins_game_name_invalid']],
            !isset(Theme::getAvailableCssThemes()[$values['default_theme']]) => ['default_theme', $LNG['ins_theme_invalid']],
            mb_strlen($values['uni_name']) > 60 => ['uni_name', $LNG['ins_uni_name_invalid']],
            !array_key_exists($values['language'], $this->allowedLanguages()) => ['language', $LNG['ins_language_invalid']],
            !in_array($values['timezone'], $this->timezones(), true) => ['timezone', $LNG['ins_timezone_invalid']],
            !isset(self::SPEED_PRESETS[$values['speed']]) => ['speed', $LNG['ins_speed_invalid']],
            !in_array($values['registration'], ['open', 'closed'], true) => ['registration', $LNG['ins_registration_invalid']],
            $values['bots'] < 0 || $values['bots'] > 50 => ['bots', $LNG['ins_bots_invalid']],
            $values['discord_url'] !== '' && \App\Game\Alliance\AllianceText::httpUrlOrEmpty($values['discord_url']) === '' => ['discord_url', $LNG['ins_discord_url_invalid']],
            default => ['', null],
        };

        return ['error' => $error, 'field' => $field, 'values' => $values];
    }

    /**
     * Writes validated game settings (see validateGameSettings()) to the global and universe 1 configuration.
     *
     * @param array<mixed> $values
     */
    public function applyGameSettings(array $values): void
    {
        $global = 'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);';
        $this->db->insert($global, [':key' => 'game_name', ':value' => $values['game_name']]);
        $this->db->insert($global, [':key' => 'default_theme', ':value' => $values['default_theme']]);
        $this->db->insert($global, [':key' => 'timezone', ':value' => $values['timezone']]);
        $this->db->insert($global, [':key' => 'mail_active', ':value' => $values['mail'] ? '1' : '0']);
        $this->db->insert($global, [':key' => 'update_check', ':value' => ($values['update_check'] ?? true) ? '1' : '0']);
        $this->db->insert($global, [':key' => 'discord_url', ':value' => \App\Game\Alliance\AllianceText::httpUrlOrEmpty((string) ($values['discord_url'] ?? ''))]);

        $universe = [
            'uni_name' => $values['uni_name'],
            'lang' => $values['language'],
            // 0 = registration open + game open, 3 = registration closed + game open
            'uni_status' => $values['registration'] === 'open' ? '0' : '3',
            'bots_enabled' => $values['bots'] > 0 ? '1' : '0',
        ];
        foreach (self::SPEED_PRESETS[$values['speed']] as $key => $multiplier) {
            $universe[$key] = (string) ($key === 'storage_multiplier' ? $multiplier : $multiplier * 2500);
        }
        $sql = 'UPDATE %%CONFIG_UNIVERSE%% SET `value` = :value WHERE `uni` = 1 AND `key` = :key;';
        foreach ($universe as $key => $value) {
            $this->db->update($sql, [':key' => $key, ':value' => $value]);
        }
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /** Chat filter keys (universe config) the installer offers; the rest keeps the schema defaults. */
    public const CHAT_DEFAULTS = ['chat' => false, 'chat_global' => true, 'chat_block_links' => true];

    /**
     * Writes the chat choice to universe 1: module MODULE_CHAT on/off, `chat_global_enabled` and `chat_block_links`
     * (the other chat filters keep their defaults, editable in the administration). The module is off after the
     * build, so this only ever switches it on or confirms off.
     *
     * @param bool $enabled the chat module
     * @param bool $global the global channel
     * @param bool $blockLinks block links in the global channel
     */
    public function applyChatSettings(bool $enabled, bool $global = true, bool $blockLinks = true): void
    {
        $this->db->update(
            'UPDATE %%CONFIG_UNIVERSE_MODULES%% SET `state` = :state WHERE `uni` = 1 AND `module` = :module;',
            [':state' => $enabled ? 1 : 0, ':module' => MODULE_CHAT],
        );
        $sql = 'INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (1, :key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);';
        $this->db->insert($sql, [':key' => 'chat_global_enabled', ':value' => $global ? '1' : '0']);
        $this->db->insert($sql, [':key' => 'chat_block_links', ':value' => $blockLinks ? '1' : '0']);
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /**
     * Optional engagement features the installer offers (all off by default): universe setting key => short code used
     * for the form field (`feature_<code>`) and the label keys `ins_feat_<code>` / `ins_feat_<code>_info`.
     */
    public const ENGAGEMENT_FEATURES = [
        'events' => 'events_enabled',
        'catchup' => 'catchup_enabled',
        'contracts' => 'contracts_enabled',
        'alliance_goals' => 'alliance_goals_enabled',
        'returning' => 'returning_enabled',
        'seasons' => 'seasons_enabled',
        'cosmetics' => 'cosmetics_enabled',
        'bounties' => 'bounties_enabled',
        'alliance_wars' => 'alliance_wars_enabled',
        'battle_feed' => 'battle_feed_enabled',
        'derelicts' => 'derelicts_enabled',
        'mothership' => 'mothership_enabled',
        'expedition_stories' => 'expedition_stories_enabled',
        'relics' => 'relics_enabled',
    ];

    /**
     * Writes the chosen engagement features to universe 1 (`<feature>_enabled` = 1 or 0); their other settings keep
     * the schema defaults and are tuned in the administration.
     *
     * @param array<string, bool> $features feature code (key of ENGAGEMENT_FEATURES) => on
     */
    public function applyEngagementSettings(array $features): void
    {
        $sql = 'INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (1, :key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);';
        foreach (self::ENGAGEMENT_FEATURES as $code => $key) {
            $this->db->insert($sql, [':key' => $key, ':value' => !empty($features[$code]) ? '1' : '0']);
        }
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /** Switches the bots of universe 1 on or off (applyGameSettings() sets it from its own `bots` value). */
    public function applyBotsEnabled(int $count): void
    {
        $this->db->update(
            'UPDATE %%CONFIG_UNIVERSE%% SET `value` = :value WHERE `uni` = 1 AND `key` = \'bots_enabled\';',
            [':value' => $count > 0 ? '1' : '0'],
        );
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /**
     * Validates the e-mail step. Keys of $input: active (bool), user_valid (bool, only with mail on), method (0 = PHP mail(), 2 = SMTP, as the admin
     * setting mail_use), from (sender address), host, port, encryption ('', ssl, tls), user, password.
     * Sender is checked when mail is on; host and port only for SMTP.
     *
     * @param array<string, mixed> $input
     * @return array{error: ?string, values: array<string, mixed>}
     */
    public function validateMailSettings(array $input, string $locale): array
    {
        $LNG = $this->lng($locale);
        $values = [
            'active' => !empty($input['active']),
            'method' => (int) ($input['method'] ?? 0) === 2 ? 2 : 0,
            'from' => trim((string) ($input['from'] ?? '')),
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => trim((string) ($input['port'] ?? '')),
            'encryption' => (string) ($input['encryption'] ?? ''),
            'user' => trim((string) ($input['user'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'user_valid' => !empty($input['active']) && !empty($input['user_valid']),
        ];
        if (!in_array($values['encryption'], ['', 'ssl', 'tls'], true)) {
            $values['encryption'] = '';
        }
        $smtp = $values['active'] && $values['method'] === 2;

        $error = match (true) {
            $values['active'] && !filter_var($values['from'], FILTER_VALIDATE_EMAIL) => $LNG['ins_mail_from_invalid'],
            $smtp && $values['host'] === '' => $LNG['ins_mail_host_required'],
            $smtp && !$this->isPort($values['port']) => $LNG['ins_mail_port_invalid'],
            $values['port'] !== '' && !$this->isPort($values['port']) => $LNG['ins_mail_port_invalid'],
            default => null,
        };

        return ['error' => $error, 'values' => $values];
    }

    private function isPort(string $port): bool
    {
        return ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }

    /**
     * Writes validated e-mail settings (see validateMailSettings()) with the keys of the admin mail category.
     * Without `active` only mail_active = 0 is written (the "skip" case); other keys keep their defaults.
     *
     * @param array<mixed> $values
     */
    public function applyMailSettings(array $values): void
    {
        $global = 'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);';
        $this->db->insert($global, [':key' => 'mail_active', ':value' => !empty($values['active']) ? '1' : '0']);
        // e-mail activation needs working mail: off with mail off or skipped
        $this->db->insert($global, [':key' => 'user_valid', ':value' => !empty($values['active']) && !empty($values['user_valid']) ? '1' : '0']);
        if (!empty($values['active'])) {
            $this->db->insert($global, [':key' => 'mail_use', ':value' => (string) $values['method']]);
            $this->db->insert($global, [':key' => 'smtp_sendmail', ':value' => $values['from']]);
            $this->db->insert($global, [':key' => 'smtp_ssl', ':value' => $values['encryption']]);
            $this->db->insert($global, [':key' => 'smtp_host', ':value' => $values['host']]);
            $this->db->insert($global, [':key' => 'smtp_port', ':value' => (string) (int) $values['port']]);
            $this->db->insert($global, [':key' => 'smtp_user', ':value' => $values['user']]);
            $this->db->insert($global, [':key' => 'smtp_pass', ':value' => $values['password']]);
        }
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /**
     * Sends one test mail with the entered (not yet saved) values, the same transport choice as the Mail service.
     * Never throws; the password is masked in the returned message.
     *
     * @param array<string, mixed> $values validated values (see validateMailSettings())
     * @return ?string null when sent, else the error message
     */
    public function sendTestMail(array $values, string $to, string $locale): ?string
    {
        $LNG = $this->lng($locale);
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            if ((int) $values['method'] === 2) {
                $mail->isSMTP();
                $mail->SMTPSecure = (string) $values['encryption'];
                $mail->SMTPAutoTLS = $values['encryption'] !== '';
                $mail->Host = (string) $values['host'];
                $mail->Port = (int) $values['port'];
                $mail->Timeout = 10;
                if ($values['user'] !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = (string) $values['user'];
                    $mail->Password = (string) $values['password'];
                }
            } else {
                $mail->isMail();
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom((string) $values['from'], 'V0Game');
            $mail->addAddress($to);
            $mail->Subject = $LNG['ins_mail_test_subject'];
            $mail->Body = $LNG['ins_mail_test_body'];
            $mail->send();
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (($values['password'] ?? '') !== '') {
                $message = str_replace((string) $values['password'], '***', $message);
            }

            return $message;
        }

        return null;
    }

    /**
     * Validates the legal step. Keys of $input: privacy_region (eu|other), rules_default (default|blank),
     * cookie_consent (bool, default off), disclamer_mail and disclamer_address (imprint, both optional; the caller
     * passes them HTML-encoded like the admin form does).
     *
     * @param array<string, mixed> $input
     * @return array{error: ?string, values: array<string, mixed>}
     */
    public function validateLegalSettings(array $input, string $locale): array
    {
        $LNG = $this->lng($locale);
        $values = [
            'privacy_region' => (string) ($input['privacy_region'] ?? 'eu'),
            'rules_default' => (string) ($input['rules_default'] ?? 'default'),
            'cookie_consent' => !empty($input['cookie_consent']),
            'disclamer_mail' => trim((string) ($input['disclamer_mail'] ?? '')),
            'disclamer_address' => trim((string) ($input['disclamer_address'] ?? '')),
        ];

        $error = match (true) {
            !in_array($values['privacy_region'], ['eu', 'other'], true) => $LNG['ins_legal_region_invalid'],
            !in_array($values['rules_default'], ['default', 'blank'], true) => $LNG['ins_legal_rules_invalid'],
            $values['disclamer_mail'] !== '' && !filter_var(html_entity_decode($values['disclamer_mail']), FILTER_VALIDATE_EMAIL) => $LNG['ins_imprint_mail_invalid'],
            default => null,
        };

        return ['error' => $error, 'values' => $values];
    }

    /**
     * Writes validated legal settings (see validateLegalSettings()) to the global configuration.
     *
     * @param array<mixed> $values
     */
    public function applyLegalSettings(array $values): void
    {
        $global = 'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);';
        $this->db->insert($global, [':key' => 'privacy_region', ':value' => $values['privacy_region']]);
        $this->db->insert($global, [':key' => 'rules_default', ':value' => $values['rules_default'] === 'default' ? '1' : '0']);
        $this->db->insert($global, [':key' => 'cookie_consent', ':value' => !empty($values['cookie_consent']) ? '1' : '0']);
        $this->db->insert($global, [':key' => 'disclamer_mail', ':value' => (string) ($values['disclamer_mail'] ?? '')]);
        $this->db->insert($global, [':key' => 'disclamer_address', ':value' => (string) ($values['disclamer_address'] ?? '')]);
        $this->configs->invalidateCache();
        if ($this->configs->isLoaded()) {
            $this->configs->reload();
        }
    }

    /** @return array<string, string> empty when valid, else field => message */
    public function validateAdmin(string $username, string $password, string $confirm, string $mail, string $locale): array
    {
        $LNG = $this->lng($locale);
        $errors = [];
        $length = mb_strlen($username);
        if ($length < 3 || $length > 20) {
            $errors['username'] = $LNG['step4_admin_name_desc'];
        }
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $LNG['ins_admin_mail_invalid'];
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = $LNG->format('ins_admin_pass_short', self::MIN_PASSWORD_LENGTH);
        } elseif (strlen($password) > 64) {
            $errors['password'] = $LNG['ins_admin_pass_long'];
        } elseif ($password !== $confirm) {
            $errors['password'] = $LNG['ins_admin_pass_mismatch'];
        }

        return $errors;
    }

    /**
     * Creates the administrator account with its home planet in universe 1.
     *
     * @return array{0: int, 1: int, 2: int} account id, user id, planet id
     */
    public function createAdmin(string $username, string $password, string $mail, string $language): array
    {
        (new GameData($this->db, $this->configs, $this->state))->load();
        $accountId = $this->accountUtil()->createAccount($username, $this->accountUtil()->cryptPassword($password), $mail, $language, AuthLevel::Admin->value);
        [$userId, $planetId] = $this->playerRegistration()->createPlayer(
            $accountId,
            $this->universe()->current(),
            $username,
            $language,
            1,
            1,
            2,
            null,
            AuthLevel::Admin->value,
        );

        // the imprint contact mail was left empty in the legal step: the administrator's address is the contact
        $contact = $this->db->selectSingle('SELECT `value` FROM %%CONFIG%% WHERE `key` = :key', [':key' => 'disclamer_mail'], 'value');
        if ($contact === false || $contact === null || trim((string) $contact) === '') {
            $this->db->insert(
                'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);',
                [':key' => 'disclamer_mail', ':value' => $mail],
            );
            $this->configs->invalidateCache();
        }

        return [(int) $accountId, (int) $userId, (int) $planetId];
    }

    /** Spawns $count bots in universe 1, spread over the default profiles (mixed personalities). @return int number created */
    public function spawnBots(int $count): int
    {
        return count($this->botSpawner->spawnMixed((int) $this->universe()->current(), $count));
    }

    /** the system cron line for this installation */
    public function cronLine(): string
    {
        return '* * * * * cd ' . rtrim($this->root, '/') . ' && php bin/console app:cron:run --quiet';
    }

    /** locks the install tool (removes var/ENABLE_INSTALL_TOOL) */
    public function lock(): void
    {
        SafeFile::remove($this->root . 'var/ENABLE_INSTALL_TOOL');
    }

    /** the migration password generated in the database step, if any; it is removed from disk when read */
    public function takeGeneratedSecret(): ?string
    {
        $file = $this->root . self::SECRET_FILE;
        if (!is_file($file)) {
            return null;
        }
        $secret = trim((string) SafeFile::read($file));
        SafeFile::remove($file);

        return $secret === '' ? null : $secret;
    }
}
