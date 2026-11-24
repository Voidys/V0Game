<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Relics;

use App\Game\Engagement\Relics\RelicCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Catalog validation: the defaults are valid, everything is clamped, unknown entries are dropped, a missing section
 * falls back to the default and unusable catalogs are refused with an error key.
 */
final class RelicCatalogTest extends TestCase
{
    public function testDefaultsPassValidationUnchanged(): void
    {
        [$config, $errors] = RelicCatalog::sanitize(RelicCatalog::defaults());
        $this->assertSame([], $errors);
        $this->assertEquals(RelicCatalog::defaults(), $config);
    }

    public function testEmptyInputGivesTheDefaults(): void
    {
        [$config, $errors] = RelicCatalog::sanitize([]);
        $this->assertSame([], $errors);
        $this->assertEquals(RelicCatalog::defaults(), $config);
    }

    public function testDefaultsFollowThePlan(): void
    {
        $defaults = RelicCatalog::defaults();
        $this->assertSame(4.0, $defaults['slots'][1]['metal']);
        $this->assertSame([62, 27, 9, 2], array_column($defaults['rarities'], 'weight'));
        $this->assertSame([1, 3, 9, 27], array_column($defaults['rarities'], 'dust'));
        $this->assertSame([5, 10, 20, 40], array_column($defaults['rarities'], 'reforge_dust'));
        $this->assertCount(6, $defaults['sets']);
        $this->assertSame(12.0, $defaults['caps']['metal']);
    }

    public function testNegativeAndOversizedValuesAreClamped(): void
    {
        $raw = RelicCatalog::defaults();
        $raw['slots'][1]['metal'] = 99999;
        $raw['slots'][1]['crystal'] = -5;
        $raw['subs']['metal']['max'] = -3;
        $raw['rarities'][0]['weight'] = 999999;
        $raw['rarities'][0]['subs'] = 50;
        $raw['rarities'][0]['dust'] = -1;
        $raw['caps']['metal'] = 1e9;
        $raw['caps']['energy'] = 'abc';
        $raw['reforge_base']['metal'] = -1;
        [$config, $errors] = RelicCatalog::sanitize($raw);
        $this->assertSame([], $errors);
        $this->assertSame(100.0, $config['slots'][1]['metal']);
        $this->assertArrayNotHasKey('crystal', $config['slots'][1], 'a main stat of 0 or less is disabled');
        $this->assertSame(0.0, $config['subs']['metal']['max']);
        $this->assertSame(10000, $config['rarities'][0]['weight']);
        $this->assertSame(6, $config['rarities'][0]['subs']);
        $this->assertSame(0, $config['rarities'][0]['dust']);
        $this->assertSame(100.0, $config['caps']['metal']);
        $this->assertSame(10.0, $config['caps']['energy'], 'non numeric: default');
        $this->assertSame(0, $config['reforge_base']['metal']);
    }

    public function testUnknownStatsAndSlotsAreDropped(): void
    {
        $raw = ['slots' => [1 => ['metal' => 3, 'bogus' => 5]], 'subs' => ['metal' => ['max' => 1, 'slots' => [1, 9, 'x']]]];
        [$config] = RelicCatalog::sanitize($raw);
        $this->assertSame(['metal' => 3.0], $config['slots'][1]);
        $this->assertSame([1], $config['subs']['metal']['slots']);
        $this->assertSame(RelicCatalog::defaults()['slots'][2], $config['slots'][2], 'a missing slot keeps its default');
    }

    public function testCommaDecimalsAreRead(): void
    {
        [$config] = RelicCatalog::sanitize(['caps' => ['metal' => '7,5']]);
        $this->assertSame(7.5, $config['caps']['metal']);
    }

    public function testBandWithMinAboveMaxIsSwappedAndReported(): void
    {
        $raw = RelicCatalog::defaults();
        $raw['rarities'][1]['min'] = 70;
        $raw['rarities'][1]['max'] = 30;
        [$config, $errors] = RelicCatalog::sanitize($raw);
        $this->assertContains('rl_err_band', $errors);
        $this->assertSame([30, 70], [$config['rarities'][1]['min'], $config['rarities'][1]['max']]);
    }

    public function testWithoutAnyWeightEnabledSetOrMainStatTheCatalogIsRefused(): void
    {
        $raw = RelicCatalog::defaults();
        foreach ($raw['rarities'] as &$rarity) {
            $rarity['weight'] = 0;
        }
        $this->assertContains('rl_err_weights', RelicCatalog::sanitize($raw)[1]);

        $raw = RelicCatalog::defaults();
        foreach ($raw['sets'] as &$set) {
            $set['enabled'] = false;
        }
        $this->assertContains('rl_err_sets', RelicCatalog::sanitize($raw)[1]);

        $raw = RelicCatalog::defaults();
        $raw['slots'] = [1 => [], 2 => [], 3 => [], 4 => []];
        $this->assertContains('rl_err_slots', RelicCatalog::sanitize($raw)[1]);
    }

    public function testSetCodesAndBonusLinesAreValidated(): void
    {
        $raw = RelicCatalog::defaults();
        $raw['sets']['Bad Code!'] = ['enabled' => true, 'name' => 'x', 'b2' => [], 'b4' => []];
        $raw['sets']['custom'] = [
            'enabled' => '1', 'name' => 'Not a key', 'b2' => [['stat' => 'metal', 'value' => 1.5], ['stat' => 'nope', 'value' => 2], ['stat' => 'fuel', 'value' => 0]], 'b4' => 'junk',
        ];
        [$config, $errors] = RelicCatalog::sanitize($raw);
        $this->assertContains('rl_err_code', $errors);
        $this->assertArrayNotHasKey('Bad Code!', $config['sets']);
        $this->assertSame('rl_set_custom', $config['sets']['custom']['name'], 'invalid name key: default key');
        $this->assertSame([['stat' => 'metal', 'value' => 1.5]], $config['sets']['custom']['b2']);
        $this->assertSame([], $config['sets']['custom']['b4']);
        $this->assertTrue($config['sets']['custom']['enabled']);
    }

    public function testFormatValueSignsReductions(): void
    {
        $this->assertSame('+3.25 %', RelicCatalog::formatValue('metal', 3.25));
        $this->assertSame('-0.37 %', RelicCatalog::formatValue('build_time', 0.37));
        $this->assertSame('-2.00 %', RelicCatalog::formatValue('fuel', 2.0));
    }
}
