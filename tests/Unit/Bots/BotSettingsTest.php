<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bots;

use App\Game\Bots\BotPresets;
use App\Game\Bots\BotSettings;
use App\Game\Bots\Raid\RaidPlanner;
use App\Game\Bots\Raid\RaidTargetFinder;
use PHPUnit\Framework\TestCase;

/**
 * The bot knobs: the defaults equal the constants that were hard-coded before, the preset `normal` equals the defaults, presets and
 * per-bot overrides replace knobs, and at the defaults the profile settings of a bot come back unchanged.
 */
final class BotSettingsTest extends TestCase
{
    public function testDefaultsReproduceTheOldConstants(): void
    {
        $d = BotSettings::defaults();

        self::assertSame(RaidPlanner::MIN_LOOT, $d->minLoot);
        self::assertSame([0.0, 0.15, 0.3, 0.5, 0.75, 1.0], $d->escortShares);
        self::assertSame(2, $d->simulationRuns);
        self::assertSame(4, $d->probesWanted);
        self::assertSame(7200, $d->spyCooldownSeconds());
        self::assertSame(900, $d->spyCooldownStreakSeconds());
        self::assertSame(RaidTargetFinder::COOLDOWN, $d->targetCooldownSeconds());
        self::assertSame(10800, $d->threatWindowSeconds());
        self::assertSame(0.2, $d->fleetsaveKeep());
        self::assertSame(1200, $d->fleetsaveMinOfflineMinutes * 60);
        self::assertEquals(3000 * 0.5, $d->minLoot * $d->recallLootPercent / 100);
        self::assertEquals(3000 * 0.5, $d->minLoot * $d->streakLootPercent / 100);
        self::assertSame(50, $d->aggression);
        self::assertFalse($d->raidsEnabled);
        self::assertTrue($d->attackActives);
        self::assertTrue($d->fleetsaveEnabled);
        self::assertSame(50, $d->maxCount);
        self::assertSame(25, $d->ticksPerRun);
        self::assertTrue($d->alliancesEnabled);
        foreach (['revenge', 'botAcs', 'recycling', 'moons', 'missiles', 'warmonger'] as $switch) {
            self::assertFalse($d->$switch, $switch . ' is off by default');
        }
    }

    public function testNormalPresetEqualsTheDefaults(): void
    {
        $defaults = BotSettings::defaults();
        $normal = $defaults->withPreset(BotPresets::NORMAL);

        self::assertEquals($defaults, $normal);
        foreach (BotPresets::values(BotPresets::NORMAL) as $property => $value) {
            self::assertSame($value, $defaults->$property, $property);
        }
    }

    public function testEveryPresetCoversTheSameKnobsAndKeepsTheLimits(): void
    {
        $knobs = array_keys(BotPresets::VALUES[BotPresets::NORMAL]);
        foreach (BotPresets::VALUES as $name => $values) {
            self::assertSame($knobs, array_keys($values), $name . ' has the same knobs as normal');
            $settings = BotSettings::defaults()->withPreset($name);
            self::assertSame($name, $settings->preset);
            self::assertGreaterThanOrEqual(1, $settings->targetCooldownHours, $name . ' keeps a cooldown');
            if ($name !== BotPresets::NORMAL) {
                self::assertGreaterThanOrEqual(1, $settings->raidsPerTargetDay, $name . ' caps raids per target and day');
                self::assertLessThanOrEqual(5, $settings->raidsPerTargetDay);
            }
        }
        self::assertGreaterThan(BotPresets::VALUES[BotPresets::NORMAL]['aggression'], BotPresets::VALUES[BotPresets::HARD]['aggression']);
        self::assertGreaterThan(BotPresets::VALUES[BotPresets::HARD]['aggression'], BotPresets::VALUES[BotPresets::BRUTAL]['aggression']);
        self::assertLessThan(BotPresets::VALUES[BotPresets::NORMAL]['aggression'], BotPresets::VALUES[BotPresets::RELAXED]['aggression']);
    }

    public function testPresetKeepsRaidsAndCapacitySettings(): void
    {
        $base = BotSettings::fromValues(['raidsEnabled' => '1', 'maxCount' => '12', 'preset' => 'custom']);
        $hard = $base->withPreset(BotPresets::HARD);

        self::assertTrue($hard->raidsEnabled);
        self::assertSame(12, $hard->maxCount);
        self::assertSame(70, $hard->aggression);
        self::assertSame(BotPresets::HARD, $hard->preset);
        self::assertSame($base, $base->withPreset(BotPresets::CUSTOM), 'custom changes nothing');
    }

