<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fleet\FleetTemplateStore;
use App\Tests\Support\GameTestCase;

/** Fleet templates: create/list/delete, the per-player limit and that players cannot delete each other's templates. */
final class FleetTemplateStoreTest extends GameTestCase
{
    private FleetTemplateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new FleetTemplateStore($this->db);
    }

    public function testCreateListDelete(): void
    {
        [$user] = $this->createPlayer();
        $id = $this->store->create((int) $user['id'], 'raid', '202:50,210:2', 1800000000);
        self::assertGreaterThan(0, $id);
        self::assertSame([['id' => $id, 'name' => 'raid', 'ships' => '202:50,210:2']], $this->store->all((int) $user['id']));
        self::assertTrue($this->store->delete((int) $user['id'], $id));
        self::assertSame([], $this->store->all((int) $user['id']));
        self::assertFalse($this->store->delete((int) $user['id'], $id));
    }

    public function testLimitPerPlayer(): void
    {
        [$user] = $this->createPlayer();
        [$other] = $this->createPlayer();
        for ($i = 0; $i < FleetTemplateStore::MAX_PER_USER; $i++) {
            self::assertGreaterThan(0, $this->store->create((int) $user['id'], 't' . $i, '202:1', 1800000000));
        }
        self::assertSame(0, $this->store->create((int) $user['id'], 'one too many', '202:1', 1800000000));
        self::assertCount(FleetTemplateStore::MAX_PER_USER, $this->store->all((int) $user['id']));
        // the limit is per player
        self::assertGreaterThan(0, $this->store->create((int) $other['id'], 'mine', '202:1', 1800000000));
    }

    public function testCannotDeleteForeignTemplate(): void
    {
        [$user] = $this->createPlayer();
        [$other] = $this->createPlayer();
        $id = $this->store->create((int) $user['id'], 'private', '202:1', 1800000000);
        self::assertFalse($this->store->delete((int) $other['id'], $id));
        self::assertCount(1, $this->store->all((int) $user['id']));
    }
}
