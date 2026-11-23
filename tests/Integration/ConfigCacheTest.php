<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\ConfigRegistry;
use App\Tests\Support\GameTestCase;

/**
 * The parsed-config file cache (ConfigCache, used by ConfigRegistry): a warm registry reads no config table, every
 * write path (Config::save*, reload) makes the next reader see the new rows, and a reader that read the database
 * before a write cannot publish its old rows afterwards.
 */
final class ConfigCacheTest extends GameTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/config-cache-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function registry(): ConfigRegistry
    {
        return new ConfigRegistry($this->db, $this->state, true, $this->dir);
    }

    public function testWarmRegistryMatchesTheDatabaseRead(): void
    {
        $cold = $this->registry();
        $coldValue = $cold->get(1)->uni_name;
        $this->assertFileExists($this->dir . 'cache.config.php');

        $warm = $this->registry();
        $this->assertSame($coldValue, $warm->get(1)->uni_name);
        $this->assertSame($cold->get(1)->fleet_speed, $warm->get(1)->fleet_speed);
        $this->assertSame($cold->get(1)->moduls, $warm->get(1)->moduls);
        $this->assertSame($cold->rawRows(), $warm->rawRows());
    }

    public function testWarmRegistryDoesNotTouchTheDatabase(): void
    {
        $this->registry()->get(1);
        // a row changed behind the cache's back is not seen until the stamp changes: proves the cache is served
        $this->db->update("UPDATE %%CONFIG_UNIVERSE%% SET `value` = 'behind the back' WHERE `uni` = 1 AND `key` = 'uni_name';");
        $this->assertNotSame('behind the back', $this->registry()->get(1)->uni_name);
    }

    public function testSaveInvalidatesTheCache(): void
    {
        $writer = $this->registry();
        $writer->get(1);
        $this->registry()->get(1);

        $config = $writer->get(1);
        $config->uni_name = 'cache test universe';
        $config->save();

        $this->assertSame('cache test universe', $this->registry()->get(1)->uni_name);
    }

    public function testSaveGlobalKeysAndModulesInvalidateTheCache(): void
    {
        $writer = $this->registry();
        $config = $writer->get(1);
        $this->registry()->get(1);

        $config->game_name = 'cache test game';
        $config->saveGlobalKeys();
        $this->assertSame('cache test game', $this->registry()->get(1)->game_name);

        $modules = $config->moduls;
        $first = array_key_first($modules);
        $modules[$first] = $modules[$first] ? 0 : 1;
        $config->moduls = $modules;
        $config->saveModules();
        $this->assertEquals($modules[$first], $this->registry()->get(1)->moduls[$first]);
    }

    public function testReloadReadsTheDatabaseAgain(): void
    {
        $registry = $this->registry();
        $registry->get(1);
        $this->db->update("UPDATE %%CONFIG_UNIVERSE%% SET `value` = 'edited by hand' WHERE `uni` = 1 AND `key` = 'uni_name';");

        $registry->reload();

        $this->assertSame('edited by hand', $registry->get(1)->uni_name);
        $this->assertSame('edited by hand', $this->registry()->get(1)->uni_name);
    }

    public function testReaderThatReadBeforeAWriteCannotPublishItsRows(): void
    {
        $cache = new \App\Game\Core\Config\ConfigCache($this->dir);
        $staleStamp = $cache->stamp();
        $cache->invalidate(); // a writer saved after the slow reader took its stamp
        $cache->store($staleStamp, 'f', ['old' => true]);

        $this->assertNull($cache->load($cache->stamp(), 'f'));
    }

    public function testFingerprintMismatchIsAMiss(): void
    {
        $cache = new \App\Game\Core\Config\ConfigCache($this->dir);
        $cache->store($cache->stamp(), 'a', ['x' => 1]);

        $this->assertSame(['x' => 1], $cache->load($cache->stamp(), 'a'));
        $this->assertNull($cache->load($cache->stamp(), 'b'));
    }

    public function testDisabledCacheNeverStores(): void
    {
        $cache = new \App\Game\Core\Config\ConfigCache($this->dir, false);
        $cache->store('', 'a', ['x' => 1]);
        $cache->invalidate();

        $this->assertSame([], glob($this->dir . '*'));
        $this->assertNull($cache->load('', 'a'));
    }

    private function gameConfig(ConfigRegistry $registry): \App\Runtime\GameConfig
    {
        return new \App\Runtime\GameConfig(
            self::container()->get('doctrine.dbal.default_connection'),
            self::container()->get(\App\Runtime\TableNames::class),
            static fn(): ConfigRegistry => $registry,
        );
    }

    public function testGameConfigReadsTheCacheFileBeforeTheGameBooted(): void
    {
        $writer = $this->registry();
        $expected = $writer->get(1)->uni_name;
        $writer->get(1)->game_name;

        // a registry that has not loaded anything (the state of the session policy / rate limit listeners)
        $cold = $this->registry();
        $this->assertFalse($cold->isLoaded());
        $this->db->update("UPDATE %%CONFIG_UNIVERSE%% SET `value` = 'behind the back' WHERE `uni` = 1 AND `key` = 'uni_name';");
        $config = $this->gameConfig($cold);
        $this->assertSame($expected, $config->get('uni_name'), 'served from the cache file, no table read');
        $this->assertFalse($cold->isLoaded(), 'the instances were not built for it');
    }

    public function testGameConfigSeesASaveThroughTheStamp(): void
    {
        $writer = $this->registry();
        $config = $writer->get(1);
        $this->assertNotSame('', $this->gameConfig($this->registry())->get('uni_name') ?? '');

        $config->uni_name = 'game config cache test';
        $config->save();

        $this->assertSame('game config cache test', $this->gameConfig($this->registry())->get('uni_name'));
    }

    public function testGameConfigFallsBackToTheTablesWithoutACache(): void
    {
        $registry = new ConfigRegistry($this->db, $this->state, false, $this->dir);
        $this->db->update("UPDATE %%CONFIG_UNIVERSE%% SET `value` = 'plain read' WHERE `uni` = 1 AND `key` = 'uni_name';");

        $this->assertSame('plain read', $this->gameConfig($registry)->get('uni_name'));
    }
}
