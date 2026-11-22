<?php

namespace App\Game\Settings;

use App\Game\Enum\AuthLevel;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\GameUtilities;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;

/**
 * Saves the player's settings form (game/settings, POST): reads and validates the posted values, renames the player
 * (with the name rules and the change cooldown; logs the player out afterwards), writes the admin protection / discord
 * fields of the user and all columns of users_settings, switches the theme and checks the settings achievements.
 *
 * Most settings are plain pass-through values (see FIELDS: column => request parameter and default); the rest (language,
 * decimal separator, themes, hangar filters, spy report hours, spy count, fleet actions, messages per page) are
 * validated individually. Everything runs in one transaction that locks the user and planets.
 */
class SettingsUpdater
{
    use GameUtilities;

    /** users_settings column => [request parameter, default]; the type of the default decides how the input is read */
    private const FIELDS = [
        'timezone' => ['timezone', ''],
        'planet_sort' => ['planetSort', 0],
        'planet_sort_order' => ['planetOrder', 0],
        'settings_esp' => ['galaxySpy', 0],
        'settings_wri' => ['galaxyMessage', 0],
        'settings_bud' => ['galaxyBuddyList', 0],
        'settings_mis' => ['galaxyMissle', 0],
        'settings_rep' => ['galaxySpyReport', 0],
        'spy_report_fresh_hours' => ['spyReportFreshHours', 24],
        'spy_report_recent_hours' => ['spyReportRecentHours', 168],
        'settings_blockPM' => ['blockPM', 0],
        'showMessageCategory' => ['showMessageCategory', 0],
        'enable_planet_styles' => ['enablePlanetStyles', 0],
        'hof' => ['queueMessages', 0],
        'spyMessagesMode' => ['spyMessagesMode', 0],
        'spyReportsCollapsed' => ['spyReportsCollapsed', 0],
        'show_all_buildable_elements' => ['show_all_buildable_elements', 0],
        'missing_requirements_opacity' => ['missing_requirements_opacity', 0],
        'missing_resources_opacity' => ['missing_resources_opacity', 0],
        'hide_useless' => ['hide_useless', 0],
        'hangar_filters_enabled' => ['hangarFiltersEnabled', 0],
        'colorMission2friend' => ['colorMission2friend', '#ff00ff'],
        'colorMission1Own' => ['colorMission1Own', '#66cc33'],
        'colorMission2Own' => ['colorMission2Own', '#339966'],
        'colorMission3Own' => ['colorMission3Own', '#5bf1c2'],
        'colorMission4Own' => ['colorMission4Own', '#cf79de'],
        'colorMission5Own' => ['colorMission5Own', '#80a0c0'],
        'colorMission6Own' => ['colorMission6Own', '#ffcc66'],
        'colorMission7Own' => ['colorMission7Own', '#c1c1c1'],
        'colorMission7OwnReturn' => ['colorMission7OwnReturn', '#cf79de'],
        'colorMission8Own' => ['colorMission8Own', '#ceff68'],
        'colorMission9Own' => ['colorMission9Own', '#ffff99'],
        'colorMission10Own' => ['colorMission10Own', '#ffcc66'],
        'colorMission15Own' => ['colorMission15Own', '#5bf1c2'],
        'colorMission16Own' => ['colorMission16Own', '#5bf1c2'],
        'colorMission17Own' => ['colorMission17Own', '#5bf1c2'],
        'colorMissionReturnOwn' => ['colorMissionReturnOwn', '#6e8eea'],
        'colorMission1Foreign' => ['colorMission1Foreign', '#ff0000'],
        'colorMission2Foreign' => ['colorMission2Foreign', '#aa0000'],
        'colorMission3Foreign' => ['colorMission3Foreign', '#00ff00'],
        'colorMission4Foreign' => ['colorMission4Foreign', '#ad57bc'],
        'colorMission5Foreign' => ['colorMission5Foreign', '#3399cc'],
        'colorMission6Foreign' => ['colorMission6Foreign', '#ff6600'],
        'colorMission7Foreign' => ['colorMission7Foreign', '#00ff00'],
        'colorMission8Foreign' => ['colorMission8Foreign', '#acdd46'],
        'colorMission9Foreign' => ['colorMission9Foreign', '#dddd77'],
        'colorMission10Foreign' => ['colorMission10Foreign', '#ff6600'],
        'colorMission15Foreign' => ['colorMission15Foreign', '#39d0a0'],
        'colorMission16Foreign' => ['colorMission16Foreign', '#39d0a0'],
        'colorMission17Foreign' => ['colorMission17Foreign', '#39d0a0'],
        'colorMissionReturnForeign' => ['colorMissionReturnForeign', '#6e8eea'],
        'colorStaticTimer' => ['colorStaticTimer', '#ffff00'],
        'colorPositive' => ['colorPositive', '#00ff00'],
        'colorNegative' => ['colorNegative', '#ff0000'],
        'colorNeutral' => ['colorNeutral', '#ffd600'],
        'prioMission1' => ['type_mission_1', 1],
        'prioMission2' => ['type_mission_2', 2],
        'prioMission3' => ['type_mission_3', 0],
        'prioMission4' => ['type_mission_4', 3],
        'prioMission5' => ['type_mission_5', 4],
        'prioMission6' => ['type_mission_6', 5],
        'prioMission7' => ['type_mission_7', 6],
        'prioMission8' => ['type_mission_8', 7],
        'prioMission9' => ['type_mission_9', 8],
        'prioMission17' => ['type_mission_17', 9],
        'records_optIn' => ['recordsOptIn', 0],
        'stb_small_ress' => ['stb_small_ress', 500],
        'stb_med_ress' => ['stb_med_ress', 3000],
        'stb_big_ress' => ['stb_big_ress', 7000],
        'stb_small_time' => ['stb_small_time', 1],
        'stb_med_time' => ['stb_med_time', 2],
        'stb_big_time' => ['stb_big_time', 3],
        'stb_enabled' => ['stb_enabled', 0],
        'publish_achievement' => ['publish_achievement', 0],
        'colonyWarn' => ['colonyWarn', 0],
        'noob_interface' => ['noob_interface', 0],
    ];

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Reads the posted form, validates it and saves it.
     *
     * @throws SettingsRefused with the translated reason when a value is invalid (nothing is saved)
     */
    public function save(): void
    {
        $LNG = $this->state->LNG;
        $THEME = $this->state->THEME;
        $form = $this->readForm();
        $values = $form['values'];
        $discord_id = $form['discord_id'];
        $discord_hook = $form['discord_hook'];
        $username = $form['username'];
        $language = $form['language'];
        $recordsOptIn = $values['records_optIn'];

        $messagesPerPage = $form['messagesPerPage'];
        if ($messagesPerPage > 50) {
            $messagesPerPage = 50;
        } elseif ($messagesPerPage < 3) {
            $messagesPerPage = 3;
        }

        $adminprotection = $form['adminprotection'];
        if ($this->state->USER['authlevel'] != AuthLevel::User->value) {
            $adminprotection = ($adminprotection == 1) ? $this->state->USER['authlevel'] : 0;
        } else {
            $adminprotection = 0;
            $discord_id = '';
            $discord_hook = '';
        }
        $discord_hook = trim((string) $discord_hook);
        if ($discord_hook !== '' && !Discord::isAllowedWebhook($discord_hook)) {
            throw new SettingsRefused($LNG['op_discord_hook_invalid']);
        }

        $spycount           = min(max(round($form['spycount']), 1), 4294967295);
        $fleetactions       = min(max($form['fleetactions'], 1), 99);

        $spyReportFreshHours  = $values['spy_report_fresh_hours'];
        $spyReportRecentHours = $values['spy_report_recent_hours'];
        if ($spyReportFreshHours < 1 || $spyReportRecentHours < 1
            || $spyReportRecentHours <= $spyReportFreshHours
            || $spyReportRecentHours > 65535) {
            throw new SettingsRefused($LNG['op_spy_report_time_invalid']);
        }

        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $decimalSeparator = $this->decimalSeparatorFor($language, (int) $form['decimalSeparator']);

        $availableImageThemes = Theme::getAvailableImageThemes();
        $availableCssThemes = Theme::getAvailableCssThemes();
        $imageTheme = array_key_exists($form['imageThemeInput'], $availableImageThemes) ? $form['imageThemeInput'] : $THEME->getImageThemeName();
        $cssTheme = array_key_exists($form['cssThemeInput'], $availableCssThemes) ? $form['cssThemeInput'] : $THEME->getCssThemeName();

        $db = $this->db;
        $db->startTransaction();
        $this->lockPlayerRows();
        $this->changeUsername($username);

        $this->writeSettings($values + [
            'dpath' => $imageTheme,
            'css_dpath' => $cssTheme,
            'spio_anz' => $spycount,
            'settings_fleetactions' => $fleetactions,
            'lang' => $language,
            'decimal_separator' => $decimalSeparator,
            'hangar_filters' => $form['hangarFilters'],
            'messages_per_page' => $messagesPerPage,
        ], $adminprotection, $discord_id, $discord_hook);
        // the account area (lobby, account settings, board) wears the same skin as the game
        $db->update(
            'UPDATE %%ACCOUNTS%% a INNER JOIN %%ACCOUNTS_TO_USERS%% atu ON atu.account_id = a.id AND atu.end_time = 0'
            . ' SET a.dpath = :dpath, a.css_dpath = :cssDpath WHERE atu.user_id = :userId;',
            [':dpath' => $imageTheme, ':cssDpath' => $cssTheme, ':userId' => $this->state->USER['id']],
        );

        $this->state->USER['dpath'] = $imageTheme;
        $this->state->USER['css_dpath'] = $cssTheme;
        $THEME->setUserTheme($imageTheme, $cssTheme);

        $db->commit();

        $this->miscAchievements()->checkSettingsAchievements($this->state->USER['id'], $recordsOptIn);
    }

