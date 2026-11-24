<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Bots\BotPresets;
use App\Game\Bots\BotSettings;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of the admin config categories of the bots (split out of ConfigFieldSchema, which merges them in order).
 * The fields come from the knob table of BotSettings (key, kind, default, range); this class only groups them into cards.
 */
final class UniBotsFields
{
    /** Categories (cards) of the bots settings and the knobs (BotSettings property names) each one shows, in order. */
    private const GROUPS = [
        'bots' => ['enabled', 'maxCount', 'ticksPerRun', 'preset', 'maxFleets', 'alliancesEnabled', 'joinHumanAlliances', 'llmEnabled', 'llmDailyLimit', 'replyDelayHours', 'chatter'],
        'bots_raids' => [
            'raidsEnabled', 'aggression', 'attackActives', 'raidsPerTargetDay', 'targetCooldownHours', 'minTargetPoints', 'minTargetRatioPercent',
            'probesPerTargetDay', 'probesWanted', 'minLoot', 'escortShares', 'simulationRuns', 'spyCooldownMinutes', 'spyCooldownStreakMinutes',
            'recallLootPercent', 'recallRatioPercent', 'streakLootPercent', 'raidsPerHumanDay', 'humanCooldownHours', 'probesPerHumanDay', 'scoutRangeMax', 'respyJitterSeconds',
        ],
        'bots_defense' => ['fleetsaveEnabled', 'fleetsaveKeepPercent', 'fleetsaveMinOfflineMinutes', 'threatWindowHours'],
        'bots_growth' => ['shipWeightPercent', 'defenseWeightPercent', 'growthSpeedPercent', 'growthBonus', 'maxPlanets', 'activityPercent', 'hiddenRhythmPercent', 'timezoneSpreadHours'],
        'bots_features' => ['revenge', 'botAcs', 'recycling', 'moons', 'missiles', 'warmonger', 'warmongerPoints'],
    ];

    /**
     * Universe settings, categories `bots`, `bots_raids`, `bots_defense`, `bots_growth` and `bots_features`.
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        $out = [];
        foreach (self::GROUPS as $category => $properties) {
            foreach ($properties as $property) {
                [$key, $kind, $default, $min, $max] = BotSettings::KNOBS[$property];
                $out[$category][$key] = match ($kind) {
                    'bool' => ['type' => ConfigFieldSchema::TYPE_CHECKBOX, 'transform' => 'bool', 'default' => $default],
                    'int' => ['type' => ConfigFieldSchema::TYPE_NUMBER, 'transform' => 'int', 'min' => $min, 'max' => $max, 'default' => $default],
                    'shares' => ['type' => ConfigFieldSchema::TYPE_TEXT, 'transform' => 'string', 'maxlength' => 60, 'default' => implode(', ', array_map(static fn(float $s): string => (string) round($s * 100), $default))],
                    default => ['type' => ConfigFieldSchema::TYPE_SELECT, 'transform' => 'string', 'options' => $this->presetOptions($LNG), 'default' => $default],
                };
            }
        }

        return $out;
    }

    /**
     * The difficulty presets as select options (name => label).
     *
     * @return array<string, string>
     */
    private function presetOptions(Language $LNG): array
    {
        $options = [];
        foreach (BotPresets::NAMES as $name) {
            $options[$name] = $LNG['se_bots_preset_' . $name];
        }

        return $options;
    }
}
