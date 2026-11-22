<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class UniServerParametersFields
{
    /**
     * Universe settings, category `server_parameters` (Server Parameter).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        // split into parts only to keep each method short; `+` keeps the field order
        return ['server_parameters' => $this->part1($LNG) + $this->part2($LNG) + $this->part3($LNG) + $this->part3b($LNG) + $this->part4($LNG)];
    }

    /**
     * Fields of category `server_parameters`, part 1 of 4 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part1(Language $LNG): array
    {
        return [
            'last_galaxy_pos' => [
                'type' => ConfigFieldSchema::TYPE_NONE,
                'default' => '1',
            ],
            'last_system_pos' => [
                'type' => ConfigFieldSchema::TYPE_NONE,
                'default' => '1',
            ],
            'last_planet_pos' => [
                'type' => ConfigFieldSchema::TYPE_NONE,
                'default' => '1',
            ],
            'users_amount' => [
                'type' => ConfigFieldSchema::TYPE_NONE,
                'default' => '1',
            ],
            'uni_name' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 60,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => $LNG['fcm_universe'] . ' 1',
            ],
            'start' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 15,
                'size' => 15,
                'transform' => 'string',
                'sanitize' => true,
                'default' => date('d.m.Y'),
            ],
            'start_time' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 15,
                'size' => 15,
                'transform' => 'string',
                'sanitize' => true,
                'default' => date('H:i:s'),
            ],
            'end' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 15,
                'size' => 15,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ],
            'end_time' => [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 15,
                'size' => 15,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ],
        ];
    }

    /**
     * Fields of category `server_parameters`, part 2 of 4 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part2(Language $LNG): array
    {
        return [
            'lang' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => $LNG->getAllowedLangs(false),
                'default' => $LNG->getLanguage(),
            ],
            'catch_up' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'building_speed' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'transform' => 'int_multiply_2500',
                'min' => 0,
                'tooltip' => $LNG['se_normal_speed'],
                'default' => 2500,
            ],
            'shipyard_speed' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'transform' => 'int_multiply_2500',
                'min' => 0,
                'tooltip' => $LNG['se_normal_speed'],
                'default' => 2500,
            ],
            'research_speed' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'transform' => 'int_multiply_2500',
                'min' => 0,
                'tooltip' => $LNG['se_normal_speed'],
                'default' => 2500,
            ],
            'fleet_speed' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'transform' => 'int_multiply_2500',
                'min' => 0,
                'default' => 2500,
            ],
            'resource_multiplier' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 5,
                'transform' => 'int_multiply_2500',
                'min' => 0,
                'default' => 2500,
            ],
            'storage_multiplier' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'default' => 1,
            ],
        ];
    }

    /**
     * Fields of category `server_parameters`, part 3 of 4 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part3(Language $LNG): array
    {
        return [
            'max_overflow' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'float',
                'default' => 1.0,
            ],
            'expo_hold_multiplier' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'default' => 1,
            ],
            'energy_multiplier' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'transform' => 'int',
                'default' => 1,
            ],
            'catchup_percent' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 3,
                'transform' => 'int',
                'min' => 0,
                'max' => 100,
                'unit' => '%',
                'default' => 50,
            ],
            'missile_range_factor' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'float',
                'default' => 1.0,
            ],
            'phalanx_range_factor' => [
                'type' => ConfigFieldSchema::TYPE_FLOAT,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'float',
                'default' => 1.0,
            ],
            'del_user_automatic' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 2,
                'transform' => 'int',
                'min' => 0,
                'unit' => $LNG['se_days'],
                'default' => 90,
            ],
        ];
    }

    /**
     * Fields of category `server_parameters`, part 3b (automatic deletion groups, vacation time; see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part3b(Language $LNG): array
    {
        return [
            'del_user_automatic_on' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'del_user_vacation_on' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'del_user_vacation' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 2,
                'transform' => 'int',
                'min' => 0,
                'unit' => $LNG['se_days'],
                'default' => 0,
            ],
            'del_user_banned_on' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'del_user_banned' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 3,
                'size' => 2,
                'transform' => 'int',
                'min' => 0,
                'unit' => $LNG['se_days'],
                'default' => 0,
            ],
            'vmode_min_time' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'default' => 172800,
            ],
        ];
    }

    /**
     * Fields of category `server_parameters`, part 4 of 4 (see fields()).
     *
     * @return array<string, array<string, mixed>> field => definition
     */
    private function part4(Language $LNG): array
    {
        return [
            'alliance_create_min_points' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 20,
                'size' => 25,
                'transform' => 'int',
                'min' => 0,
                'default' => 0,
            ],
            'vmode_allow_incoming' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'depot_cost_real_consumption' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => true,
            ],
            'debug' => [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ],
            'uni_status' => [
                'type' => ConfigFieldSchema::TYPE_SELECT,
                'options' => [
                    '0' => $LNG['se_uni_status_regopen_gameopen'],
                    '1' => $LNG['se_uni_status_regclosed_gameclosed'],
                    '2' => $LNG['se_uni_status_regopen_gameclosed'],
                    '3' => $LNG['se_uni_status_regclosed_gameopen'],
                ],
                'default' => 1,
            ],
            'prepare_text' => [
                'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                'cols' => 80,
                'rows' => 5,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ],
            'close_reason' => [
                'type' => ConfigFieldSchema::TYPE_TEXTAREA,
                'cols' => 80,
                'rows' => 5,
                'transform' => 'string',
                'sanitize' => true,
                'default' => $LNG['sql_close_reason'],
            ],
            'statsjson_delay' => [
                'type' => ConfigFieldSchema::TYPE_NUMBER,
                'maxlength' => 11,
                'size' => 11,
                'transform' => 'int',
                'min' => 0,
                'default' => 604800,
            ],
        ];
    }
}