    /**
     * Reads the posted settings form with the defaults of each field (the form can be partial): the users_settings
     * columns of FIELDS, the user-level fields (admin protection, discord, username), language, separator, themes,
     * spy/fleet/message counts and the hangar filter groups merged into the stored filters (encoded).
     *
     * @return array<string, mixed> values (column => value), adminprotection, discord_id, discord_hook, username, language,
     *         decimalSeparator, imageThemeInput, cssThemeInput, spycount, fleetactions, messagesPerPage, hangarFilters
     */
    private function readForm(): array
    {
        $THEME = $this->state->THEME;
        $values = [];
        foreach (self::FIELDS as $column => [$parameter, $default]) {
            $values[$column] = $this->input()->typed($parameter, $default);
            if (str_starts_with($column, 'color')) {
                $values[$column] = ColorSetting::sanitize($values[$column], (string) $default);
            }
        }
        $imageThemeInput    = $this->input()->string('imageTheme');
        if ($imageThemeInput === '') {
            $imageThemeInput = $this->input()->typed('theme', $THEME->getImageThemeName());
        }
        $hangarFilterGroups = [];
        if ($this->input()->int('hangarFilterCivil')) {
            $hangarFilterGroups[] = 'civil';
        }
        if ($this->input()->int('hangarFilterMilitary')) {
            $hangarFilterGroups[] = 'military';
        }
        if ($this->input()->int('hangarFilterRecycler')) {
            $hangarFilterGroups[] = 'recycler';
        }
        if ($this->input()->int('hangarFilterAll')) {
            $hangarFilterGroups[] = 'all';
        }

        return [
            'values'          => $values,
            'adminprotection' => $this->input()->int('adminprotection'),
            'discord_id'      => $this->input()->string('discord_id'),
            'discord_hook'    => $this->input()->string('discord_hook'),
            'username'        => $this->input()->typed('username', $this->state->USER['username'], UTF8_SUPPORT),
            'language'        => $this->input()->string('language'),
            'decimalSeparator' => $this->input()->int('decimalSeparator'),
            'imageThemeInput' => $imageThemeInput,
            'cssThemeInput'   => $this->input()->typed('cssTheme', $THEME->getCssThemeName()),
            'spycount'        => $this->input()->float('spycount', 1.0),
            'fleetactions'    => $this->input()->int('fleetactions', 5),
            'messagesPerPage' => $this->input()->int('messagesPerPage', 10),
            'hangarFilters'   => $this->hangarFilters()->encode($this->hangarFilters()->mergeGroups(
                $this->hangarFilters()->parse($this->state->USER['hangar_filters'] ?? null),
                $hangarFilterGroups,
            )),
        ];
    }

