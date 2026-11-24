<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Relics;

use App\Game\Engagement\Relics\RelicBonus;
use App\Game\Engagement\Relics\RelicCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The bonus maths of the relics on one planet: relic stats, 2 and 4 piece set bonuses, the power percent and the caps.
 */
final class RelicBonusTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = RelicCatalog::defaults();
    }

    /**
     * @param list<array{stat: string, value: float}> $subs
     * @return array<string, mixed>
     */
    private function relic(string $set, string $main, float $value, array $subs = []): array
    {
        $slot = 1;
        foreach (RelicCatalog::defaults()['slots'] as $number => $mains) {
            if (isset($mains[$main])) {
                $slot = $number;
                break;
            }
        }

        return ['set_code' => $set, 'slot' => $slot, 'rarity' => 3, 'main_stat' => $main, 'main_value' => $value, 'subs' => $subs];
    }

    public function testNoRelicsNoBonus(): void
    {
        $this->assertSame(['values' => [], 'capped' => [], 'sets' => [], 'production' => []], RelicBonus::compute([], $this->config, 100.0));
    }

    public function testStatsOfOneRelicAreSummed(): void
    {
        $result = RelicBonus::compute([$this->relic('wanderer', 'metal', 2.5, [['stat' => 'metal', 'value' => 0.5], ['stat' => 'energy', 'value' => 1.0]])], $this->config, 100.0);
        $this->assertSame(['metal' => 3.0, 'energy' => 1.0], $result['values']);
    }

    public function testSubsAsJsonAreAccepted(): void
    {
        $relic = $this->relic('wanderer', 'metal', 1.0);
        $relic['subs'] = '[{"stat":"crystal","value":0.4}]';
        $this->assertSame(['metal' => 1.0, 'crystal' => 0.4], RelicBonus::compute([$relic], $this->config, 100.0)['values']);
    }

    public function testTwoAndFourPieceSetBonuses(): void
    {
        $piece = fn(string $main, float $value): array => $this->relic('deepcore', $main, $value);
        $one = RelicBonus::compute([$piece('crystal', 1.0)], $this->config, 100.0);
        $this->assertSame(['crystal' => 1.0], $one['values'], 'one piece: no set bonus');

        $two = RelicBonus::compute([$piece('crystal', 1.0), $piece('energy', 1.0)], $this->config, 100.0);
        $this->assertSame(1.5, $two['values']['metal'], '2 pieces: metal +1.5');
        $this->assertSame(1.0, $two['values']['crystal']);

        $four = RelicBonus::compute([$piece('energy', 1.0), $piece('energy', 1.0), $piece('storage', 1.0), $piece('fuel', 1.0)], $this->config, 100.0);
        $this->assertSame(4.5, $four['values']['metal'], '4 pieces add to the 2 piece bonus: 1.5 + 3');
        $this->assertSame(1.0, $four['values']['crystal']);
        $this->assertSame(['deepcore' => 4], $four['sets']);
    }

    public function testDifferentSetsDoNotCombine(): void
    {
        $result = RelicBonus::compute([$this->relic('deepcore', 'fuel', 1.0), $this->relic('prism', 'fuel', 1.0)], $this->config, 100.0);
        $this->assertSame(['fuel' => 2.0], $result['values']);
    }

    public function testDisabledSetGivesNoSetBonusButKeepsTheRelicStats(): void
    {
        $this->config['sets']['deepcore']['enabled'] = false;
        $result = RelicBonus::compute([$this->relic('deepcore', 'fuel', 1.0), $this->relic('deepcore', 'storage', 1.0)], $this->config, 100.0);
        $this->assertSame(['fuel' => 1.0, 'storage' => 1.0], $result['values']);
    }

    public function testPowerPercentScalesEveryStatIncludingSetBonuses(): void
    {
        $relics = [$this->relic('deepcore', 'crystal', 2.0), $this->relic('deepcore', 'fuel', 1.0)];
        $half = RelicBonus::compute($relics, $this->config, 50.0);
        $this->assertSame(['crystal' => 1.0, 'fuel' => 0.5, 'metal' => 0.75], $half['values']);
        $this->assertSame([], RelicBonus::compute($relics, $this->config, 0.0)['values']);
    }

    public function testCapsHoldAndAreFlagged(): void
    {
        $relics = [$this->relic('wanderer', 'build_time', 2.0), $this->relic('wanderer', 'build_time', 2.0), $this->relic('wanderer', 'build_time', 2.0), $this->relic('wanderer', 'metal', 3.0)];
        $result = RelicBonus::compute($relics, $this->config, 100.0);
        $this->assertSame(5.0, $result['values']['build_time'], 'cap 5');
        $this->assertTrue($result['capped']['build_time']);
        $this->assertFalse($result['capped']['metal']);
        $this->assertSame(3.0, $result['values']['metal']);
    }

    public function testCapAppliesAfterThePowerPercent(): void
    {
        $two = [$this->relic('wanderer', 'metal', 4.0), $this->relic('wanderer', 'metal', 4.0)];
        $result = RelicBonus::compute($two, $this->config, 200.0);
        $this->assertSame(12.0, $result['values']['metal'], '16 % cut to the cap of 12');
        $this->config['caps']['metal'] = 20.0;
        $this->assertSame(16.0, RelicBonus::compute($two, $this->config, 200.0)['values']['metal']);
    }

    public function testAllResourcesDoNotStackPastThePerResourceCap(): void
    {
        $relics = [$this->relic('wanderer', 'metal', 4.0, [['stat' => 'metal', 'value' => 1.0]]), $this->relic('wanderer', 'metal', 4.0), $this->relic('wanderer', 'metal', 4.0), $this->relic('wanderer', 'all', 2.0), $this->relic('wanderer', 'all', 2.0)];
        $result = RelicBonus::compute($relics, $this->config, 100.0);
        $this->assertSame(12.0, $result['values']['metal']);
        $this->assertSame(4.0, $result['values']['all'], 'the all cap (5) applies to the all part itself');
        $this->assertSame(12.0, $result['production']['metal'], 'metal 12 + all 4 is cut to the metal cap 12');
        $this->assertSame(4.0, $result['production']['crystal'], 'a resource without its own bonus gets the all part');
        $this->config['caps']['crystal'] = 3.0;
        $this->assertSame(3.0, RelicBonus::compute($relics, $this->config, 100.0)['production']['crystal']);
    }

    public function testLoweredMaximaNerfExistingRelics(): void
    {
        $relic = $this->relic('wanderer', 'metal', 4.0, [['stat' => 'energy', 'value' => 1.5]]);
        $this->config['slots'][1]['metal'] = 2.0;
        $this->config['subs']['energy']['max'] = 0.5;
        $result = RelicBonus::compute([$relic], $this->config, 100.0);
        $this->assertSame(['metal' => 2.0, 'energy' => 0.5], $result['values']);
        $this->config['rarities'][3]['max'] = 50;
        $this->assertSame(['metal' => 1.0, 'energy' => 0.25], RelicBonus::compute([$relic], $this->config, 100.0)['values'], 'cut to the top of the rarity band');
    }

    public function testStatsTheCatalogNoLongerAllowsGiveNothing(): void
    {
        $relic = $this->relic('deepcore', 'metal', 3.0, [['stat' => 'energy', 'value' => 1.0], ['stat' => 'storage', 'value' => 2.0]]);
        unset($this->config['slots'][1]['metal']);
        $this->config['subs']['energy']['slots'] = [2, 3, 4];
        $eff = RelicBonus::effectiveStats($relic, $this->config);
        $this->assertFalse($eff['main']['active']);
        $this->assertFalse($eff['subs'][0]['active']);
        $this->assertTrue($eff['subs'][1]['active']);
        $this->assertSame(['storage' => 2.0], RelicBonus::compute([$relic], $this->config, 100.0)['values']);
    }

    public function testRelicOfADisabledSlotGivesNothingAndCountsForNoSet(): void
    {
        $this->config['slots'][1] = [];
        $result = RelicBonus::compute([$this->relic('deepcore', 'metal', 3.0), $this->relic('deepcore', 'fuel', 1.0)], $this->config, 100.0);
        $this->assertSame(['fuel' => 1.0], $result['values'], 'one usable piece: no set bonus');
        $this->assertSame(['deepcore' => 1], $result['sets']);
    }
}
