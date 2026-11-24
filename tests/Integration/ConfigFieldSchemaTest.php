<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;
use App\Tests\Support\GameTestCase;

/**
 * The declarative settings schema (admin "basic" and "universe" settings): pins the category order and the full
 * schema content (English labels), so that splitting the schema into one method per category cannot change a field,
 * default, option or key order. Adding or changing a setting on purpose means updating the golden values
 * (GOLDEN_CAPTURE=1).
 */
final class ConfigFieldSchemaTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'basic_categories' => ['server_parameters', 'community_links', 'discord_hooks', 'smtp', 'social', 'user_settings', 'messages', 'proofofwork', 'disclaimer', 'legal', 'statistics'],
        'basic_fields' => ['game_name', 'default_theme', 'source_url', 'version', 'timezone', 'dst', 'forum_url', 'git_issues_link', 'ttf_file', 'update_check', 'session_handler', 'redis_connection_url', 'discord_url', 'discord_widget_guild', 'wiki_url', 'donate_url', 'userscripts_url', 'discord_logs_hook', 'discord_exceptions_hook', 'discord_tickets_hook', 'mail_active', 'smtp_sendmail', 'mail_use', 'smail_path', 'smtp_ssl', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'social_discord_enabled', 'social_discord_client_id', 'social_discord_client_secret', 'social_facebook_enabled', 'social_facebook_client_id', 'social_facebook_client_secret', 'social_github_enabled', 'social_github_client_id', 'social_github_client_secret', 'social_google_enabled', 'social_google_client_id', 'social_google_client_secret', 'session_lifetime', 'remember_me_days', 'del_oldstuff', 'del_user_manually', 'user_valid', 'sendmail_inactive', 'del_user_sendmail', 'message_delete_days', 'pow_adaptive', 'pow_max_number', 'pow_elevated_factor', 'pow_high_factor', 'pow_max_cap', 'pow_sensitivity', 'pow_min_seconds', 'disclamer_address', 'disclamer_phone', 'disclamer_mail', 'disclamer_notice', 'privacy_region', 'rules_default', 'cookie_consent', 'board_enabled', 'stat_settings', 'stat', 'stat_level'],
        'basic_hash' => 'b693adac50456679e3e7bf0001ac0fb6',
        'uni_categories' => ['server_parameters', 'buildlist', 'galaxy_parameters', 'colonisation_config', 'planet_parameters', 'buildings', 'expedition_parameters', 'attacks', 'piratebase', 'chat', 'trader', 'news', 'ref', 'bots', 'bots_raids', 'bots_defense', 'bots_growth', 'bots_features'],
        'uni_fields' => ['last_galaxy_pos', 'last_system_pos', 'last_planet_pos', 'users_amount', 'uni_name', 'start', 'start_time', 'end', 'end_time', 'lang', 'catch_up', 'building_speed', 'shipyard_speed', 'research_speed', 'fleet_speed', 'resource_multiplier', 'storage_multiplier', 'max_overflow', 'expo_hold_multiplier', 'energy_multiplier', 'catchup_percent', 'missile_range_factor', 'phalanx_range_factor', 'del_user_automatic', 'del_user_automatic_on', 'del_user_vacation_on', 'del_user_vacation', 'del_user_banned_on', 'del_user_banned', 'vmode_min_time', 'alliance_create_min_points', 'vmode_allow_incoming', 'depot_cost_real_consumption', 'debug', 'uni_status', 'prepare_text', 'close_reason', 'statsjson_delay', 'max_elements_build', 'max_elements_tech', 'max_elements_ships', 'max_fleet_per_build', 'min_build_time', 'deuterium_cost_galaxy', 'max_galaxy', 'max_system', 'max_planets', 'uni_type', 'galaxy_type', 'planet_creation', 'galaxy_distance_factor', 'system_distance_factor', 'system_distance', 'planet_distance_factor', 'planet_distance', 'max_initial_planets', 'max_additional_planets', 'planets_per_tech', 'planet_size_factor', 'all_planet_pictures', 'metal_start', 'crystal_start', 'deuterium_start', 'initial_fields', 'initial_temp', 'metal_basic_income', 'crystal_basic_income', 'deuterium_basic_income', 'energy_basic_income', 'planet_ressource_bonus', 'moon_factor', 'moon_chance', 'debris_moon', 'moon_size_factor', 'cascading_moon_chance', 'factor_university', 'silo_factor', 'jumpgate_factor', 'expo_ress_met_chance', 'expo_ress_crys_chance', 'expo_ress_deut_chance', 'fleet_debris_percentage', 'def_debris_percentage', 'max_fleets_per_acs', 'max_participants_per_acs', 'noob_protection', 'noob_protection_time', 'noob_protection_multi', 'noob_protection_outlaw', 'noob_inactives', 'rebuild_protection', 'adm_attack', 'bash_per_user', 'bash_per_planet', 'bash_count_missiles', 'wreckfield_min_units', 'wreckfield_min_percent', 'wreckfield_cooldown', 'bash_war_exempt', 'honor_pair_cap', 'abuse_review_threshold', 'piratebase_min_points', 'piratebase_fleet_percentage_min', 'piratebase_fleet_percentage_max', 'piratebase_mainplanet_only', 'piratebase_ress_hours', 'chat_global_enabled', 'chat_block_links', 'chat_filter_words', 'chat_allowed_domains', 'chat_min_account_age_hours', 'chat_min_points', 'chat_global_slowmode', 'trade_allowed_ships', 'trade_charge', 'market_push_tolerance', 'transport_push_check', 'transport_push_limit', 'overview_news_frame', 'overview_news_text', 'ref_active', 'ref_bonus', 'ref_minpoints', 'ref_max_referals', 'bots_enabled', 'bots_max_count', 'bots_ticks_per_run', 'bots_preset', 'bots_max_fleets', 'bots_alliances_enabled', 'bots_join_human_alliances', 'bots_llm_enabled', 'bots_llm_daily_limit', 'bot_reply_delay_hours', 'bot_chatter', 'bots_raids_enabled', 'bots_aggression', 'bots_attack_actives', 'bots_raids_per_target_day', 'bots_target_cooldown_hours', 'bots_min_target_points', 'bots_min_target_ratio_percent', 'bots_probes_per_target_day', 'bots_probes_wanted', 'bots_min_loot', 'bots_escort_shares', 'bots_simulation_runs', 'bots_spy_cooldown_minutes', 'bots_spy_cooldown_streak_minutes', 'bots_recall_loot_percent', 'bots_recall_ratio_percent', 'bots_streak_loot_percent', 'bots_raids_per_human_day', 'bots_human_cooldown_hours', 'bots_probes_per_human_day', 'bots_scout_range_max', 'bots_respy_jitter_seconds', 'bots_fleetsave_enabled', 'bots_fleetsave_keep_percent', 'bots_fleetsave_min_offline_minutes', 'bots_threat_window_hours', 'bots_ship_weight_percent', 'bots_defense_weight_percent', 'bots_growth_speed_percent', 'bots_growth_bonus', 'bots_max_planets', 'bots_activity_percent', 'bots_hidden_rhythm_percent', 'bots_timezone_spread_hours', 'bots_revenge', 'bots_bot_acs', 'bots_recycling', 'bots_moons', 'bots_missiles', 'bots_warmonger', 'bots_warmonger_points'],
        'uni_hash' => '889d53fd30bd67ec6687415ee3b1d5b0',
    ];
    // GOLDEN-END

    private function schema(string $type): ConfigFieldSchema
    {
        $LNG = new Language($this->configs, 'en');
        $LNG->includeData(['L18N', 'INGAME', 'ADMIN', 'PUBLIC', 'CUSTOM', 'TECH']);
        $this->state->LNG = $LNG;

        return new ConfigFieldSchema($type, $this->state, $this->configs);
    }

    /**
     * Fingerprint of a schema. The start/end date defaults of the universe schema are "today" and "now" and the
     * timezone field depends on the machine: they are replaced by constants so the hash depends on neither the time
     * of the run nor the environment.
     *
     * @param array<string, array<string, array<string, mixed>>> $schema categories => fields => definition
     */
    private function fingerprint(array $schema): string
    {
        foreach ($schema as $category => $fields) {
            foreach (['start', 'start_time', 'end', 'end_time'] as $timeField) {
                if (isset($fields[$timeField]['default'])) {
                    $schema[$category][$timeField]['default'] = 'TIME-DEPENDENT';
                }
            }
            // the timezone field depends on the machine: its default is the PHP/system timezone and its options
            // come from the installed tzdata, so both are neutralised (CI runs in UTC, DDEV in Europe/Berlin)
            if (isset($fields['timezone'])) {
                $schema[$category]['timezone']['default'] = 'ENVIRONMENT-DEPENDENT';
                $schema[$category]['timezone']['options'] = 'ENVIRONMENT-DEPENDENT';
            }
        }

        return md5(serialize($schema));
    }

    public function testBasicSchema(): void
    {
        $schema = $this->schema(ConfigFieldSchema::CONFIG_TYPE_BASIC);
        $this->goldenAssert('basic_categories', array_keys($schema->getSchema()));
        $this->goldenAssert('basic_fields', array_keys($schema->getAllFields()));
        $this->goldenAssert('basic_hash', $this->fingerprint($schema->getSchema()));
    }

    public function testUniverseSchema(): void
    {
        $schema = $this->schema(ConfigFieldSchema::CONFIG_TYPE_UNI);
        $this->goldenAssert('uni_categories', array_keys($schema->getSchema()));
        $this->goldenAssert('uni_fields', array_keys($schema->getAllFields()));
        $this->goldenAssert('uni_hash', $this->fingerprint($schema->getSchema()));
    }
}
