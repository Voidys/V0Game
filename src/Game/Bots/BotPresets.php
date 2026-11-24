<?php

declare(strict_types=1);

namespace App\Game\Bots;

/**
 * Difficulty presets of the bots (universe setting `bots_preset`). A preset is a bundle of values for the gameplay knobs of
 * BotSettings; `normal` equals the defaults, so a universe that never touched the setting plays as before. Raids on/off
 * (`bots_raids_enabled`), the capacity and social settings are not part of a preset. The behaviour switches (revenge, bot ACS, recycling,
 * moons, missiles, warmonger) are off in `relaxed` and `normal`; `hard` turns on the first four, `brutal` all six. Every preset keeps the anti-frustration
 * limits: a cap of raids per target and day (brutal raises it moderately), a cooldown per target, a cap of bot raids per human player and day over all bots together (`raidsPerHumanDay`, 1-6) with a shared cooldown and probe cap, and bots never hit players
 * under noob protection or in vacation mode (rules in the target finder, not configurable).
 */
final class BotPresets
{
    public const CUSTOM = 'custom';
    public const RELAXED = 'relaxed';
    public const NORMAL = 'normal';
    public const HARD = 'hard';
    public const BRUTAL = 'brutal';

    /** Preset names in the order the admin shows them. */
    public const NAMES = [self::CUSTOM, self::RELAXED, self::NORMAL, self::HARD, self::BRUTAL];

    /**
     * Knob values of each preset by BotSettings property name. `raidsPerTargetDay` 0 = the profile's own cap (2-4 attacks per
     * target and day depending on the profile); `maxFleets` 0 = no limit.
     */
    public const VALUES = [
        self::RELAXED => [
            'aggression' => 25, 'attackActives' => false, 'raidsPerTargetDay' => 1, 'targetCooldownHours' => 6, 'probesPerTargetDay' => 4,
            'minLoot' => 6000, 'simulationRuns' => 3, 'shipWeightPercent' => 60, 'defenseWeightPercent' => 100, 'growthSpeedPercent' => 100,
            'growthBonus' => 0, 'activityPercent' => 80, 'hiddenRhythmPercent' => 0, 'maxFleets' => 5, 'minTargetRatioPercent' => 0,
            'threatWindowHours' => 3,
            'raidsPerHumanDay' => 1, 'humanCooldownHours' => 3, 'probesPerHumanDay' => 6,
            'revenge' => false, 'botAcs' => false, 'recycling' => false, 'moons' => false, 'missiles' => false, 'warmonger' => false,
        ],
        self::NORMAL => [
            'aggression' => 50, 'attackActives' => true, 'raidsPerTargetDay' => 0, 'targetCooldownHours' => 2, 'probesPerTargetDay' => 0,
            'minLoot' => 3000, 'simulationRuns' => 2, 'shipWeightPercent' => 100, 'defenseWeightPercent' => 100, 'growthSpeedPercent' => 100,
            'growthBonus' => 0, 'activityPercent' => 100, 'hiddenRhythmPercent' => 0, 'maxFleets' => 0, 'minTargetRatioPercent' => 0,
            'threatWindowHours' => 3,
            'raidsPerHumanDay' => 3, 'humanCooldownHours' => 1, 'probesPerHumanDay' => 12,
            'revenge' => false, 'botAcs' => false, 'recycling' => false, 'moons' => false, 'missiles' => false, 'warmonger' => false,
        ],
        self::HARD => [
            'aggression' => 70, 'attackActives' => true, 'raidsPerTargetDay' => 3, 'targetCooldownHours' => 2, 'probesPerTargetDay' => 0,
            'minLoot' => 3000, 'simulationRuns' => 2, 'shipWeightPercent' => 150, 'defenseWeightPercent' => 150, 'growthSpeedPercent' => 150,
            'growthBonus' => 5, 'activityPercent' => 110, 'hiddenRhythmPercent' => 20, 'maxFleets' => 0, 'minTargetRatioPercent' => 0,
            'threatWindowHours' => 4,
            'raidsPerHumanDay' => 4, 'humanCooldownHours' => 1, 'probesPerHumanDay' => 16,
            'revenge' => true, 'botAcs' => true, 'recycling' => true, 'moons' => true, 'missiles' => false, 'warmonger' => false,
        ],
        self::BRUTAL => [
            'aggression' => 90, 'attackActives' => true, 'raidsPerTargetDay' => 5, 'targetCooldownHours' => 1, 'probesPerTargetDay' => 0,
            'minLoot' => 2000, 'simulationRuns' => 2, 'shipWeightPercent' => 250, 'defenseWeightPercent' => 250, 'growthSpeedPercent' => 200,
            'growthBonus' => 10, 'activityPercent' => 120, 'hiddenRhythmPercent' => 40, 'maxFleets' => 0, 'minTargetRatioPercent' => 0,
            'threatWindowHours' => 6,
            'raidsPerHumanDay' => 6, 'humanCooldownHours' => 1, 'probesPerHumanDay' => 24,
            'revenge' => true, 'botAcs' => true, 'recycling' => true, 'moons' => true, 'missiles' => true, 'warmonger' => true,
        ],
    ];

    /** Whether the name is a preset that fills knobs (`custom` and unknown names are not). */
    public static function isPreset(string $name): bool
    {
        return isset(self::VALUES[$name]);
    }

    /**
     * Knob values of a preset by property name; empty for `custom` and unknown names.
     *
     * @return array<string, int|bool>
     */
    public static function values(string $name): array
    {
        return self::VALUES[$name] ?? [];
    }

    /**
     * What saving the universe settings does with the preset: choosing another preset overwrites the preset's knobs in
     * `$after`; saving with the same preset but a changed preset knob switches the preset to `custom`.
     *
     * @param array<string, mixed> $before config values before the save (by config key)
     * @param array<string, mixed> $after posted and processed values (by config key), the result is returned
     * @return array<string, mixed> `$after` with the preset applied
     */
    public static function resolveOnSave(array $before, array $after): array
    {
        $new = (string) ($after['bots_preset'] ?? '');
        if ($new === '') {
            return $after;
        }
        $old = (string) ($before['bots_preset'] ?? self::NORMAL);
        if ($new !== $old && self::isPreset($new)) {
            foreach (self::values($new) as $property => $value) {
                $after[BotSettings::KNOBS[$property][0]] = is_bool($value) ? (int) $value : $value;
            }

            return $after;
        }
        if ($new === $old && self::isPreset($new)) {
            foreach (self::values($new) as $property => $value) {
                $key = BotSettings::KNOBS[$property][0];
                if (array_key_exists($key, $after) && (int) $after[$key] !== (int) $value) {
                    $after['bots_preset'] = self::CUSTOM;

                    break;
                }
            }
        }

        return $after;
    }
}
