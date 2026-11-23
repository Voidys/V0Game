<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Game\Core\AccountUtil;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Player\PlayerRegistration;
use App\Game\Core\Singleton;
use App\Game\Model\ElementCatalog;
use App\Kernel;
use App\Runtime\GameKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class of the tests that need the booted game (game data, universe config, language) and the dedicated
 * test database: boots the Symfony kernel (APP_ENV=test) and the game like `bin/console` does
 * (GameKernel::bootCli) once per process, and wraps every test in a transaction that is rolled back, so tests
 * cannot influence each other and the freshly installed schema stays pristine.
 *
 * Safety: refuses to run when the connected database name does not end in "_test" (the dev database is `db`).
 *
 * Golden values: tests compare against values captured from the code as it was before the legacy renewal
 * (see goldenAssert()). They pin behaviour, not correctness: a failing golden test means "the result changed",
 * which is only acceptable when a change is intended and the golden value is updated on purpose.
 */
abstract class GameTestCase extends TestCase
{
    use GoldenAssertions;

    private static ?Kernel $kernel = null;
    private static ?ContainerInterface $container = null;
    private static int $positionCounter = 0;
    private static ?string $passwordHash = null;
    /** @var list<array{0: int, 1: int}> [account id, user id] of the players this test created */
    private array $createdPlayers = [];

    protected Database $db;
    protected ConfigRegistry $configs;
    protected Singleton $state;

    /** the typed game data of the booted game (the only source of the unit tables) */
    protected function elements(): ElementCatalog
    {
        return $this->state->elementCatalog;
    }

    public static function container(): ContainerInterface
    {
        if (self::$container === null) {
            self::$kernel = new Kernel('test', true);
            self::$kernel->boot();
            self::$container = self::$kernel->getContainer()->get('test.service_container');
            self::$container->get(Singleton::class)->time = ['start' => microtime(true), 'db' => 0.0];

            $dbName = (string) self::$container->get(Database::class)->selectSingle('SELECT DATABASE() AS db', [], 'db');
            if (!str_ends_with($dbName, '_test')) {
                throw new \RuntimeException("Refusing to run integration tests against database '$dbName' (name must end in _test).");
            }
            self::$container->get(GameKernel::class)->bootCli();
            // the game registers its own error/exception handlers (ErrorReporter); PHPUnit manages its own
            restore_error_handler();
            restore_exception_handler();
        }

        return self::$container;
    }

    protected function setUp(): void
    {
        $container = self::container();
        $this->db = $container->get(Database::class);
        $this->configs = $container->get(ConfigRegistry::class);
        $this->state = $container->get(Singleton::class);
        // the state is one shared container service: no test may inherit the previous test's game session
        // (SessionManager::create() reuses an existing one, bound to that test's request)
        $this->state->session = null;
        $this->createdPlayers = [];
        $this->db->startTransaction();
        $this->configs->reload();
        // the defaults of a fresh install, spelled out so a golden value never depends on a changed default
        $this->setUniverseConfig([
            'uni_status' => STATUS_OPEN,
            'uni_type' => 0,
            'galaxy_type' => 0,
            'building_speed' => 2500,
            'shipyard_speed' => 2500,
            'research_speed' => 2500,
            'fleet_speed' => 2500,
            'resource_multiplier' => 2500,
            'storage_multiplier' => 1,
            'energy_multiplier' => 1,
            'metal_basic_income' => 20,
            'crystal_basic_income' => 10,
            'deuterium_basic_income' => 0,
            'max_overflow' => 1,
            'factor_university' => 8,
            'min_build_time' => 1,
            'catch_up' => false,
            'planet_ressource_bonus' => false,
            'max_galaxy' => 9,
            'max_system' => 400,
            'max_planets' => 15,
            'max_elements_build' => 5,
            'max_elements_tech' => 2,
            'galaxy_distance_factor' => 20000,
            'system_distance_factor' => 95,
            'system_distance' => 2700,
            'planet_distance_factor' => 5,
            'planet_distance' => 1000,
            'fleet_debris_percentage' => 30,
            'def_debris_percentage' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
        $this->purgeCommittedPlayers();
        $this->configs->reload();
    }

    /**
     * Code under test that runs its own SQL transaction makes MySQL commit the test transaction implicitly, so the
     * rollback above keeps everything created before that point. Deletes the players (and their rows) this test
     * created that survived the rollback, so the suite can run twice on the same database (no leaked coordinates).
     */
    private function purgeCommittedPlayers(): void
    {
        foreach ($this->createdPlayers as [$accountId, $userId]) {
            foreach (['PLANETS' => 'id_owner', 'STATPOINTS' => 'id_owner', 'USERS_SETTINGS' => 'user_id', 'MESSAGES' => 'message_owner', 'USERS_TO_MESSAGES' => 'user_id', 'ALLIANCE_REQUEST' => 'userId', 'USERS' => 'id'] as $table => $column) {
                $this->db->delete("DELETE FROM %%$table%% WHERE $column = :id;", [':id' => $userId]);
            }
            $this->db->delete('DELETE FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $accountId]);
        }
        $this->createdPlayers = [];
    }

    /** @param array<string, mixed> $values in-memory universe settings (not saved) */
    protected function setUniverseConfig(array $values, int $universe = 1): void
    {
        $config = $this->configs->get($universe);
        foreach ($values as $key => $value) {
            $config->$key = $value;
        }
    }

    /**
     * Creates an account, a player and its home planet in universe 1 (inside the test transaction) and returns the
     * rows the game works with. $user / $planet override columns (e.g. ['metal_mine' => 10, 'metal' => 1e6]); they are
     * written to the database, then both rows are read back.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} USER row (users + users_settings), PLANET row
     */
    protected function createPlayer(array $user = [], array $planet = []): array
    {
        $container = self::container();
        $accounts = new AccountUtil($this->db, $this->configs, $this->state);
        $players = new PlayerRegistration($this->db, $this->configs, $this->state);

        $n = ++self::$positionCounter;
        $name = 'tester' . $n;
        $accountId = $accounts->createAccount($name, self::$passwordHash ??= password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), $name . '@example.com', 'en');
        [$userId, $planetId] = $players->createPlayer($accountId, 1, $name, 'en', 1 + intdiv($n, 400) % 9, 1 + ($n % 400), 8);

        $this->createdPlayers[] = [(int) $accountId, (int) $userId];
        foreach (['users' => [$user, $userId], 'planets' => [$planet, $planetId]] as $table => [$values, $id]) {
            if ($values === []) {
                continue;
            }
            $set = implode(', ', array_map(static fn(string $column): string => "`$column` = :$column", array_keys($values)));
            $this->db->update("UPDATE %%" . strtoupper($table) . "%% SET $set WHERE id = :id;", [':id' => $id] + array_combine(
                array_map(static fn(string $column): string => ':' . $column, array_keys($values)),
                array_values($values),
            ));
        }

        return [$this->loadUser((int) $userId), $this->loadPlanet((int) $planetId)];
    }

    /** @return array<string, mixed> */
    protected function loadUser(int $userId): array
    {
        return $this->db->selectSingle('SELECT u.*, us.* FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id WHERE u.id = :id;', [':id' => $userId]);
    }

    /** @return array<string, mixed> */
    protected function loadPlanet(int $planetId): array
    {
        return $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id = :id;', [':id' => $planetId]);
    }
}
