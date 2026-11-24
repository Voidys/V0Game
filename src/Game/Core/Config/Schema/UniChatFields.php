<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniChatFields
{
    /**
     * Universe settings, category `chat` (module Chat): global channel switch and the filters of the global channel.
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'chat' => [
                'chat_global_enabled' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'chat_block_links' => [
                    'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                    'transform' => 'bool',
                    'default' => true,
                ],
                'chat_filter_words' => [
                    'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                    'cols' => 60,
                    'rows' => 6,
                    'transform' => 'string',
                    'default' => '',
                ],
                'chat_allowed_domains' => [
                    'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                    'cols' => 60,
                    'rows' => 3,
                    'transform' => 'string',
                    'default' => '',
                ],
                'chat_min_account_age_hours' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'unit' => $LNG['se_per_hour'],
                    'default' => 24,
                ],
                'chat_min_points' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'default' => 0,
                ],
                'chat_global_slowmode' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'transform' => 'int',
                    'min' => 0,
                    'max' => 3600,
                    'default' => 0,
                ],
            ],
        ];
    }
}
