<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\CombatReportValue;
use PHPUnit\Framework\TestCase;

/**
 * Pins the combat report value maths: losses in resources per player and side, attacker profit, simulator boost.
 */
final class CombatReportValueTest extends TestCase
{
    private function value(): CombatReportValue
    {
        return new CombatReportValue([202 => [2000, 2000, 0], 401 => [2000, 0, 0]]);
    }

    public function testLossValuesLostUnits(): void
    {
        $loss = $this->value()->loss([202 => 10, 401 => 5], [202 => 4, 401 => 5]);
        self::assertSame(6, $loss['units']);
        self::assertSame(12000.0, $loss['metal']);
        self::assertSame(12000.0, $loss['crystal']);
        self::assertSame(24000.0, $loss['total']);
    }

    public function testSideLossesMergesFleetsOfOnePlayer(): void
    {
        $fleets = [
            'attacker' => [
                ['name' => 'a', 'start' => [202 => 2], 'end' => [202 => 1]],
                ['name' => 'a', 'start' => [202 => 2], 'end' => [202 => 0]],
                ['name' => 'b', 'start' => [202 => 1], 'end' => [202 => 1]],
            ],
            'defender' => [],
        ];
        $out = $this->value()->sideLosses($fleets);
        self::assertSame(3, $out['attacker']['players']['a']['units']);
        self::assertSame(0, $out['attacker']['players']['b']['units']);
        self::assertSame(12000.0, $out['attacker']['total']['total']);
        self::assertSame([], $out['defender']['players']);
    }

    public function testProfitAndResourceValue(): void
    {
        self::assertSame(600.0, CombatReportValue::resourceValue([901 => 100, 902 => 200, 903 => 300, 911 => 999]));
        self::assertSame(900.0, CombatReportValue::attackerProfit([901 => 500], [901 => 300, 902 => 200], 100.0));
        self::assertSame(850.0, CombatReportValue::attackerProfit([901 => 500], [901 => 300, 902 => 200], 100.0, 50.0));
    }

    public function testFleetsFromRust(): void
    {
        $report = ['rounds' => [
            ['attackers' => [0 => ['player' => ['username' => 'x']]], 'defenders' => [], 'attacker' => ['Roundstats' => [0 => [202 => ['amount' => 5]]]], 'defender' => ['Roundstats' => []]],
            ['attacker' => ['Roundstats' => [0 => [202 => ['amount' => 3]]]], 'defender' => ['Roundstats' => []]],
        ]];
        $f = CombatReportValue::fleetsFromRust($report);
        self::assertSame([['name' => 'x', 'start' => [202 => 5], 'end' => [202 => 3]]], $f['attacker']);
    }

    public function testFleetsFromClassic(): void
    {
        $report = ['players' => [7 => ['name' => 'y']], 'rounds' => [
            ['attacker' => [['userID' => 7, 'ships' => [202 => [5, 1, 1, 1]]]], 'defender' => []],
            ['attacker' => [['userID' => 7, 'ships' => []]], 'defender' => []],
        ]];
        $f = CombatReportValue::fleetsFromClassic($report);
        self::assertSame([['name' => 'y', 'start' => [202 => 5], 'end' => []]], $f['attacker']);
    }

    public function testBoostAttackersOnlyRaisesAttackerShips(): void
    {
        $in = [0 => [0 => [202 => 10, 109 => 5], 1 => [202 => 10]]];
        $out = CombatReportValue::boostAttackers($in, [202], 25);
        self::assertSame(13, $out[0][0][202]);
        self::assertSame(5, $out[0][0][109]);
        self::assertSame(10, $out[0][1][202]);
        self::assertSame($in, CombatReportValue::boostAttackers($in, [202], 0));
    }
}
