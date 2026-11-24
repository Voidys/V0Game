<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniMapContentFields
{
    /**
     * Universe settings, categories derelicts, mothership, expedition_stories (engagement features, all off by default).
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        return $this->derelicts($LNG) + $this->mothership($LNG) + $this->stories();
    }

    /**
     * Category `derelicts`.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function derelicts(Language $LNG): array
    {
        return [
            'derelicts' => [
                'derelicts_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'derelicts_per_galaxy' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 50,
                    'default' => 2,
                ],
                'derelicts_interval_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 12,
                ],
                'derelicts_min' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 200000,
                ],
                'derelicts_max' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 2000000,
                ],
                'derelicts_lifetime_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 24,
                ],
            ],
        ];
    }

    /**
     * Category `mothership`.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function mothership(Language $LNG): array
    {
        return [
            'mothership' => [
                'mothership_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'mothership_interval_days' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 365,
                    'default' => 14,
                ],
                'mothership_strength_percent' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 10,
                    'max' => 100000,
                    'unit' => '%',
                    'default' => 300,
                ],
                'mothership_loot_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 48,
                ],
                'mothership_lifetime_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 72,
                ],
                'mothership_tech_level' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'default' => 20,
                ],
                'mothership_min_players' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 20,
                    'default' => 2,
                ],
            ],
        ];
    }

    /**
     * Category `expedition_stories`.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function stories(): array
    {
        return [
            'expedition_stories' => [
                'expedition_stories_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'story_chance_permille' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 1000,
                    'default' => 5,
                ],
                'story_range_systems' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 500,
                    'default' => 20,
                ],
                'story_reward_factor' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'maxlength' => 5,
                    'size' => 5,
                    'transform' => 'float',
                    'default' => 2.0,
                ],
            ],
        ];
    }
}
