<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\SpyReportAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Pins the pure spy report calculations (loot rules, cargos needed, unit values, activity state, "woke up" marker).
 *
 * Why: the farm list and the report tools must agree with what a raid really takes (BattleHandler::fillCapacity).
 */
final class SpyReportAnalysisTest extends TestCase
{
    public function testResourcesReadsClass900AndDefaultsToZero(): void
    {
        $this->assertSame(
            ['metal' => 10.0, 'crystal' => 0.0, 'deuterium' => 5.0],
            SpyReportAnalysis::resources([900 => [901 => '10', 903 => 5]]),
        );
        $this->assertSame(['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0], SpyReportAnalysis::resources([]));
    }

    public function testUnlimitedCapacityTakesHalfOfEachStock(): void
    {
        $loot = SpyReportAnalysis::loot(['metal' => 1000.0, 'crystal' => 600.0, 'deuterium' => 201.0]);
        $this->assertSame(['metal' => 500.0, 'crystal' => 300.0, 'deuterium' => 100.0], $loot);
    }

    public function testLimitedCapacityFollowsTheOneThirdRule(): void
    {
        // capacity 900: metal min(300, 5000) = 300, crystal min(300, 5000) = 300, deuterium 300, nothing left
        $loot = SpyReportAnalysis::loot(['metal' => 10000.0, 'crystal' => 10000.0, 'deuterium' => 10000.0], 900.0);
        $this->assertSame(['metal' => 300.0, 'crystal' => 300.0, 'deuterium' => 300.0], $loot);
        $this->assertSame(900.0, SpyReportAnalysis::total($loot));
    }

    public function testLeftoverCapacityRefillsMetalThenCrystal(): void
    {
        // no deuterium: step 1 metal 300, step 2 crystal min(300, 5000) = 300, rest 300 -> metal +150, crystal +150
        $loot = SpyReportAnalysis::loot(['metal' => 10000.0, 'crystal' => 10000.0, 'deuterium' => 0.0], 900.0);
        $this->assertSame(['metal' => 450.0, 'crystal' => 450.0, 'deuterium' => 0.0], $loot);
    }

    public function testMinCapacityCarriesTheFullLootAndNotMore(): void
    {
        $res = ['metal' => 1000.0, 'crystal' => 600.0, 'deuterium' => 200.0];
        $full = SpyReportAnalysis::total(SpyReportAnalysis::loot($res));
        $min = SpyReportAnalysis::minCapacity($res);

        $this->assertSame($full, SpyReportAnalysis::total(SpyReportAnalysis::loot($res, (float) $min)));
        $this->assertLessThan($full, SpyReportAnalysis::total(SpyReportAnalysis::loot($res, (float) ($min - 1))));
        $this->assertSame(0, SpyReportAnalysis::minCapacity(['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0]));
    }

    public function testShipsNeededRoundsUp(): void
    {
        $this->assertSame(3, SpyReportAnalysis::shipsNeeded(10001, 5000));
        $this->assertSame(0, SpyReportAnalysis::shipsNeeded(0, 5000));
        $this->assertSame(0, SpyReportAnalysis::shipsNeeded(100, 0));
    }

    public function testUnitValuesSplitFleetAndDefense(): void
    {
        $costs = [204 => [3000, 1000, 0], 401 => [2000, 0, 0], 502 => [8000, 0, 2000]];
        $value = SpyReportAnalysis::unitValues([200 => [204 => 2], 400 => [401 => 5], 500 => [502 => 9]], $costs);

        $this->assertSame(['fleet' => 8000.0, 'defense' => 10000.0], $value);
    }

    public function testInactiveStateThresholds(): void
    {
        $now = 10_000_000;
        $this->assertSame('', SpyReportAnalysis::inactiveState($now - 100, $now, 604800, 2419200));
        $this->assertSame('i', SpyReportAnalysis::inactiveState($now - 604801, $now, 604800, 2419200));
        $this->assertSame('I', SpyReportAnalysis::inactiveState($now - 2419201, $now, 604800, 2419200));
    }

    public function testWokeUpNeedsInactiveReportAndActivitySince(): void
    {
        $now = 10_000_000;
        $this->assertTrue(SpyReportAnalysis::wokeUp(true, $now - 5000, $now - 100, $now, 604800));
        $this->assertFalse(SpyReportAnalysis::wokeUp(false, $now - 5000, $now - 100, $now, 604800));
        $this->assertFalse(SpyReportAnalysis::wokeUp(null, $now - 5000, $now - 100, $now, 604800));
        $this->assertFalse(SpyReportAnalysis::wokeUp(true, $now - 5000, $now - 6000, $now, 604800), 'not online since the report');
        $this->assertFalse(SpyReportAnalysis::wokeUp(true, $now - 1_000_000, $now - 900_000, $now, 604800), 'online since, but inactive again');
    }
}
