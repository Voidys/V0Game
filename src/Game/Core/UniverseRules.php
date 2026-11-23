<?php

namespace App\Game\Core;

/**
 * The settings of a universe as grouped, display-ready rule rows for the rules pages ("every rule in the open").
 *
 * Only values that exist in the universe configuration are listed; a group without rows is omitted. A row is
 * `['label' => translation key, 'value' => int|float|string|bool, 'unit' => ''|'x'|'%'|'hours'|'days'|'points'|'seconds'|'bool']`
 * (translation keys live in the `ingame` domain with the prefix `ur_`). Speeds use the same 2500 = 1x scale as the game.
 * Collaborators: ConfigRegistry (settings and module switches).
 */
class UniverseRules
{
    /** module id => label key of the feature list */
    private const MODULE_LABELS = [
        MODULE_ALLIANCE => 'ur_mod_alliance',
        MODULE_MISSION_ATTACK => 'ur_mod_attack',
        MODULE_MISSION_ACS => 'ur_mod_acs',
        MODULE_MISSION_HOLD => 'ur_mod_hold',
        MODULE_MISSION_DESTROY => 'ur_mod_destroy',
        MODULE_MISSION_EXPEDITION => 'ur_mod_expedition',
        MODULE_MISSION_COLONY => 'ur_mod_colony',
        MODULE_MISSION_RECYCLE => 'ur_mod_recycle',
        MODULE_MISSION_TRANSFER => 'ur_mod_transfer',
        MODULE_MISSION_TRADE => 'ur_mod_trade',
        MODULE_MISSILEATTACK => 'ur_mod_missiles',
        MODULE_PHALANX => 'ur_mod_phalanx',
        MODULE_MARKET_TRADE => 'ur_mod_market_trade',
        MODULE_MARKET_TRANSFER => 'ur_mod_market_transfer',
        MODULE_REPAIR_DOCK => 'ur_mod_repair_dock',
        MODULE_RESOURCE_STASH => 'ur_mod_stash',
        MODULE_HONORPOINTS => 'ur_mod_honor',
        MODULE_PIRATE_BASES => 'ur_mod_pirates',
        MODULE_VMODE_KICK => 'ur_mod_vmode_kick',
        MODULE_EMPTY_BUDDY => 'ur_mod_empty_buddy',
        MODULE_SIMULATOR => 'ur_mod_simulator',
        MODULE_TUTORIAL => 'ur_mod_tutorial',
        MODULE_VOTING => 'ur_mod_voting',
        MODULE_CHAT => 'ur_mod_chat',
    ];

    public function __construct(private ConfigRegistry $configs) {}

    /**
     * All rule groups of a universe.
     *
     * @param int $universe 0 = the current universe
     * @return list<array{id: string, label: string, rows: list<array{label: string, value: int|float|string|bool, unit: string}>}>
     */
    public function groups(int $universe = 0): array
    {
        $config = $this->configs->get($universe);
        $groups = [];
        foreach ([
            'speeds' => $this->speeds($config),
            'galaxy' => $this->galaxy($config),
            'start' => $this->start($config),
            'combat' => $this->combat($config),
            'protection' => $this->protection($config),
            'vacation' => $this->vacation($config),
            'fleets' => $this->fleets($config),
            'expedition' => $this->expedition($config),
            'market' => $this->market($config),
            'features' => $this->features($config),
        ] as $id => $rows) {
            if ($rows !== []) {
                $groups[] = ['id' => $id, 'label' => 'ur_group_' . $id, 'rows' => $rows];
            }
        }

        return $groups;
    }

    /**
     * Rows for the given keys: key => [label, unit, converter]; keys the config lacks are skipped.
     *
     * @param array<string, array{0: string, 1: string, 2?: callable}> $map
     * @return list<array{label: string, value: int|float|string|bool, unit: string}>
     */
    private function rows(Config $config, array $map): array
    {
        $rows = [];
        foreach ($map as $key => $def) {
            if (!isset($config->$key)) {
                continue;
            }
            $value = $config->$key;
            if (isset($def[2])) {
                $value = $def[2]($value);
            }
            $rows[] = ['label' => $def[0], 'value' => $value, 'unit' => $def[1]];
        }

        return $rows;
    }

