<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicStatisticsFields
{
    /**
     * Basic server settings, category `statistics` (Statistics).
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        return [
            'statistics' => [
                'stat_settings' => [
                    'type' => ConfigFieldSchema::TYPE_NUMBER,
                    'maxlength' => 7,
                    'size' => 7,
                    'transform' => 'int',
                    'min' => 1,
                    'default' => 1000,
                ],
                'stat' => [
                    'type' => ConfigFieldSchema::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        1 => $LNG['se_stat_sel'][1], //Yes
                        2 => $LNG['se_stat_sel'][2], //Hide admins
                        0 => $LNG['se_stat_sel'][0], //No
                    ],
                    'default' => 0,
                ],
                'stat_level' => [
                    'type' => ConfigFieldSchema::TYPE_SELECT,
                    'transform' => 'int',
                    'options' => [
                        1 => $LNG['se_rank'][1], //Moderator
                        2 => $LNG['se_rank'][2], //Operator
                        3 => $LNG['se_rank'][3], //Administrator
                    ],
                    'default' => 2,
                ],
            ],
        ];
    }
}
