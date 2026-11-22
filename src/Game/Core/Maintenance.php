<?php

namespace App\Game\Core;

use App\Runtime\SafeFile;

/**
 * Cache clearing and the database migration check (formerly the ClearCache(), listMigrations()
 * and checkDBUpgrade() globals in functions.php, which remain as wrappers).
 */
class Maintenance
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Admin/cron "clear cache": deletes the cache files in var/game/ and var/game/languages/, the compiled Twig
     * templates, recalculates all cronjob times, empties planets.eco_hash (production is recalculated on next
     * access) and stores the version from install/VERSION in the config.
     */
    public function clearCache()
    {
        $DIRS = ['var/game/', 'var/game/languages/'];
        foreach ($DIRS as $DIR) {
            if (!is_dir($this->state->runtime->rootPath . $DIR)) {
                mkdir($this->state->runtime->rootPath . $DIR);
            }
            $FILES = array_diff(scandir($DIR), ['..', '.', '.htaccess']);
            foreach ($FILES as $FILE) {
                if (is_dir($this->state->runtime->rootPath . $DIR . $FILE)) {
                    continue;
                }

                unlink($this->state->runtime->rootPath . $DIR . $FILE);
            }
        }

        $this->gameView()->clearCompiledTemplates();

        (new \App\Game\Cronjob\Cronjob($this->db, $this->configs, $this->state))->reCalculateCronjobs(null, true);

        $sql = 'UPDATE %%PLANETS%% SET eco_hash = :ecoHash;';
        $this->db->update($sql, [
            ':ecoHash' => '',
        ]);
        clearstatcache();
        // var/game/ is wiped above, the generation stamp with it: bump it so no stale reader publishes old rows
        $this->configs->invalidateCache();

        // The release version lives in install/VERSION (year scheme: 1.<yy>.<patch>, e.g. 1.26.0 for 2026).
        $version = trim((string) SafeFile::read($this->state->runtime->rootPath . 'install/VERSION'));
        if ($version !== '') {
            $config = $this->configs->get();
            $config->version = $version;
            $config->saveGlobalKeys();
        }
    }

    /**
     * Migrations that still have to run: files of install/migrations/ minus the revisions recorded in `system`
     * (dbVersion). Legacy installs that recorded only one version number get every file below it marked as done.
     * @return array<string, array{pathName:string, fileName:string, fileRevision:string, fileExtension:string}> by revision, ascending.
     * Side effect: may insert revisions into `system`.
     */
    public function listMigrations(): array
    {
        $db = $this->db;

        $sql = "SELECT dbVersion FROM %%SYSTEM%%;";
        $dbVersions = $db->select($sql);

        $migrationFileList = $this->migrationFiles();
        ksort($migrationFileList);

        $count = count($dbVersions); // add already done migration to db.
        $sql = "INSERT INTO %%SYSTEM%% VALUES (:dbVersion);";
        if ($count === 1) {
            foreach ($migrationFileList as $fileRevision => $fileInfo) {
                if ((int) $fileRevision < (int) $dbVersions[0]['dbVersion']) {
                    $db->insert($sql, [':dbVersion' => (int) $fileRevision]);
                    unset($migrationFileList[$fileRevision]);
                }
            }
        }

        foreach ($dbVersions as $key => $version) {
            if (array_key_exists($version['dbVersion'], $migrationFileList)) {
                unset($migrationFileList[$version['dbVersion']]);
            }
        }
        return $migrationFileList;
    }

    /**
     * The migration files by revision. The directory scan is cached in var/game/ and rebuilt when the
     * directory's modification time changes (a file was added, removed or renamed).
     *
     * @return array<mixed>
     */
    private function migrationFiles(): array
    {
        $directory = $this->state->runtime->rootPath . 'install/migrations/';
        $cacheFile = $this->state->runtime->cachePath . 'cache.migrations.php';
        $mtime = SafeFile::mtime($directory);

        if ($mtime > 0 && is_file($cacheFile)) {
            $cached = include $cacheFile;
            if (is_array($cached) && ($cached['mtime'] ?? null) === $mtime && ($cached['directory'] ?? null) === $directory) {
                return $cached['files'];
            }
        }

        $directoryIterator = new \DirectoryIterator($directory);
        $migrationFileList = [];

        foreach ($directoryIterator as $fileInfo) {
            if (!($fileInfo instanceof \SplFileInfo) || !$fileInfo->isFile() || !preg_match('/^migration_\d+/', $fileInfo->getFilename())) {
                continue;
            }

            $fileRevision = substr($fileInfo->getFilename(), 10, -4);
            $migrationFileList[$fileRevision] = [
                'pathName'      => $fileInfo->getPathname(),
                'fileName'      => $fileInfo->getFilename(),
                'fileRevision'  => $fileRevision,
                'fileExtension' => $fileInfo->getExtension(),
            ];
        }

        if ($mtime > 0 && is_dir(dirname($cacheFile))) {
            $code = '<?php return ' . var_export(['mtime' => $mtime, 'directory' => $directory, 'files' => $migrationFileList], true) . ';';
            SafeFile::write($cacheFile, $code);
        }

        return $migrationFileList;
    }

    /**
     * True when at least one migration is pending (triggers the upgrade page).
     *
     * "Nothing pending" is remembered in var/game/cache.dbstatus.php under a key of database, table prefix, the
     * migration directory's modification time and config/game.php, so a normal request skips the `system` query. A new
     * or removed migration file changes the key and brings the real check back; pending migrations are never cached.
     * A database restored behind the game's back needs var/game/ cleared (admin "clear cache").
     */
    public function checkDBUpgrade(): bool
    {
        $root = $this->state->runtime->rootPath;
        $key = md5(implode('|', [
            $this->db->getDatabaseName(),
            $this->db->getTablePrefix(),
            SafeFile::mtime($root . 'install/migrations/'),
            SafeFile::mtime($root . 'config/game.php'),
        ]));
        $cacheFile = $this->state->runtime->cachePath . 'cache.dbstatus.php';
        if (is_file($cacheFile) && (include $cacheFile) === $key) {
            return false;
        }

        $pending = !empty($this->listMigrations());
        if (!$pending) {
            SafeFile::write($cacheFile, '<?php return ' . var_export($key, true) . ';');
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cacheFile, true);
            }
        }

        return $pending;
    }
}
