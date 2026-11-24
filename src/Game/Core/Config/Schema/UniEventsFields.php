<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniEventsFields
{
    /**
     * Universe settings, categories events, catchup (engagement features, all off by default).
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'events' => [
                'events_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'events_discord' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'events_show_upcoming_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 720,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 24,
                ],
            ],
            'catchup' => [
                'catchup_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => false,
                ],
                'catchup_bonus_percent' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 100,
                    'unit' => '%',
                    'default' => 50,
                ],
                'catchup_days' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 60,
                    'default' => 14,
                ],
                'catchup_threshold_percent' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 1,
                    'max' => 100,
                    'unit' => '%',
                    'default' => 25,
                ],
            ],
        ];
    }
}
