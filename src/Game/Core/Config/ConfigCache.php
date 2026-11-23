<?php

namespace App\Game\Core\Config;

use App\Runtime\SafeFile;

/**
 * File cache (opcache friendly PHP array) of the parsed game configuration: the converted and raw rows of `config`,
 * `config_universe` and the module states, so a request does not run three queries and the transforms.
 *
 * Validity: the entry carries a generation stamp (config.stamp) and a fingerprint (database, table prefix, schema
 * code, module list). Every writer of the config tables calls invalidate(), which only writes a new random stamp. The
 * reader takes the stamp BEFORE it reads the database and stores the entry under that stamp, so a request that read
 * old rows while someone saved cannot publish them afterwards: its stamp no longer matches. Hand-edited rows or a
 * restored database need the stamp bumped (delete var/game/ or run the admin "clear cache").
 */
final class ConfigCache
{
    private const FILE = 'cache.config.php';
    private const STAMP = 'config.stamp';

    public function __construct(
        private readonly string $dir,
        private readonly bool $enabled = true,
    ) {}

    /** Current generation stamp ('' when none was written yet or the cache is off). */
    public function stamp(): string
    {
        if (!$this->enabled) {
            return '';
        }

        return trim((string) SafeFile::read($this->dir . self::STAMP, 64));
    }

    /**
     * @return array<string, mixed>|null the cached data when stamp and fingerprint match, else null
     */
    public function load(string $stamp, string $fingerprint): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $file = $this->dir . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        $entry = include $file;

        return is_array($entry) && ($entry['stamp'] ?? null) === $stamp && ($entry['fingerprint'] ?? null) === $fingerprint
            && is_array($entry['data'] ?? null) ? $entry['data'] : null;
    }

    /**
     * Only the raw rows of a cache entry, validated by stamp and the module-free base fingerprint; for readers that
     * run before the game booted (the module list is not known yet).
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}|null
     */
    public function loadRaw(string $stamp, string $base): ?array
    {
        if (!$this->enabled || !is_file($this->dir . self::FILE)) {
            return null;
        }
        $entry = include $this->dir . self::FILE;
        $data = is_array($entry) ? ($entry['data'] ?? null) : null;
        if (!is_array($data) || ($entry['stamp'] ?? null) !== $stamp || ($entry['base'] ?? null) !== $base
            || !is_array($data['rawGlobal'] ?? null) || !is_array($data['rawUniverse'] ?? null)) {
            return null;
        }

        return [$data['rawGlobal'], $data['rawUniverse']];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function store(string $stamp, string $fingerprint, array $data, string $base = ''): void
    {
        if (!$this->enabled) {
            return;
        }
        SafeFile::write($this->dir . self::FILE, '<?php return ' . var_export(['stamp' => $stamp, 'fingerprint' => $fingerprint, 'base' => $base, 'data' => $data], true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->dir . self::FILE, true);
        }
    }

    /** Declares every cached entry stale (call after any write to the config tables). */
    public function invalidate(): void
    {
        if (!$this->enabled) {
            return;
        }
        SafeFile::write($this->dir . self::STAMP, bin2hex(random_bytes(8)));
    }
}
