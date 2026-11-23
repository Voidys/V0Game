<?php

namespace App\Game\Core;

use App\Game\Core\Config\ConfigCache;
use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Runtime\SafeFile;
use Exception;

/**
 * The game configuration: one Config per universe (global keys merged with the universe's keys and
 * modules), loaded on first use and shared for the request. Formerly the static side of Config
 * (Config::get(), Config::reload()).
 */
class ConfigRegistry
{
    /** @var array<int, Config> */
    private array $instances = [];
    private ?ConfigFieldSchema $schemaGlobal = null;
    private ?ConfigFieldSchema $schemaUni = null;
    /** @var array<string, string>|null global key/value rows as stored (untransformed), for App\Runtime\GameConfig */
    private ?array $rawGlobal = null;
    /** @var array<int, array<string, string>> per-universe key/value rows as stored (untransformed) */
    private array $rawUniverse = [];

    private ?ConfigCache $cache = null;

    /**
     * @param bool $cacheEnabled false: always read the database (tests write the config tables directly)
     * @param string|null $cacheDir directory of the cache files with trailing slash, default var/game/
     */
    public function __construct(
        private Database $db,
        private Singleton $state,
        private bool $cacheEnabled = true,
        private ?string $cacheDir = null,
    ) {}

    /**
     * Declares the cached configuration stale. Call it after every write to `config`, `config_universe` or
     * `config_universe_modules` that does not go through Config::save*() (installer, universe admin, migrations).
     */
    public function invalidateCache(): void
    {
        $this->cache()->invalidate();
    }

    private function cache(): ConfigCache
    {
        return $this->cache ??= new ConfigCache($this->cacheDir ?? $this->state->runtime->cachePath, $this->cacheEnabled);
    }

    /** Cache validity beyond the stamp: database, schema code and the module list (all fixed per deploy). */
    private function cacheFingerprint(): string
    {
        $root = $this->state->runtime->rootPath . 'src/Game/Core/Config/';

        return md5(implode('|', [
            $this->db->getDatabaseName(),
            $this->db->getTablePrefix(),
            SafeFile::mtime($root . 'ConfigFieldSchema.php'),
            SafeFile::mtime($root . 'Schema'),
            json_encode($this->state->modules),
        ]));
    }

    /** Fingerprint without the module list (known only after the game booted). */
    private function baseFingerprint(): string
    {
        $root = $this->state->runtime->rootPath . 'src/Game/Core/Config/';

        return md5(implode('|', [
            $this->db->getDatabaseName(),
            $this->db->getTablePrefix(),
            SafeFile::mtime($root . 'ConfigFieldSchema.php'),
            SafeFile::mtime($root . 'Schema'),
        ]));
    }

    /**
     * Raw rows for App\Runtime\GameConfig: the loaded ones, else those of a valid cache file (without building the
     * instances); null when neither is available (the caller queries the tables).
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}|null
     */
    public function rawRowsOrCached(): ?array
    {
        return $this->rawRows() ?? $this->cache()->loadRaw($this->cache()->stamp(), $this->baseFingerprint());
    }

    /** true once the instances are built (texts loaded during the build must not ask for the config) */
    public function isLoaded(): bool
    {
        return !empty($this->instances);
    }

    /**
     * Config of a universe; 0 = the current universe (1 when none is selected). The first call loads all
     * configurations from the database.
     * @throws Exception for an unknown universe id.
     */
    public function get(int $universe = 0): Config
    {
        if (empty($this->instances)) {
            $this->generateInstances(true);
        }

        if ($universe === 0) {
            $universe = (new Universe($this->state))->current();
        }

        if ($universe === 0) {
            $universe = 1;
        }

        if (!isset($this->instances[$universe])) {
            throw new Exception("Unknown universe id: " . $universe);
        }

        return $this->instances[$universe];
    }

