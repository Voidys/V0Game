<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniAttacksFields
{
    /**
     * Universe settings, category `attacks` (Attacks).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        // split into parts only to keep each method short; `+` keeps the field order
        return ['attacks' => $this->part1() + $this->part2()];
    }

    /**
     * Fields of category `attacks`, part 1 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part1(): array
    {
        return [
            'fleet_debris_percentage' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 30,
            ],
            'def_debris_percentage' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 0,
            ],
            'max_fleets_per_acs' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 16,
            ],
            'max_participants_per_acs' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 5,
            ],
            'noob_protection' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'noob_protection_time' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 5000,
            ],
            'noob_protection_multi' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'transform' => 'float',
                'default' => 5,
            ],
            'noob_protection_outlaw' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'noob_inactives' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
        ];
    }

    /**
     * Fields of category `attacks`, part 2 of 2 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part2(): array
    {
        return [
            'rebuild_protection' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'adm_attack' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'bash_per_user' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 0,
            ],
            'bash_per_planet' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 6,
            ],
            'bash_count_missiles' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'wreckfield_min_units' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 150000,
                'min' => 0,
            ],
            'wreckfield_min_percent' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 75,
                'unit' => '%',
                'min' => 0,
                'max' => 100,
            ],
            'wreckfield_cooldown' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 0,
                'min' => 0,
            ],
            'bash_war_exempt' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'honor_pair_cap' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 3,
                'min' => 0,
            ],
            'abuse_review_threshold' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 50,
                'min' => 1,
                'max' => 100,
            ],
        ];
    }
}
