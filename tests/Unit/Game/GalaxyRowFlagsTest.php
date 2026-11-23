<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\GalaxyRowFlags;
use PHPUnit\Framework\TestCase;

/** Filter flags and recycler count of a galaxy row. */
final class GalaxyRowFlagsTest extends TestCase
{
    /** @param list<string> $classes */
    private function row(array $classes = [], array $extra = []): array
    {
        return $extra + [
            'destroyed' => false,
            'ownPlanet' => false,
            'user' => ['id' => 5, 'class' => $classes],
            'moon' => false,
            'debris' => false,
            'wreck' => false,
        ];
    }

    public function testPlainActivePlayerIsInRange(): void
    {
        $flags = GalaxyRowFlags::compute($this->row(), 20000);
        self::assertTrue($flags['inRange']);
        self::assertFalse($flags['inactive']);
        self::assertSame(0, $flags['recyclers']);
    }

    public function testProtectionClassesLeaveTheRange(): void
    {
        foreach (['noob', 'strong', 'vacation', 'banned'] as $class) {
            self::assertFalse(GalaxyRowFlags::compute($this->row([$class]), 20000)['inRange'], $class);
        }
        self::assertFalse(GalaxyRowFlags::compute($this->row([], ['ownPlanet' => true]), 20000)['inRange']);
    }

    public function testInactiveAndVacation(): void
    {
        self::assertTrue(GalaxyRowFlags::compute($this->row(['inactive']), 1)['inactive']);
        self::assertTrue(GalaxyRowFlags::compute($this->row(['inactive', 'longinactive']), 1)['inactive']);
        self::assertTrue(GalaxyRowFlags::compute($this->row(['vacation']), 1)['vacation']);
    }

    public function testDebrisMoonWreckAndRecyclers(): void
    {
        $flags = GalaxyRowFlags::compute($this->row([], [
            'moon' => ['id' => 1],
            'wreck' => true,
            'debris' => ['metal' => 30000, 'crystal' => 10001],
        ]), 20000);
        self::assertSame(40001, $flags['debris']);
        self::assertTrue($flags['moon']);
        self::assertTrue($flags['wreck']);
        self::assertSame(3, $flags['recyclers']);
    }

    public function testDestroyedPlanetOnlyKeepsDebris(): void
    {
        $flags = GalaxyRowFlags::compute(['destroyed' => true, 'debris' => ['metal' => 100, 'crystal' => 0]], 50);
        self::assertSame(100, $flags['debris']);
        self::assertSame(2, $flags['recyclers']);
        self::assertFalse($flags['inRange']);
        self::assertFalse($flags['moon']);
    }

    public function testUnknownCapacityCountsNoRecyclers(): void
    {
        self::assertSame(0, GalaxyRowFlags::recyclersNeeded(1000, 0));
    }
}
