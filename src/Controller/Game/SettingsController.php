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

namespace App\Controller\Game;

use App\Game\Enum\Mission;
use App\Game\Settings\SettingsRefused;
use App\Game\Settings\SettingsUpdater;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Twig\Markup;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Theme;

/**
 * Player settings: account data, display, fleet and notification options, vacation mode and account deletion.
 */
class SettingsController extends AbstractGameController
{
    /**
     * Settings page of the player: while in vacation mode only the vacation view is rendered, otherwise the options form (display, fleet, notification
     * settings and the buttons for vacation mode and account deletion).
     */
    #[GameRoute('game', 'game/settings', 'settings')]
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        if ($USER['urlaubs_modus'] == 1) {
            return $this->render('game/page.settings.vacation.html.twig', [
                'vacationUntil'         => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $USER['urlaubs_until'], $USER['timezone']),
                'delete'                => $USER['db_deaktjava'],
                'canVacationDisbaled'   => $USER['urlaubs_until'] < $this->state->runtime->timestamp,
            ]);
        } else {
            $db = $this->db;
            $sql = "SELECT prioMission1 as 'type_mission_1', prioMission2 as 'type_mission_2', 
            prioMission3 as 'type_mission_3',prioMission4 as 'type_mission_4', 
            prioMission5 as 'type_mission_5',prioMission6 as 'type_mission_6',
            prioMission7 as 'type_mission_7',prioMission8 as 'type_mission_8', 
            prioMission9 as 'type_mission_9', prioMission17 as 'type_mission_17'
            FROM %%USERS_SETTINGS%% WHERE user_id = :userID ;";
            $missionprios = $db->selectSingle($sql, [
                ':userID'   => $USER['id'],
            ]);

            return $this->render('game/page.settings.default.html.twig', [
                ...$this->selectorOptions(),
                ...$this->accountSettings($USER),
                ...$this->galaxyAndSpySettings($USER),
                ...$this->interfaceSettings($USER),
                'missionPrios' => $missionprios,
                ...$this->hangarSettings($USER),
            ]);
        }
    }

    /**
     * Option lists of the settings form: time zones, number format, planet sorting, image and CSS themes and languages.
     *
     * @return array{Selectors: array<string, mixed>}
     */
    private function selectorOptions(): array
    {
        $LNG = $this->state->LNG;

        return [
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'decimalSeparators' => [
                    0 => "1.234.567,89",
                    1 => "1,234,567.89",
                ],
                'Sort' => [
                    0 => $LNG['op_sort_normal'],
                    1 => $LNG['op_sort_koords'],
                    2 => $LNG['op_sort_abc'],
                ],
                'SortUpDown' => [
                    0 => $LNG['op_sort_up'],
                    1 => $LNG['op_sort_down'],
                ],
                'ImageSkins' => Theme::getAvailableImageThemes(),
                'CssSkins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getLanguageOptions(),
            ],
        ];
    }

    /**
     * Account related values of the form: protection, Discord, name change timer, language, number format, themes, planet sorting,
     * time zone, deletion state and the message display options.
     *
     * @param array<string, mixed> $USER
     * @return array<string, mixed>
     */
    private function accountSettings(array $USER): array
    {
        $THEME = $this->state->THEME;

        return [
            'adminProtection'               => $USER['authattack'],
            'discord_id'                    => $USER['discord_id'],
            'discord_hook'                  => $USER['discord_hook'],
            'userAuthlevel'                 => $USER['authlevel'],
            'changeNickTime'                => ($USER['uctime'] + USERNAME_CHANGETIME) - $this->state->runtime->timestamp,
            'username'                      => $USER['username'],
            'userLang'                      => $USER['lang'],
            'decimalSeparator'              => $USER['decimal_separator'],
            'theme'                         => $USER['dpath'],
            'imageTheme'                    => $USER['dpath'],
            'cssTheme'                      => $USER['css_dpath'] ?? $THEME->getCssThemeName(),
            'planetSort'                    => $USER['planet_sort'],
            'planetOrder'                   => $USER['planet_sort_order'],
            'spycount'                      => $USER['spio_anz'],
            'fleetActions'                  => $USER['settings_fleetactions'],
            'timezone'                      => $USER['timezone'],
            'delete'                        => $USER['db_deaktjava'],
            'showMessageCategory'           => $USER['showMessageCategory'],
            'enablePlanetStyles'            => $USER['enable_planet_styles'],
            'queueMessages'                 => $USER['hof'],
            'spyMessagesMode'               => $USER['spyMessagesMode'],
            'spyReportsCollapsed'           => $USER['spyReportsCollapsed'],
        ];
    }

    /**
     * Galaxy view shortcuts, spy report freshness, message blocking, own id, referral link, colors and the storage-bar thresholds.
     *
     * @param array<string, mixed> $USER
     * @return array<string, mixed>
     */
    private function galaxyAndSpySettings(array $USER): array
    {
        return [
            'galaxySpy'                     => $USER['settings_esp'],
            'galaxyBuddyList'               => $USER['settings_bud'],
            'galaxyMissle'                  => $USER['settings_mis'],
            'galaxyMessage'                 => $USER['settings_wri'],
            'galaxySpyReport'               => $USER['settings_rep'],
            'spyReportFreshHours'           => $USER['spy_report_fresh_hours'],
            'spyReportRecentHours'          => $USER['spy_report_recent_hours'],
            'blockPM'                       => $USER['settings_blockPM'],
            'userid'                        => $USER['id'],
            'ref_active'                    => $this->configs->get()->ref_active,
            'SELF_URL'                      => $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpRoot,
            'colors'                        => $USER['colors'],
            'signalColors'                  => $USER['signalColors'],
            'defaultColors'                 => $this->playerLookup()->player_colors(),
            'defaultSignalColors'           => $this->playerLookup()->player_signal_colors(),
            'stb_small_ress'                => $USER['stb_small_ress'],
            'stb_med_ress'                  => $USER['stb_med_ress'],
            'stb_big_ress'                  => $USER['stb_big_ress'],
            'stb_small_time'                => $USER['stb_small_time'],
            'stb_med_time'                  => $USER['stb_med_time'],
            'stb_big_time'                  => $USER['stb_big_time'],
            'stb_enabled'                   => $USER['stb_enabled'],
        ];
    }

    /**
     * Interface options: records opt-in, messages per page, achievement publishing and the building/research list display options.
     *
     * @param array<string, mixed> $USER
     * @return array<string, mixed>
     */
    private function interfaceSettings(array $USER): array
    {
        return [
            'recordsOptIn'                  => $USER['records_optIn'],
            'messagesPerPage'               => $USER['messages_per_page'],
            'publish_achievement'           => $USER['publish_achievement'],
            'missing_requirements_opacity'  => $USER['missing_requirements_opacity'],
            'missing_resources_opacity'     => $USER['missing_resources_opacity'],
            'hide_useless'                  => $USER['hide_useless'],
        ];
    }

    /**
     * Hangar filter options (enabled flag and the visible groups) plus the colony warning, newbie interface and account id.
     *
     * @param array<string, mixed> $USER
     * @return array<string, mixed>
     */
    private function hangarSettings(array $USER): array
    {
        $hangarFilters = $this->hangarFilters()->parse($USER['hangar_filters'] ?? null);

        return [
            'hangarFiltersEnabled'          => $USER['hangar_filters_enabled'] ?? 0,
            'hangarGroupCivil'              => $this->hangarFilters()->hasGroup($hangarFilters, 'civil'),
            'hangarGroupMilitary'           => $this->hangarFilters()->hasGroup($hangarFilters, 'military'),
            'hangarGroupRecycler'           => $this->hangarFilters()->hasGroup($hangarFilters, 'recycler'),
            'hangarGroupAll'                => $this->hangarFilters()->hasGroup($hangarFilters, 'all'),
            'colonyWarn'                    => $USER['colonyWarn'],
            'noobInterface'                 => $USER['noob_interface'],
            'accountId'                     => $this->playerLookup()->get_account_id($USER['id']),
        ];
    }

    /**
     * Switches vacation mode. Leaving is only possible after `urlaubs_until`; entering needs checkVMode() to pass. Locks user and planet
     * rows, then VacationMode::enable_vmode / disable_vmode, and ends with a message.
     */
    #[GameRoute('game', 'game/settings/vacation', 'settings', 'toggleVacation', ['POST'])]
    public function toggleVacation(): Response
    {
        $LNG = $this->state->LNG;

        if ($this->state->USER['urlaubs_modus'] == 1) {
            if ($this->state->USER['urlaubs_until'] > $this->state->runtime->timestamp) {
                return $this->messageResponse($LNG['op_cant_deactivate_vacation_mode'], $this->settingsButton($LNG['sys_forward']));
            }

            $db = $this->db;
            $db->startTransaction();

            $sql = "SELECT id FROM %%USERS%% WHERE universe = :universe AND id = :userID FOR UPDATE;";
            $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':userID'   => $this->state->USER['id'],
            ]);

            $sql = "SELECT id FROM %%PLANETS%% WHERE universe = :universe AND id_owner = :userID FOR UPDATE;";
            $db->select($sql, [
                ':universe' => $this->universe()->current(),
                ':userID'   => $this->state->USER['id'],
            ]);

            $this->vacationMode()->disable_vmode($this->state->USER, $this->state->PLANET);
            $db->commit();

            return $this->messageResponse($LNG['op_options_vacation_deactivated'], $this->settingsButton($LNG['sys_forward']));
        } else {
            if (!$this->checkVMode()) {
                return $this->messageResponse($this->incomingHostileRefused ? $LNG['op_cant_activate_vacation_hostile'] : $LNG['op_cant_activate_vacation_mode'], $this->settingsButton($LNG['sys_back']));
            }

            $this->vacationMode()->enable_vmode($this->state->USER, $this->state->PLANET);
            $this->db->commit();
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
            $statsHandler->setTimestamp($this->state->USER['id'], AdvancedStatsEnum::FIRST_VACATION, $this->state->runtime->timestamp);

            // translations are trusted HTML
            $text = $LNG['op_options_vacation_activated'];
            $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $this->state->USER['universe'])) {
                $text .= $LNG['tn_vacation_mode_kick'];
            }
            return $this->messageResponse(new Markup($text, 'UTF-8'), $this->settingsButton($LNG['sys_forward']));
        }
    }

    /** Set by checkVMode() when vacation was refused because of foreign hostile fleets on their way. */
    private bool $incomingHostileRefused = false;

    /**
     * Whether foreign fleets with a hostile mission (attack, ACS attack, missile, destroy, spy) are on their way to a
     * planet or moon of the player (outward flights only; own fleets and returning ones do not count).
     */
    private function hostileFleetsInbound(): bool
    {
        $sql = 'SELECT COUNT(*) AS state FROM %%FLEETS%% WHERE `target_owner` = :userID AND `owner` != :userID
            AND `status` = :outward AND `mission` IN (:attack, :acs, :missile, :destroy, :spy);';

        return (int) $this->db->selectSingle($sql, [
            ':userID'   => $this->state->USER['id'],
            ':outward'  => FLEET_OUTWARD,
            ':attack'   => Mission::Attack->value,
            ':acs'      => Mission::Acs->value,
            ':missile'  => Mission::Missile->value,
            ':destroy'  => Mission::Destruction->value,
            ':spy'      => Mission::Spy->value,
        ], 'state') > 0;
    }

    /**
     * Toggles the deletion flag (`db_deaktjava` = request time, or 0 to cancel); the maintenance cronjob deletes flagged players later. Ends with a message.
     */
    #[GameRoute('game', 'game/settings/delete', 'settings', 'toggleDelete', ['POST'])]
    public function toggleDelete(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT id FROM %%USERS%% WHERE universe = :universe AND id = :userID FOR UPDATE;";
        $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':userID'   => $USER['id'],
        ]);

        if ($USER['db_deaktjava'] === 0) {
            $sql = "UPDATE %%USERS%% SET db_deaktjava = :timestamp WHERE id = :userID;";
            $db->update($sql, [
                ':userID'       => $USER['id'],
                ':timestamp'    => $this->state->runtime->timestamp,
            ]);
            $db->commit();

            $text = $LNG['op_options_deletion_activated'];
            if ($USER['urlaubs_modus'] == 0) { // only in standard settings menu
                $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            }
            return $this->messageResponse(new Markup($text, 'UTF-8'), $this->settingsButton($LNG['sys_forward']));
        } else {
            $sql = "UPDATE %%USERS%% SET db_deaktjava = 0 WHERE id = :userID;";
            $db->update($sql, [':userID'   => $USER['id']]);
            $db->commit();

            $text = $LNG['op_options_deletion_deactivated'];
            if ($USER['urlaubs_modus'] == 0) { // only in standard settings menu
                $text .= '<br>' . $LNG['op_options_no_other_settings_changed'];
            }
            return $this->messageResponse(new Markup($text, 'UTF-8'), $this->settingsButton($LNG['sys_forward']));
        }
    }

    /**
     * True when vacation mode may be entered: no own fleet is in flight. Locks the user and all planet rows, counts the fleets and
     * recalculates all other planets so the stored state is current when the vacation mode is applied. On success the transaction
     * stays OPEN (the caller enables vacation mode and commits, so no fleet can start in between); on refusal it is rolled back.
     */
    private function checkVMode()
    {
        $PLANET = $this->state->PLANET;
        $db = $this->db;

        $db->startTransaction();
        // Lock the user row first and calc from it. The page-start copy is
        // stale under concurrent requests and SavePlanetToDB writes its b_tech*
        // fields back.
        $sql = "SELECT * FROM %%USERS%% WHERE id = :userID FOR UPDATE;";
        $u = $db->selectSingle($sql, [':userID' => $this->state->USER['id']]);
        if (empty($u) || $u['urlaubs_modus'] == 1) {
            $db->rollback();
            return false;
        }
        $this->state->USER = array_merge($this->state->USER, $u);

        $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :userID AND destruyed = 0 FOR UPDATE;";
        $query = $db->select($sql, [':userID' => $this->state->USER['id']]);

        $sql = "SELECT COUNT(*) as state FROM %%FLEETS%% WHERE `owner` = :userID;";
        $fleets = $db->selectSingle($sql, [':userID' => $this->state->USER['id']], 'state');

        if ($fleets != 0) {
            $db->rollback();
            return false;
        }

        if (!$this->configs->get($this->state->USER['universe'])->vmode_allow_incoming && $this->hostileFleetsInbound()) {
            $db->rollback();
            $this->incomingHostileRefused = true;
            return false;
        }

        foreach ($query as $CPLANET) {
            if ($CPLANET['id'] == $PLANET['id']) {
                continue;
            }
            [$this->state->USER, $CPLANET] = $this->ecoObj->CalcResource($this->state->USER, $CPLANET, true);
            unset($CPLANET);
        }

        return true;
    }

    /**
     * Saves the settings form (delegates to the settings saving code) and ends with a message.
     */
    #[GameRoute('game', 'game/settings', 'settings', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        try {
            (new SettingsUpdater($this->db, $this->configs, $this->state))->save();
        } catch (SettingsRefused $refused) {
            return $this->messageResponse($refused->getMessage(), $this->settingsButton($LNG['sys_back']));
        }

        return $this->messageResponse($LNG['op_options_changed'], $this->settingsButton($LNG['sys_forward']));
    }

    /**
     * The "back to the settings" button under the message pages of the settings actions.
     *
     * @return list<array{url: string, label: string}>
     */
    private function settingsButton(string $label): array
    {
        return [['label' => $label, 'url' => $this->router->generate('game_settings_show')]];
    }
}