    /**
     * Rereads the configuration from the database (after settings were saved by others).
     */
    public function reload(): void
    {
        $this->cache()->invalidate();
        $this->generateInstances(false);
    }

    /**
     * The rows read by the last load, untransformed; null while the configuration is not loaded.
     * App\Runtime\GameConfig reads them instead of querying the same tables again.
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}|null
     */
    public function rawRows(): ?array
    {
        return $this->rawGlobal === null ? null : [$this->rawGlobal, $this->rawUniverse];
    }

    /** @internal for Config and Language (request data in the game state) */
    public function gameState(): Singleton
    {
        return $this->state;
    }

    /** @internal for Config */
    public function database(): Database
    {
        return $this->db;
    }

    /** @internal for Config */
    public function globalSchema(): ConfigFieldSchema
    {
        // built on first use: the labels need the language files, which a cached request does not touch
        return $this->schemaGlobal ??= new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_BASIC, $this->state, $this);
    }

    /** @internal for Config */
    public function universeSchema(): ConfigFieldSchema
    {
        return $this->schemaUni ??= new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this);
    }

    /**
     * Reads `config` and `config_universe` (values converted by the field transforms of ConfigFieldSchema), inserts
     * missing keys with their schema default and missing modules as disabled, merges global and universe keys and
     * creates one Config per universe (registering it with Universe). Also remembers the raw rows for App\Runtime\GameConfig.
     */
    private function generateInstances(bool $useCache): void
    {
        $cache = $this->cache();
        $stamp = $cache->stamp();
        $fingerprint = $this->cacheFingerprint();
        $data = $useCache ? $cache->load($stamp, $fingerprint) : null;

        if ($data === null) {
            [$globalConfig, $rawGlobal] = $this->loadGlobalConfig();
            [$universeConfigs, $rawUniverse] = $this->loadUniverseConfigs();
            $moduls = [];
            foreach (array_keys($universeConfigs) as $uni) {
                $moduls[$uni] = $this->loadModules($uni);
            }
            $data = [
                'global' => $globalConfig,
                'rawGlobal' => $rawGlobal,
                'universes' => $universeConfigs,
                'rawUniverse' => $rawUniverse,
                'moduls' => $moduls,
            ];
            $cache->store($stamp, $fingerprint, $data, $this->baseFingerprint());
        }

        $this->rawGlobal = $data['rawGlobal'];
        $this->rawUniverse = $data['rawUniverse'];

        foreach ($data['universes'] as $uni => $config) {
            // Merge global config with universe config
            $configData = array_merge($data['global'], $config);
            $configData['moduls'] = $data['moduls'][$uni];
            $configData['uni'] = $uni;

            $this->instances[$uni] = new Config($configData, $this);
            (new Universe($this->state))->add($uni);
        }
    }

    /**
     * Reads the global key-value table `config`, converts the values by the field transforms of the schema and inserts
     * every key the schema knows but the table lacks with its default.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} converted values, raw values by key
     */
    private function loadGlobalConfig(): array
    {
        $db = $this->db;

        // Load global config from key-value table
        $globalConfig = [];
        $rawGlobal = [];
        $sql = 'SELECT `key`, `value` FROM %%CONFIG%%;';
        $globalConfigResult = $db->select($sql);
        foreach ($globalConfigResult as $row) {
            $key = $row['key'];
            $value = $row['value'];
            $rawGlobal[$key] = $value;

            $field = $this->globalSchema()->getField($key);
            if ($field && isset($field['transform'])) {
                $value = ConfigFieldProcessor::applyTypeTransform($value, $field['transform']);
            }
            $globalConfig[$key] = $value;
        }

        // Ensure all expected keys are present in global config
        $expectedKeys = array_keys($this->globalSchema()->getAllFields());
        $keysMissing = array_diff($expectedKeys, array_keys($globalConfig));
        // IGNORE: a parallel request (cron, another page) may add the same missing key first
        $sql = 'INSERT IGNORE INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value);';
        foreach ($keysMissing as $missingKey) {
            $fieldConfig = $this->globalSchema()->getField($missingKey);
            $db->insert($sql, [
                ':key' => $missingKey,
                ':value' => $fieldConfig['default'] ?? '',
            ]);
            $globalConfig[$missingKey] = $fieldConfig['default'] ?? '';
            $rawGlobal[$missingKey] = (string) ($fieldConfig['default'] ?? '');
        }

        return [$globalConfig, $rawGlobal];
    }

    /**
     * Reads the per-universe key-value table `config_universe` (converted by the field transforms of the schema) and
     * inserts every key the schema knows but a universe lacks with its default.
     *
     * @return array{0: array<int|string, array<string, mixed>>, 1: array<int|string, array<string, string>>} converted
     *         and raw values by universe and key
     */
    private function loadUniverseConfigs(): array
    {
        $db = $this->db;
        $rawUniverse = [];

        // Load universe configs from key-value table
        $universeConfigs = [];
        $sql = 'SELECT `uni`, `key`, `value` FROM %%CONFIG_UNIVERSE%% ORDER BY `uni`;';
        $universeResult = $db->select($sql);
        foreach ($universeResult as $row) {
            $uni = $row['uni'];
            $key = $row['key'];
            $value = $row['value'];
            $rawUniverse[$uni][$key] = $value;

            $field = $this->universeSchema()->getField($key);
            if ($field && isset($field['transform'])) {
                $value = ConfigFieldProcessor::applyTypeTransform($value, $field['transform']);
            }

            if (!isset($universeConfigs[$uni])) {
                $universeConfigs[$uni] = [];
            }
            $universeConfigs[$uni][$key] = $value;
        }

        // Ensure all expected keys are present for each universe config
        $expectedKeys = array_keys($this->universeSchema()->getAllFields());
        foreach ($universeConfigs as $uni => $configs) {
            $keysMissing = array_diff($expectedKeys, array_keys($configs));

            $sql = 'INSERT IGNORE INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (:uni, :key, :value);';
            foreach ($keysMissing as $missingKey) {
                $fieldConfig = $this->universeSchema()->getField($missingKey);
                $db->insert($sql, [
                    ':uni' => $uni,
                    ':key' => $missingKey,
                    ':value' => $fieldConfig['default'] ?? '',
                ]);
                $universeConfigs[$uni][$missingKey] = $fieldConfig['default'] ?? '';
                $rawUniverse[$uni][$missingKey] = (string) ($fieldConfig['default'] ?? '');
            }
        }

        return [$universeConfigs, $rawUniverse];
    }

    /**
     * The module states of a universe (module id => state); modules the game knows but the table lacks are inserted as
     * disabled (0).
     *
     * @return array<int|string, int|string>
     */
    private function loadModules(int|string $uni): array
    {
        $db = $this->db;
        $sql = 'SELECT * FROM %%CONFIG_UNIVERSE_MODULES%% WHERE `uni` = :universe;';
        $modulesResult = $db->select($sql, [':universe' => $uni]);
        $moduls = [];
        foreach ($modulesResult as $modulesRow) {
            $moduls[$modulesRow['module']] = $modulesRow['state'];
        }

        // Ensure all modules are present
        $modules = $this->state->modules;
        foreach ($modules as $moduleKey => $moduleName) {
            if (!isset($moduls[$moduleKey])) {
                $sql = 'INSERT INTO %%CONFIG_UNIVERSE_MODULES%% (`uni`, `module`, `module_name`, `state`)
                    VALUES (:uni, :module, :module_name, :state)';
                $db->insert($sql, [
                    ':uni' => $uni,
                    ':module' => $moduleKey,
                    ':module_name' => $moduleName,
                    ':state' => 0,
                ]);
                $moduls[$moduleKey] = 0;
            }
        }

        return $moduls;
    }
}
