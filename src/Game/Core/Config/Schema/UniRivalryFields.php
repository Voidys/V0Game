<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniRivalryFields
{
    /**
     * Universe settings, categories bounties, alliance_wars, battle_feed (engagement features, all off by default).
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'bounties' => [
                'bounties_enabled' => self::flag(),
                'bounty_fee_percent' => self::number(15, 0, 50),
                'bounty_min_total' => self::number(100000, 1),
                'bounty_max_open' => self::number(3, 1, 50),
                'bounty_cooldown_hours' => self::number(24, 0, 720),
                'bounty_duration_days' => self::number(7, 1, 14),
                'bounty_threshold_percent' => self::number(10, 0, 100),
                'bounty_value_ratio' => [
                    'type' => ConfigFieldSchema::TYPE_FLOAT,
                    'transform' => 'float',
                    'min' => 1,
                    'max' => 5,
                    'default' => 1.0,
                ],
                'bounty_min_target_points' => self::number(5000, 0),
            ],
            'alliance_wars' => [
                'alliance_wars_enabled' => self::flag(),
                'war_max_days' => self::number(0, 0, 365),
                'war_min_fight_value' => self::number(20000, 0),
            ],
            'battle_feed' => [
                'battle_feed_enabled' => self::flag(),
                'battle_feed_min_units' => self::number(1000000, 1),
                'battle_feed_show_coords' => self::flag(),
                'battle_feed_max_per_hour' => self::number(6, 1, 60),
            ],
        ];
    }

    /** @return array<string, mixed> a checkbox, off by default */
    private static function flag(): array
    {
        return [
            'type' => ConfigFieldSchema::TYPE_CHECKBOX,
            'transform' => 'bool',
            'default' => false,
        ];
    }

    /** @return array<string, mixed> an integer field */
    private static function number(int $default, int $min, ?int $max = null): array
    {
        return array_filter([
            'type' => ConfigFieldSchema::TYPE_NUMBER,
            'transform' => 'int',
            'min' => $min,
            'max' => $max,
            'default' => $default,
        ], static fn($value) => $value !== null);
    }
}
