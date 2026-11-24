<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniRelicsFields
{
    /**
     * Universe settings, category relics (engagement feature, off by default). The catalog itself (slots, stats, rarities,
     * sets, caps) is edited on the admin page Relics.
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        $rarities = [
            '0' => $LNG['rl_adm_rarity_0'],
            '1' => $LNG['rl_adm_rarity_1'],
            '2' => $LNG['rl_adm_rarity_2'],
            '3' => $LNG['rl_adm_rarity_3'],
        ];

        return [
            'relics' => [
                'relics_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'relics_drop_permille' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 1000,
                    'unit' => '‰',
                    'default' => 20,
                ],
                'relics_inventory_max' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 500,
                    'default' => 60,
                ],
                'relics_move_cooldown_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 6,
                ],
                'relics_power_percent' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 500,
                    'unit' => '%',
                    'default' => 100,
                ],
                'relics_log_days' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 3650,
                    'default' => 90,
                ],
                'relics_pity' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 1000,
                    'default' => 40,
                ],
            ] + $this->sources($rarities),
        ];
    }

    /**
     * The extra relic sources (mothership kill, story, season winners) of the category relics.
     *
     * @param array<string, string> $rarities rarity select options
     * @return array<string, array<string, mixed>>
     */
    private function sources(array $rarities): array
    {
        return [
            'relics_src_mothership' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'relics_src_mothership_rarity' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => $rarities,
                'default' => 2,
            ],
            'relics_src_mothership_chance' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 0,
                'max' => 100,
                'unit' => '%',
                'default' => 100,
            ],
            'relics_src_story' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'relics_src_story_rarity' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => $rarities,
                'default' => 0,
            ],
            'relics_src_story_chance' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 0,
                'max' => 100,
                'unit' => '%',
                'default' => 50,
            ],
            'relics_src_season' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'relics_src_season_rarity' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => $rarities,
                'default' => 3,
            ],
        ];
    }
}
