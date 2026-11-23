<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Fleet\FleetTemplateStore;
use App\Fleet\ShipPreset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The compact ship list `202:50,210:2` of the step-1 preset link and the fleet templates. */
final class ShipPresetTest extends TestCase
{
    private const IDS = [202, 203, 204, 210];

    public function testParsesAValidList(): void
    {
        self::assertSame([202 => 50, 210 => 2], ShipPreset::parse('202:50,210:2', self::IDS));
        self::assertSame('202:50,210:2', ShipPreset::format([202 => 50, 210 => 2.0, 204 => 0]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown id' => ['999:5'];
        yield 'non fleet id (building)' => ['1:5'];
        yield 'solar satellite' => ['212:5'];
        yield 'negative count' => ['202:-5'];
        yield 'zero count' => ['202:0'];
        yield 'huge count' => ['202:99999999999'];
        yield 'above the cap' => ['202:1000000001'];
        yield 'duplicate id' => ['202:1,202:2'];
        yield 'no count' => ['202'];
        yield 'garbage' => ['202:5;DROP'];
        yield 'trailing comma' => ['202:5,'];
        yield 'float count' => ['202:1.5'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidLists(string $raw): void
    {
        self::assertNull(ShipPreset::parse($raw, ShipPreset::flyable([...self::IDS, 212])));
    }

    public function testFlyableDropsSolarSatellites(): void
    {
        self::assertSame([202, 210], ShipPreset::flyable([202, 212, 210]));
    }

    public function testTemplateNamesAreCleaned(): void
    {
        self::assertNull(FleetTemplateStore::cleanName("  \n "));
        self::assertSame('a b', FleetTemplateStore::cleanName("a\nb"));
        self::assertSame(40, mb_strlen((string) FleetTemplateStore::cleanName(str_repeat('ä', 80))));
    }
}
