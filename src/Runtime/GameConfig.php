<?php

namespace App\Runtime;

use App\Game\Core\ConfigRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Read access to the game configuration (App\Game\Core\Config):
 * global key/value pairs ({prefix}config) merged with the per-universe ones
 * ({prefix}config_universe). Values are raw strings, as stored.
 * Once the game's ConfigRegistry has loaded the same tables in this request, or a valid config cache file exists
 * (ConfigCache), those rows are reused instead of querying.
 */
final class GameConfig
{
    /** Same as the legacy ROOT_UNI constant. */
    public const ROOT_UNIVERSE = 1;

    /** @var array<string, string>|null */
    private ?array $global = null;

    /** @var array<int, array<string, string>> */
    private array $universes = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly TableNames $tables,
        /** @var \Closure(): ConfigRegistry */
        #[AutowireServiceClosure(ConfigRegistry::class)]
        private readonly \Closure $registry,
    ) {}

    /** Value of `$key`: the universe's own setting, else the global one, else `$default`. */
    public function get(string $key, ?string $default = null, int $universe = self::ROOT_UNIVERSE): ?string
    {
        return $this->universe($universe)[$key] ?? $this->global()[$key] ?? $default;
    }

    /** Boolean view of a numeric setting ("0"/"1"); missing keys are false. */
    public function getBool(string $key, int $universe = self::ROOT_UNIVERSE): bool
    {
        return (bool) (int) $this->get($key, '0', $universe);
    }

    /** @return array<string, string> */
    private function global(): array
    {
        $rows = ($this->registry)()->rawRowsOrCached();
        if ($rows !== null) {
            return $rows[0];
        }

        return $this->global ??= $this->connection->fetchAllKeyValue(
            sprintf('SELECT `key`, `value` FROM %s', $this->tables->get('config')),
        );
    }

    /** @return array<string, string> */
    private function universe(int $universe): array
    {
        $rows = ($this->registry)()->rawRowsOrCached();
        if ($rows !== null) {
            return $rows[1][$universe] ?? [];
        }

        return $this->universes[$universe] ??= $this->connection->fetchAllKeyValue(
            sprintf('SELECT `key`, `value` FROM %s WHERE `uni` = ?', $this->tables->get('config_universe')),
            [$universe],
        );
    }
}
