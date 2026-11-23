<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

// included by Database::__construct() with $database = connection settings
$prefix = $database['tableprefix'];

// Data Tabells
$dbTableNames   = [
    'API_TOKENS'              => $prefix . 'api_tokens',
    'ACCOUNTS'                => $prefix . 'accounts',
    'ACCOUNTS_BANNED'         => $prefix . 'accounts_banned',
    'ACCOUNTS_SOCIAL'         => $prefix . 'accounts_social',
    'ACCOUNTS_TO_USERS'       => $prefix . 'accounts_to_users',
    'ACCOUNTS_VALID'          => $prefix . 'accounts_valid',
    'ACHIEVEMENTS'            => $prefix . 'achievements',
    'ADVANCED_STATS'          => $prefix . 'advanced_stats',
    'ADVANCED_STATS_HISTORY'  => $prefix . 'advanced_stats_history',
    'ADVANCED_STATS_UNIVERSE' => $prefix . 'advanced_stats_universe',
    'AKS'                     => $prefix . 'aks',
    'ALLIANCE'                => $prefix . 'alliance',
    'ALLIANCE_RANK'           => $prefix . 'alliance_ranks',
    'ALLIANCE_REQUEST'        => $prefix . 'alliance_request',
    'USERS_BANNED'            => $prefix . 'users_banned',
    'BOT_LLM_PROVIDERS'       => $prefix . 'bot_llm_providers',
    'BOT_LOG'                 => $prefix . 'bot_log',
    'BOT_PLAYERS'             => $prefix . 'bot_players',
    'BOT_PROFILES'            => $prefix . 'bot_profiles',
    'BOT_RELATIONS'           => $prefix . 'bot_relations',
    'BUDDY'                   => $prefix . 'buddy',
    'BUDDY_REQUEST'           => $prefix . 'buddy_request',
    'CONFIG'                  => $prefix . 'config',
    'CONFIG_UNIVERSE'         => $prefix . 'config_universe',
    'CONFIG_UNIVERSE_MODULES' => $prefix . 'config_universe_modules',
    'CRONJOBS'                => $prefix . 'cronjobs',
    'CRONJOBS_LOG'            => $prefix . 'cronjobs_log',
    'DIPLO'                   => $prefix . 'diplo',
    'EXPEDITION_DECK_CONFIG'  => $prefix . 'expedition_deck_config',
    'EXPEDITION_FINDABLE_SHIPS' => $prefix . 'expedition_findable_ships',
    'EXPEDITION_USER_DECK'    => $prefix . 'expedition_user_deck',
    'FLEETS'                  => $prefix . 'fleets',
    'FLEETS_EVENT'            => $prefix . 'fleet_event',
    'FLEET_TEMPLATES'         => $prefix . 'fleet_templates',
    'LOG'                     => $prefix . 'log',
    'HONOR_FIGHTS'            => $prefix . 'honor_fights',
    'USER_DEVICES'            => $prefix . 'user_devices',
    'LOG_FLEETS'              => $prefix . 'log_fleets',
    'LOSTPASSWORD'            => $prefix . 'lostpassword',
    'NEWS'                    => $prefix . 'news',
    'HALL_OF_FAME'            => $prefix . 'hall_of_fame',
    'PAGES'                   => $prefix . 'pages',
    'TEAM_MEMBERS'            => $prefix . 'team_members',
    'BOARD_POSTS'             => $prefix . 'board_posts',
    'BOARD_COMMENTS'          => $prefix . 'board_comments',
    'BOARD_VOTES'             => $prefix . 'board_votes',
    'NOTES'                   => $prefix . 'notes',
    'MARKETPLACE'             => $prefix . 'marketplace',
    'MESSAGES'                => $prefix . 'messages',
    'MESSAGES_FORBIDDEN'      => $prefix . 'messages_forbidden',
    'MULTI'                   => $prefix . 'multi',
    'MULTI_TO_USERS'          => $prefix . 'multi_to_users',
    'PHALANX_FLEETS'          => $prefix . 'phalanx_fleets',
    'PHALANX_LOG'             => $prefix . 'phalanx_log',
    'PIRATE_BASES'            => $prefix . 'pirate_bases',
    'PIRATE_BASES_HISTORY'    => $prefix . 'pirate_bases_history',
    'PLANETS'                 => $prefix . 'planets',
    'PLANET_DATA'             => $prefix . 'planet_data',
    'PLANET_WRECKFIELD'       => $prefix . 'planet_wreckfield',
    'RW'                      => $prefix . 'raports',
    'RECORDS'                 => $prefix . 'records',
    'SESSION'                 => $prefix . 'session',
    'SHORTCUTS'               => $prefix . 'shortcuts',
    'STATPOINTS'              => $prefix . 'statpoints',
    'STATPOINTS_HISTORY'      => $prefix . 'statpoints_history',
    'SYSTEM'                  => $prefix . 'system',
    'TICKETS'                 => $prefix . 'ticket',
    'TICKETS_ANSWER'          => $prefix . 'ticket_answer',
    'TICKETS_CATEGORY'        => $prefix . 'ticket_category',
    'TOPKB'                   => $prefix . 'topkb',
    'TOPKB_USERS'             => $prefix . 'users_to_topkb',
    'TRADES'                  => $prefix . 'trades',
    'TUTORIALS'               => $prefix . 'tutorials',
    'TUTORIAL_UNIVERSE'       => $prefix . 'tutorial_universe',
    'TUTORIAL_GOALS'          => $prefix . 'tutorial_goals',
    'TUTORIAL_REWARDS'        => $prefix . 'tutorial_rewards',
    'USERS'                   => $prefix . 'users',
    'USERS_BLOCKLIST'         => $prefix . 'users_blocklist',
    'USERS_SETTINGS'          => $prefix . 'users_settings',
    'USERS_TO_ACHIEVEMENTS'   => $prefix . 'users_to_achievements',
    'USERS_TO_ACS'            => $prefix . 'users_to_acs',
    'USERS_TO_MESSAGES'       => $prefix . 'users_to_messages',
    'USERS_COMMENTS'          => $prefix . 'users_comments',
    'USERS_ANALYTICS'         => $prefix . 'users_analytics',
    'USERS_TUTORIAL'          => $prefix . 'users_tutorial',
    'USERS_TUTORIAL_GOAL'     => $prefix . 'users_tutorial_goal',
    'VARS'                    => $prefix . 'vars',
    'VARS_RAPIDFIRE'          => $prefix . 'vars_rapidfire',
    'VARS_REQUIRE'            => $prefix . 'vars_requriements',
    'VOTE_LOG'                => $prefix . 'vote_log',
    'VOTE_SITES'              => $prefix . 'vote_sites',
];
// MOD-TABLES
