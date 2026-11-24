<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniSeasonsFields
{
    /**
     * Universe settings, categories seasons, cosmetics (engagement features, all off by default).
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        $seasons = [
            'seasons_enabled' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'season_length_days' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 7,
                'max' => 90,
                'default' => 30,
            ],
        ];
        foreach (['raider', 'warrior', 'defender', 'explorer', 'recycler'] as $category) {
            $seasons['season_cat_' . $category] = [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ];
        }
        $seasons['season_badges_top'] = [
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => 1,
            'max' => 10,
            'default' => 3,
        ];
        $seasons['season_pair_full_fights'] = [
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => 1,
            'max' => 50,
            'default' => 5,
        ];
        $seasons['season_daily_full_fights'] = [
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => 0,
            'max' => 500,
            'default' => 15,
        ];
        $seasons['season_bot_factor_percent'] = [
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => 0,
            'max' => 100,
            'default' => 50,
        ];
        $seasons['season_min_fight_value'] = [
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => 0,
            'default' => 50000,
        ];

        return [
            'seasons' => $seasons,
            'cosmetics' => [
                'cosmetics_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
            ],
        ];
    }
}