    public function testValuesAreClampedAndParsed(): void
    {
        $s = BotSettings::fromValues(['aggression' => '250', 'simulationRuns' => '0', 'preset' => 'nonsense', 'escortShares' => '100, 30 ,x, 30, 0']);

        self::assertSame(100, $s->aggression);
        self::assertSame(1, $s->simulationRuns);
        self::assertSame(BotPresets::CUSTOM, $s->preset);
        self::assertSame([0.0, 0.3, 1.0], $s->escortShares);
        self::assertSame('0, 30, 100', $s->escortSharesText());
        self::assertSame(BotSettings::defaults()->escortShares, BotSettings::fromValues(['escortShares' => 'abc'])->escortShares);
    }

    public function testPerBotOverrideAndProfileApplication(): void
    {
        $profile = ['raid_chance' => 40, 'min_profit_ratio' => 3.0, 'weights' => ['ships' => 10, 'defense' => 4], 'skill_gain_per_day' => 0.0, 'fleetsave_chance' => 60,
            'max_attacks_per_target_per_day' => 3, 'activity' => array_fill(0, 24, 0.5)];

        $d = BotSettings::defaults();
        $same = $d->applyToProfile($profile, 17);
        self::assertSame($profile + ['hidden_rhythm' => 0], $same, 'defaults leave the profile alone');

        $bot = $d->forBot(['preset' => 'hard', 'aggression' => 100]);
        self::assertSame(100, $bot->aggression);
        self::assertSame(150, $bot->shipWeightPercent);
        $applied = $bot->applyToProfile($profile);
        self::assertSame(80, $applied['raid_chance']);
        self::assertSame(1.5, $applied['min_profit_ratio']);
        self::assertSame(15, $applied['weights']['ships']);
        self::assertSame(6, $applied['weights']['defense']);
        self::assertSame(3, $applied['max_attacks_per_target_per_day']);
        self::assertEqualsWithDelta(0.55, $applied['activity'][0], 1e-9);
        self::assertEqualsWithDelta(0.005, $applied['skill_gain_per_day'], 1e-9);

        self::assertSame($d, $d->forBot([]));
        self::assertSame(0, $d->forBot(['aggression' => '0'])->aggression);
        self::assertSame(0, $d->withAggression(0)->applyToProfile($profile)['raid_chance']);
        self::assertSame(0, $d->fleetsaveEnabled ? 0 : 1);
        self::assertSame(0, BotSettings::fromValues(['fleetsaveEnabled' => 0])->applyToProfile($profile)['fleetsave_chance']);
    }

    public function testHiddenRhythmShareIsDeterministic(): void
    {
        $all = BotSettings::fromValues(['hiddenRhythmPercent' => 100]);
        $none = BotSettings::defaults();

        self::assertSame(33, $all->applyToProfile([], 33)['hidden_rhythm']);
        self::assertSame(0, $none->applyToProfile([], 33)['hidden_rhythm']);
        $half = BotSettings::fromValues(['hiddenRhythmPercent' => 50]);
        $hidden = 0;
        for ($id = 1; $id <= 400; $id++) {
            $hidden += $half->applyToProfile([], $id)['hidden_rhythm'] > 0 ? 1 : 0;
        }
        self::assertEqualsWithDelta(200, $hidden, 50);
    }

    public function testPresetResolutionOnSave(): void
    {
        $normalKnobs = [];
        foreach (BotPresets::values(BotPresets::NORMAL) as $property => $value) {
            $normalKnobs[BotSettings::KNOBS[$property][0]] = (int) $value;
        }

        $chosen = BotPresets::resolveOnSave(['bots_preset' => 'normal'], ['bots_preset' => 'brutal'] + $normalKnobs);
        self::assertSame(90, $chosen['bots_aggression']);
        self::assertSame('brutal', $chosen['bots_preset']);
        self::assertSame(1, $chosen['bots_attack_actives']);

        $edited = BotPresets::resolveOnSave(['bots_preset' => 'normal'], ['bots_preset' => 'normal', 'bots_aggression' => 60] + $normalKnobs);
        self::assertSame('custom', $edited['bots_preset']);
        self::assertSame(60, $edited['bots_aggression']);

        $untouched = BotPresets::resolveOnSave(['bots_preset' => 'normal'], ['bots_preset' => 'normal'] + $normalKnobs);
        self::assertSame('normal', $untouched['bots_preset']);

        $custom = BotPresets::resolveOnSave(['bots_preset' => 'hard'], ['bots_preset' => 'custom', 'bots_aggression' => 33] + $normalKnobs);
        self::assertSame(33, $custom['bots_aggression']);
        self::assertSame('custom', $custom['bots_preset']);

        $fresh = BotPresets::resolveOnSave(['bots_preset' => null], ['bots_preset' => 'normal'] + $normalKnobs);
        self::assertSame('normal', $fresh['bots_preset']);
    }
}
