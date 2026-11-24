<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniContractsFields
{
    /**
     * Universe settings, categories contracts, alliance_goals, returning (engagement features, all off by default).
     *
     * @return array<string, array<string, array<string, mixed>>> the categories with their field definitions
     */
    public function fields(Language $LNG): array
    {
        return ['contracts' => $this->contracts($LNG), 'alliance_goals' => $this->allianceGoals(), 'returning' => $this->returning($LNG)];
    }

    /**
     * Fields of category `contracts`.
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function contracts(Language $LNG): array
    {
        return [
            'contracts_enabled' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'contracts_count' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 1,
                'max' => 5,
                'default' => 3,
            ],
            'contracts_reward_mode' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'transform' => 'int',
                'options' => [
                    0 => $LNG['se_contracts_reward_mode_0'],
                    1 => $LNG['se_contracts_reward_mode_1'],
                ],
                'default' => 0,
            ],
            'contracts_reward_hours' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 1,
                'max' => 24,
                'default' => 2,
            ],
            'contracts_resource_cap' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 0,
                'default' => 0,
            ],
            'contracts_rerolls' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 0,
                'max' => 3,
                'default' => 1,
            ],
        ];
    }

    /**
     * Fields of category `alliance_goals`.
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function allianceGoals(): array
    {
        return [
            'alliance_goals_enabled' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'alliance_goals_min_members' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 1,
                'max' => 100,
                'default' => 3,
            ],
            'alliance_goals_min_per_member' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => 2,
            ],
        ];
    }

    /**
     * Fields of category `returning`.
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function returning(Language $LNG): array
    {
        return [
            'returning_enabled' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'returning_days' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 3,
                'max' => 365,
                'default' => 14,
            ],
            'returning_protection_hours' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 0,
                'max' => 48,
                'unit' => $LNG['se_per_hour'],
                'default' => 12,
            ],
            'returning_cooldown_days' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'min' => 1,
                'max' => 365,
                'default' => 60,
            ],
        ];
    }
}
