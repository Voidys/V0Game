<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\ResourceCollector;
use PHPUnit\Framework\TestCase;

/** The cargo split of the resource collection: large cargos first, small for the rest, cargo plus fuel fit the ships. */
final class ResourceCollectorTest extends TestCase
{
    private const CAP = [203 => 25000, 202 => 5000];

    /** fuel: 100 per ship */
    private static function fuel(array $ships): float
    {
        return 100.0 * array_sum($ships);
    }

    public function testLargeCargosFirstThenSmallForTheRest(): void
    {
        $plan = ResourceCollector::plan([901 => 60000, 902 => 0, 903 => 1000], [203 => 10, 202 => 10], self::CAP, [901 => true, 902 => true, 903 => true], 0, self::fuel(...));

        $this->assertNotNull($plan);
        $this->assertSame([203 => 2, 202 => 3], $plan['ships'], 'small cargos carry the remainder');
        $this->assertSame(65000, $plan['capacity']);
        $this->assertSame(500.0, $plan['fuel']);
        $this->assertSame([60000, 0, 500], array_values($plan['cargo']), 'the deuterium cargo is what is left after the fuel');
        $this->assertGreaterThanOrEqual(array_sum($plan['cargo']) + $plan['fuel'], $plan['capacity']);
    }

    public function testAWholeLargeCargoIsUsedInsteadOfManySmallOnes(): void
    {
        $plan = ResourceCollector::plan([901 => 22000, 902 => 0, 903 => 0], [203 => 3, 202 => 20], self::CAP, [901 => true], 0, static fn(array $s): float => 0.0);

        $this->assertSame([203 => 1], $plan['ships']);
        $this->assertSame(22000, $plan['cargo'][901]);
    }

    public function testCargoIsTrimmedWhenTheShipsAreNotEnough(): void
    {
        $plan = ResourceCollector::plan([901 => 100000, 902 => 100000, 903 => 100000], [203 => 1, 202 => 1], self::CAP, [901 => true, 902 => true, 903 => true], 0, self::fuel(...));

        $this->assertSame([203 => 1, 202 => 1], $plan['ships']);
        $this->assertSame(30000 - 200, array_sum($plan['cargo']), 'capacity minus fuel');
        $this->assertSame([29800, 0, 0], array_values($plan['cargo']), 'deuterium is cut first, then crystal');
    }

    public function testDeuteriumIsKeptAndOnlyChosenResourcesAreTaken(): void
    {
        $plan = ResourceCollector::plan([901 => 9999, 902 => 5000, 903 => 3000], [203 => 5], self::CAP, [901 => false, 902 => true, 903 => true], 1000, self::fuel(...));

        $this->assertSame(0, $plan['cargo'][901]);
        $this->assertSame(5000, $plan['cargo'][902]);
        $this->assertSame(3000 - 100 - 1000, $plan['cargo'][903], 'fuel and the reserve stay behind');
    }

    public function testNothingToSendWithoutShipsResourcesOrFuel(): void
    {
        $none = static fn(array $s): float => 0.0;
        $this->assertNull(ResourceCollector::plan([901 => 1000], [203 => 0, 202 => 0], self::CAP, [901 => true], 0, $none));
        $this->assertNull(ResourceCollector::plan([901 => 0, 902 => 0, 903 => 0], [203 => 2], self::CAP, [901 => true, 902 => true, 903 => true], 0, $none));
        $this->assertNull(ResourceCollector::plan([901 => 1000, 903 => 50], [203 => 2], self::CAP, [901 => true], 0, static fn(array $s): float => 500.0), 'the planet cannot pay the fuel');
        $this->assertNull(ResourceCollector::plan([901 => 0, 903 => 500], [203 => 2], self::CAP, [903 => true], 500, $none), 'everything is reserved');
    }
}
