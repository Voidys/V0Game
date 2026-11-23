<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\HangarOrderPlanner;
use PHPUnit\Framework\TestCase;

/** "Build up to X": the order is the target minus what is on the planet and in the queue, clamped. */
final class HangarOrderPlannerTest extends TestCase
{
    public function testSubtractsOwnedAndQueued(): void
    {
        $this->assertSame(70.0, HangarOrderPlanner::upTo(100, 20, 10));
    }

    public function testTargetReachedOrder0(): void
    {
        $this->assertSame(0.0, HangarOrderPlanner::upTo(100, 90, 10));
        $this->assertSame(0.0, HangarOrderPlanner::upTo(50, 90, 10));
    }

    public function testCapByAffordableAndLimit(): void
    {
        $this->assertSame(25.0, HangarOrderPlanner::upTo(100, 0, 0, 25));
        $this->assertSame(0.0, HangarOrderPlanner::upTo(100, 0, 0, -5));
        $this->assertSame(40.0, HangarOrderPlanner::upTo(100, 20, 40, 500));
    }

    public function testFractionalTargetIsFloored(): void
    {
        $this->assertSame(9.0, HangarOrderPlanner::upTo(10.7, 1, 0));
    }
}
