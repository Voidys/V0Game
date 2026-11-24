<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Relics;

use App\Game\Engagement\Relics\RelicCatalog;
use App\Game\Engagement\Relics\RelicInventory;
use App\Game\Engagement\Relics\RelicRoller;
use PHPUnit\Framework\TestCase;

/**
 * The roller: values stay within the rarity band of the stat maximum, have 2 decimals, substats are distinct, never the
 * main stat and respect the slot lists; the rarity follows the weights and the pity minimum.
 */
final class RelicRollerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = RelicCatalog::defaults();
    }

    public function testValuesStayInsideTheBandForEveryRarityAndSlot(): void
    {
        $roller = new RelicRoller($this->config);
        foreach (array_keys(RelicCatalog::RARITIES) as $rarity) {
            $band = $this->config['rarities'][$rarity];
            foreach (array_keys(RelicCatalog::SLOTS) as $slot) {
                for ($i = 0; $i < 60; $i++) {
                    $relic = $roller->roll($rarity, $slot, 'deepcore');
                    $max = $this->config['slots'][$slot][$relic['main_stat']];
                    $this->assertGreaterThanOrEqual(round($max * $band['min'] / 100, 2), $relic['main_value']);
                    $this->assertLessThanOrEqual(round($max * $band['max'] / 100, 2), $relic['main_value']);
                    $this->assertSame(round($relic['main_value'], 2), $relic['main_value']);
                    foreach ($relic['subs'] as $sub) {
                        $subMax = $this->config['subs'][$sub['stat']]['max'];
                        $this->assertGreaterThanOrEqual(round($subMax * $band['min'] / 100, 2), $sub['value']);
                        $this->assertLessThanOrEqual(round($subMax * $band['max'] / 100, 2), $sub['value']);
                        $this->assertSame(round($sub['value'], 2), $sub['value']);
                    }
                }
            }
        }
    }

    public function testSubstatsAreDistinctNotTheMainStatAndAllowedInTheSlot(): void
    {
        $roller = new RelicRoller($this->config);
        for ($i = 0; $i < 300; $i++) {
            $slot = 1 + $i % 4;
            $relic = $roller->roll(3, $slot);
            $stats = array_column($relic['subs'], 'stat');
            $this->assertCount(4, $stats);
            $this->assertSame($stats, array_values(array_unique($stats)), 'substats are distinct');
            $this->assertNotContains($relic['main_stat'], $stats);
            foreach ($stats as $stat) {
                $this->assertContains($slot, $this->config['subs'][$stat]['slots']);
                $this->assertGreaterThan(0, $this->config['subs'][$stat]['max']);
            }
        }
    }

    public function testSubstatCountFollowsTheRarity(): void
    {
        $roller = new RelicRoller($this->config);
        foreach ([0 => 1, 1 => 2, 2 => 3, 3 => 4] as $rarity => $count) {
            $this->assertCount($count, $roller->roll($rarity, 1)['subs']);
        }
    }

    public function testMainStatComesFromTheSlotPool(): void
    {
        $roller = new RelicRoller($this->config);
        for ($i = 0; $i < 100; $i++) {
            $this->assertContains($roller->roll(0, 3)['main_stat'], ['build_time', 'shipyard_time', 'research_time']);
        }
    }

    public function testASmallPoolGivesFewerSubstatsAndNeverDuplicates(): void
    {
        foreach (RelicCatalog::STATS as $stat) {
            $this->config['subs'][$stat]['slots'] = $stat === 'metal' ? [4] : [];
        }
        $relic = (new RelicRoller($this->config))->roll(3, 4);
        $this->assertSame(['metal'], array_column($relic['subs'], 'stat'));
    }

    public function testUnusableSlotOrSetGivesNull(): void
    {
        $this->config['slots'][2] = [];
        $roller = new RelicRoller($this->config);
        $this->assertNull($roller->roll(0, 2));
        $this->assertNull($roller->roll(0, 1, 'nope'));
        $this->assertNull($roller->roll(9, 1));
        $this->assertNotContains(2, $roller->slots());
    }

    public function testInjectedRandomnessIsDeterministic(): void
    {
        $low = new RelicRoller($this->config, static fn(int $min, int $max): int => $min);
        $high = new RelicRoller($this->config, static fn(int $min, int $max): int => $max);
        $a = $low->roll(0, 1, 'deepcore');
        $b = $high->roll(0, 1, 'deepcore');
        $this->assertSame('metal', $a['main_stat']);
        $this->assertSame(1.0, $a['main_value']);  // 4.00 * 25 %
        $this->assertSame('all', $b['main_stat'], 'the highest random pick is the last main stat of the slot');
        $this->assertSame(0.8, $b['main_value']);  // 2.00 * 40 %
        $this->assertSame(0.25, $a['subs'][0]['value']);
        $this->assertSame(0.4, $b['subs'][0]['value']);
    }

    public function testRarityFollowsTheWeightsAndTheMinimum(): void
    {
        $this->assertSame(0, (new RelicRoller($this->config, static fn(int $min, int $max): int => 1))->rarity());
        $this->assertSame(3, (new RelicRoller($this->config, static fn(int $min, int $max): int => $max))->rarity());
        $this->assertSame(2, (new RelicRoller($this->config, static fn(int $min, int $max): int => 1))->rarity(2));
        $counts = array_fill(0, 4, 0);
        $roller = new RelicRoller($this->config);
        for ($i = 0; $i < 4000; $i++) {
            $counts[$roller->rarity()]++;
        }
        $this->assertGreaterThan($counts[1], $counts[0]);
        $this->assertGreaterThan($counts[3], $counts[2]);
        $this->assertGreaterThan(0, $counts[3]);
        $this->config['rarities'][0]['weight'] = 0;
        $this->assertGreaterThanOrEqual(1, (new RelicRoller($this->config))->rarity());
    }

    public function testRerollStaysInTheBand(): void
    {
        $roller = new RelicRoller($this->config);
        for ($i = 0; $i < 100; $i++) {
            $value = $roller->rerollSub('energy', 1);
            $this->assertGreaterThanOrEqual(0.6, $value);   // 1.5 * 40 %
            $this->assertLessThanOrEqual(0.9, $value);      // 1.5 * 60 %
        }
        $this->assertSame(0.0, $roller->rerollSub('fusion', 1), 'fusion is not a substat');
    }

    public function testPityRespectsRaritiesWithoutWeight(): void
    {
        $this->assertSame(2, RelicInventory::pityMinimum($this->config, 0));
        $this->assertSame(3, RelicInventory::pityMinimum($this->config, 3));
        $this->config['rarities'][2]['weight'] = 0;
        $this->assertSame(3, RelicInventory::pityMinimum($this->config, 0), 'rare cannot drop: epic is the lowest allowed');
        $this->config['rarities'][3]['weight'] = 0;
        $this->assertSame(0, RelicInventory::pityMinimum($this->config, 0), 'nothing rare or better can drop: no pity');
        $this->assertSame(1, RelicInventory::pityMinimum($this->config, 1));
    }
}