    private static function speed(mixed $value): float
    {
        return round((float) $value / 2500, 2);
    }

    private static function hours(mixed $value): float
    {
        return round((float) $value / 3600, 1);
    }

    private static function flag(mixed $value): bool
    {
        return (bool) (int) $value;
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function speeds(Config $config): array
    {
        return $this->rows($config, [
            'resource_multiplier' => ['ur_speed_resources', 'x', self::speed(...)],
            'fleet_speed' => ['ur_speed_fleet', 'x', self::speed(...)],
            'building_speed' => ['ur_speed_building', 'x', self::speed(...)],
            'research_speed' => ['ur_speed_research', 'x', self::speed(...)],
            'shipyard_speed' => ['ur_speed_shipyard', 'x', self::speed(...)],
            'storage_multiplier' => ['ur_storage_factor', 'x'],
            'energy_multiplier' => ['ur_energy_factor', 'x'],
            'max_overflow' => ['ur_overflow', 'x'],
        ]);
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function galaxy(Config $config): array
    {
        $rows = $this->rows($config, [
            'max_galaxy' => ['ur_galaxies', ''],
            'max_system' => ['ur_systems', ''],
            'max_planets' => ['ur_positions', ''],
        ]);
        $shapes = [0 => 'ur_shape_line', 1 => 'ur_shape_ring', 2 => 'ur_shape_sphere'];
        foreach (['uni_type' => 'ur_shape_galaxies', 'galaxy_type' => 'ur_shape_systems'] as $key => $label) {
            if (isset($config->$key, $shapes[(int) $config->$key])) {
                $rows[] = ['label' => $label, 'value' => $shapes[(int) $config->$key], 'unit' => 'text'];
            }
        }

        return array_merge($rows, $this->rows($config, [
            'max_initial_planets' => ['ur_start_planets', ''],
            'planets_per_tech' => ['ur_planets_per_tech', ''],
            'max_additional_planets' => ['ur_max_colonies', ''],
        ]));
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function start(Config $config): array
    {
        return $this->rows($config, [
            'metal_start' => ['ur_start_metal', ''],
            'crystal_start' => ['ur_start_crystal', ''],
            'deuterium_start' => ['ur_start_deuterium', ''],
            'initial_fields' => ['ur_start_fields', ''],
            'metal_basic_income' => ['ur_income_metal', ''],
            'crystal_basic_income' => ['ur_income_crystal', ''],
            'deuterium_basic_income' => ['ur_income_deuterium', ''],
        ]);
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function combat(Config $config): array
    {
        $rows = $this->rows($config, [
            'fleet_debris_percentage' => ['ur_debris_fleet', '%'],
            'def_debris_percentage' => ['ur_debris_defense', '%'],
            'moon_chance' => ['ur_moon_max', '%'],
            'moon_factor' => ['ur_moon_per_100k', '%'],
            'debris_moon' => ['ur_moon_clears_debris', 'bool', self::flag(...)],
            'bash_per_user' => ['ur_bash_user', ''],
            'bash_per_planet' => ['ur_bash_planet', ''],
            'bash_count_missiles' => ['ur_bash_missiles', 'bool', self::flag(...)],
            'honor_pair_cap' => ['ur_honor_pair_cap', ''],
        ]);
        if (self::on($config, MODULE_REPAIR_DOCK)) {
            $rows = array_merge($rows, $this->rows($config, [
                'wreckfield_min_units' => ['ur_wreck_min_units', ''],
                'wreckfield_min_percent' => ['ur_wreck_percent', '%'],
                'wreckfield_cooldown' => ['ur_wreck_cooldown', 'hours', self::hours(...)],
            ]));
        }

        return $rows;
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function protection(Config $config): array
    {
        if (!isset($config->noob_protection)) {
            return [];
        }
        $rows = $this->rows($config, ['noob_protection' => ['ur_noob_on', 'bool', self::flag(...)]]);
        if (!(bool) (int) $config->noob_protection) {
            return $rows;
        }

        return array_merge($rows, $this->rows($config, [
            'noob_protection_time' => ['ur_noob_points', 'points'],
            'noob_protection_multi' => ['ur_noob_multi', 'x'],
            'noob_protection_outlaw' => ['ur_noob_outlaw', 'bool', self::flag(...)],
            'noob_inactives' => ['ur_noob_inactives', 'bool', self::flag(...)],
            'rebuild_protection' => ['ur_rebuild_protection', 'bool', self::flag(...)],
            'catch_up' => ['ur_catchup', 'bool', self::flag(...)],
            'catchup_percent' => ['ur_catchup_percent', '%'],
        ]));
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function vacation(Config $config): array
    {
        $rows = $this->rows($config, [
            'vmode_min_time' => ['ur_vmode_min', 'hours', self::hours(...)],
            'vmode_allow_incoming' => ['ur_vmode_incoming', 'bool', self::flag(...)],
        ]);
        foreach ([
            'del_user_automatic' => ['ur_inactive_delete', 'del_user_automatic_on'],
            'del_user_vacation' => ['ur_vacation_delete', 'del_user_vacation_on'],
            'del_user_banned' => ['ur_banned_delete', 'del_user_banned_on'],
        ] as $key => [$label, $switch]) {
            if (isset($config->$key, $config->$switch) && (int) $config->$switch === 1 && (int) $config->$key > 0) {
                $rows[] = ['label' => $label, 'value' => (int) $config->$key, 'unit' => 'days'];
            }
        }

        return $rows;
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function fleets(Config $config): array
    {
        return $this->rows($config, [
            'max_elements_build' => ['ur_queue_build', ''],
            'max_elements_tech' => ['ur_queue_research', ''],
            'max_elements_ships' => ['ur_queue_shipyard', ''],
            'max_fleets_per_acs' => ['ur_acs_fleets', ''],
            'max_participants_per_acs' => ['ur_acs_players', ''],
            'transport_push_check' => ['ur_push_check', 'bool', self::flag(...)],
        ]);
        return $rows;
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function expedition(Config $config): array
    {
        if (!self::on($config, MODULE_MISSION_EXPEDITION)) {
            return [];
        }

        return $this->rows($config, [
            'expo_hold_multiplier' => ['ur_expo_hold', 'x'],
            'expo_ress_met_chance' => ['ur_expo_metal', '%'],
            'expo_ress_crys_chance' => ['ur_expo_crystal', '%'],
            'expo_ress_deut_chance' => ['ur_expo_deuterium', '%'],
        ]);
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function market(Config $config): array
    {
        $rows = [];
        if (self::on($config, MODULE_MARKET_TRADE) || self::on($config, MODULE_MARKET_TRANSFER)) {
            $rows = $this->rows($config, ['market_push_tolerance' => ['ur_market_tolerance', '%']]);
        }
        if (self::on($config, MODULE_FLEET_TRADER)) {
            $rows = array_merge($rows, $this->rows($config, ['trade_charge' => ['ur_merchant_fee', '%']]));
        }

        return $rows;
    }

    /** @return list<array{label: string, value: int|float|string|bool, unit: string}> */
    private function features(Config $config): array
    {
        $rows = [];
        $modules = $config->moduls ?? [];
        foreach (self::MODULE_LABELS as $module => $label) {
            if (isset($modules[$module])) {
                $rows[] = ['label' => $label, 'value' => (int) $modules[$module] === 1, 'unit' => 'bool'];
            }
        }

        return $rows;
    }

    /** true when the module exists and is switched on */
    private static function on(Config $config, int $module): bool
    {
        $modules = $config->moduls ?? [];

        return isset($modules[$module]) && (int) $modules[$module] === 1;
    }
}