    /**
     * Follow the new language's default decimal separator only when the stored separator is still that language's
     * default. A custom override is kept when the dropdown was not changed in this save.
     */
    private function decimalSeparatorFor(string $language, int $decimalSeparator): int
    {
        $previousSeparator = (int) $this->state->USER['decimal_separator'];
        if ($language !== $this->state->USER['lang']
            && $decimalSeparator === $previousSeparator
            && $previousSeparator === (new NumberAbbreviation())->defaultDecimalSeparatorForLang($this->state->USER['lang'])
        ) {
            $decimalSeparator = (new NumberAbbreviation())->defaultDecimalSeparatorForLang($language);
        }

        return $decimalSeparator;
    }

    /** Locks the user, settings and planet rows of the player (FOR UPDATE) for the rest of the transaction. */
    private function lockPlayerRows(): void
    {
        $db = $this->db;
        $sql = "SELECT u.username, u.uctime FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id WHERE u.universe = :universe AND u.id = :id FOR UPDATE;";
        $locked = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':id' => $this->state->USER['id'],
        ]);
        if (!empty($locked)) {
            // the page-start copy is stale when a second request renamed the player meanwhile (name cooldown)
            $this->state->USER['username'] = $locked['username'];
            $this->state->USER['uctime'] = $locked['uctime'];
        }
        $sql = "SELECT * FROM %%PLANETS%% WHERE universe = :universe AND id_owner = :id FOR UPDATE;";
        $db->select($sql, [
            ':universe' => $this->universe()->current(),
            ':id' => $this->state->USER['id'],
        ]);
    }

    /**
     * Renames the player when the posted name differs: the name must be valid, unused in the universe and the last
     * change at least USERNAME_CHANGETIME ago; the player is logged out afterwards (the session carries the name).
     *
     * @throws SettingsRefused with the translated reason (the open transaction is not committed then)
     */
    private function changeUsername(mixed $username): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        if (!empty($username) && $this->state->USER['username'] != $username) {
            if (!$this->playerRegistration()->isNameValid($username)) {
                throw new SettingsRefused($LNG['op_user_name_no_alphanumeric']);
            } elseif ($this->state->USER['uctime'] >= $this->state->runtime->timestamp - USERNAME_CHANGETIME) {
                throw new SettingsRefused($LNG['op_change_name_pro_week']);
            } else {
                $sql = "SELECT
					(SELECT COUNT(*) FROM %%USERS%% WHERE universe = :universe AND username = :username)
				AS count";
                $Count = $db->selectSingle($sql, [
                    ':universe' => $this->universe()->current(),
                    ':username' => $username,
                ], 'count');

                if (!empty($Count)) {
                    throw new SettingsRefused($LNG->format('op_change_name_exist', $username));
                } else {
                    $sql = "UPDATE %%USERS%% SET username = :username, uctime = :timestamp WHERE id = :userID;";
                    try {
                        $db->update($sql, [
                            ':username' => $username,
                            ':userID'   => $this->state->USER['id'],
                            ':timestamp' => $this->state->runtime->timestamp,
                        ]);
                    } catch (\Throwable $exception) {
                        // the unique (universe, username) index decides when two players take the same free name at once
                        if (Database::errorNumber($exception) !== Database::ERROR_DUPLICATE_KEY) {
                            throw $exception;
                        }
                        throw new SettingsRefused($LNG->format('op_change_name_exist', $username));
                    }

                    $this->state->auth->logout();
                }
            }
        }
    }

    /**
     * Writes the admin protection and discord fields of the user and all settings columns.
     *
     * @param array<string, mixed> $values users_settings column => value
     */
    private function writeSettings(array $values, mixed $adminprotection, mixed $discord_id, mixed $discord_hook): void
    {
        $USER = $this->state->USER;
        $this->db->update("UPDATE %%USERS%% SET
		authattack				        = :adminProtection,
		discord_id				        = :discord_id,
		discord_hook				    = :discord_hook
		WHERE id = :userID;", [
            ':adminProtection'              => $adminprotection,
            ':discord_id'                   => $discord_id,
            ':discord_hook'                 => $discord_hook,
            ':userID'                       => $USER['id'],
        ]);

        // $values: settings column => value, built from the fixed setting list of the form
        $this->db->updateRows('USERS_SETTINGS', $values, ['user_id' => $USER['id']]);
    }
}
