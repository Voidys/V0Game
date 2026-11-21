<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Enum\AuthLevel;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Data of the admin layout (formerly three frames: menu, top navigation, content): the compact grouped
 * navigation filtered by the admin's rights (related pages share one entry and switch via tabs in the
 * page head) with the current entry and tab marked, the universe switcher,
 * the open ticket count and the account actions. Rendered by admin/overall_header.html.twig.
 *
 * pageData() is everything an admin page template needs besides its own variables (title, clock,
 * theme, the shell itself); data() is the shell part alone.
 */
final class AdminShell
{
    use \App\Game\Core\GameUtilities;

    /**
     * group => menu entries; an entry is [label key, icon, tabs]. Tabs are the pages shown under the
     * entry (switchable inside the page): [page, extra query, tab label key, right (former class name), route],
     * the route (GameRoute name) is the link of the tab (query = its extra parameters).
     * A null right means every admin may open it. Entries with one tab are plain links; an entry is
     * hidden when the admin may open none of its tabs.
     */
    private const MENU = [
        'mu_general' => [
            ['adm_cp_index', 'layout-dashboard', [['overview', '', 'adm_cp_index', null, 'admin_overview_show']]],
            ['mu_game_info', 'info', [['information', '', 'mu_game_info', 'ShowInformationPage', 'admin_information_show']]],
            ['adm_nav_settings', 'sliders-horizontal', [
                ['configBasic', '', 'adm_tab_server', 'ShowConfigBasicPage', 'admin_config_basic_show'],
                ['configUni', '', 'adm_tab_universe', 'ShowConfigUniPage', 'admin_config_uni_show'],
                ['configPlanets', '', 'adm_tab_planets', 'ShowMultiIPPage', 'admin_config_planets_show'],
                ['module', '', 'mu_module', 'ShowModulePage', 'admin_module_show'],
            ]],
            ['adm_nav_social', 'log-in', [['configSocial', '', 'adm_nav_social', 'ShowConfigSocialPage', 'admin_config_social_show']]],
            ['adm_nav_expeditions', 'compass', [
                ['expeditionDeck', '', 'adm_tab_card_deck', 'ShowExpeditionDeckPage', 'admin_expedition_deck_show'],
                ['expeditionShips', '', 'adm_tab_findable_ships', 'ShowExpeditionShipsPage', 'admin_expedition_ships_show'],
            ]],
            ['adm_nav_bots', 'user-cog', [
                ['bots', 'mode=providers', 'adm_tab_bot_providers', 'ShowBotsPage', 'admin_bots_providers'],
                ['bots', 'mode=profiles', 'adm_tab_bot_profiles', 'ShowBotsPage', 'admin_bots_profiles'],
                ['bots', 'mode=bots', 'adm_tab_bot_list', 'ShowBotsPage', 'admin_bots_bots'],
            ]],
            ['mu_cronjob', 'calendar-clock', [['cronjob', '', 'mu_cronjob', 'ShowCronjobPage', 'admin_cronjob_show']]],
        ],
        'adm_group_community' => [
            ['adm_nav_players', 'users', [
                ['search', 'search=users', 'adm_tab_players', 'ShowSearchPage', 'admin_search_show'],
                ['search', 'search=online', 'mu_connected', 'ShowSearchPage', 'admin_search_show'],
                ['search', 'search=planet', 'adm_tab_planets', 'ShowSearchPage', 'admin_search_show'],
                ['search', 'search=moon', 'adm_tab_moons', 'ShowSearchPage', 'admin_search_show'],
                ['search', 'search=p_connect', 'mu_active_planets', 'ShowSearchPage', 'admin_search_show'],
                ['search', '', 'mu_search_page', 'ShowSearchPage', 'admin_search_show'],
            ]],
            ['adm_nav_accounts', 'id-card', [
                ['accountData', '', 'mu_info_account_page', 'ShowAccountDataPage', 'admin_account_data_show'],
                ['accountEditor', '', 'adm_tab_editor', 'ShowAccountEditorPage', 'admin_account_editor_show'],
                ['creator', '', 'adm_tab_create', 'ShowCreatorPage', 'admin_creator_show'],
                ['accountswap', '', 'adm_tab_swap', 'ShowAccountswapPage', 'admin_account_swap_show'],
            ]],
            ['adm_nav_moderation', 'shield-check', [
                ['ban', '', 'adm_tab_bans', 'ShowBanPage', 'admin_ban_show'],
                ['active', '', 'mu_vaild_users', 'ShowActivePage', 'admin_active_show'],
                ['multiIP', '', 'mu_multiip_page', 'ShowMultiIPPage', 'admin_multi_ip_show'],
                ['abuseReport', '', 'abuse_menu', 'ShowAbuseReportPage', 'admin_abuse_report_show'],
                ['comments', '', 'adm_comments', 'ShowMultiIPPage', 'admin_comments_show'],
                ['analytics', '', 'adm_analytics', 'ShowMultiIPPage', 'admin_analytics_show'],
            ]],
            ['mu_support', 'ticket', [['support', '', 'mu_support', 'ShowSupportPage', 'admin_support_show']]],
            ['adm_nav_messages', 'messages-square', [
                ['messageList', '', 'mu_mess_list', 'ShowMessageListPage', 'admin_message_list_show'],
                ['sendMessages', '', 'mu_global_message', 'ShowSendMessagesPage', 'admin_send_messages_show'],
            ]],
            ['adm_nav_content', 'file-text', [
                ['pages', '', 'adm_tab_pages', 'ShowPagesPage', 'admin_pages_show'],
                ['news', '', 'mu_news', 'ShowNewsPage', 'admin_news_show'],
                ['hallOfFame', '', 'mu_hall_of_fame', 'ShowHallOfFamePage', 'admin_hall_of_fame_show'],
                ['team', '', 'adm_tab_team', 'ShowTeamPage', 'admin_team_show'],
            ]],
            ['adm_nav_voting', 'gift', [['votes', '', 'adm_nav_voting', 'ShowVotesPage', 'admin_votes_show']]],
            ['mu_flying_fleets', 'send', [['flyingFleet', '', 'mu_flying_fleets', 'ShowFlyingFleetPage', 'admin_flying_fleet_show']]],
        ],
        'mu_tools' => [
            ['mu_logs', 'scroll-text', [['log', '', 'mu_logs', 'ShowLogPage', 'admin_log_show']]],
            ['mu_giveaway', 'gift', [['giveaway', '', 'mu_giveaway', 'ShowGiveawayPage', 'admin_giveaway_show']]],
            ['mu_dump', 'hard-drive-download', [['dump', '', 'mu_dump', 'ShowDumpPage', 'admin_dump_show']]],
            ['mu_md5_encripter', 'key-round', [['passEncripter', '', 'mu_md5_encripter', 'ShowPassEncripterPage', 'admin_pass_encripter_show']]],
            ['adm_nav_maintenance', 'wrench', [['maintenance', '', 'adm_nav_maintenance', 'ShowClearCachePage|ShowStatUpdatePage', 'admin_maintenance_show']]],
            ['rp_header', 'shuffle', [['reshuffle', '', 'rp_header', 'ShowReshufflePage', 'admin_reshuffle_show']]],
        ],
    ];

    /** pages that ask for confirmation before they run (label key of the question) */
    private const CONFIRM = [];

    /** action pages without a menu entry of their own, shown under the entry of the given page */
    private const PAGE_ALIASES = ['clearcache' => 'maintenance', 'statupdate' => 'maintenance'];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** templates rendered without the shell (password prompt, popup editors) */
    private const BARE_TEMPLATES = ['LoginPage.html.twig', 'QuickEditorUser.html.twig', 'QuickEditorPlanet.html.twig'];

    /**
     * Layout variables of an admin page (formerly Template::adm_main()). The shell is left out for
     * the bare templates and as long as the session has no admin access (password prompt).
     *
     * @return array<string, mixed>
     */
    public function pageData(string $template = ''): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $config = $this->configs->get();

        $serverTime = new \DateTime('now');
        $userTime = $serverTime;
        if (isset($USER['timezone'])) {
            try {
                $userTime = new \DateTime('now', new \DateTimeZone($USER['timezone']));
            } catch (\Exception) {
                $userTime = $serverTime;
            }
        }

        $bare = in_array(basename($template), self::BARE_TEMPLATES, true) || (int) ($this->state->session->adminAccess ?? 0) !== 1;

        return [
            'title' => $config->game_name . ' - ' . $LNG['adm_cp_title'],
            'fcm_info' => $LNG['fcm_info'],
            'lang' => $LNG->getLanguage(),
            'REV' => substr($config->version, -4),
            'date' => explode('|', date('Y\\|n\\|j\\|G\\|i\\|s\\|Z', $this->state->runtime->timestamp)),
            'Offset' => $userTime->getOffset() - $serverTime->getOffset(),
            'version' => $config->version,
            'dpath' => 'styles/theme/nova/',
            'themeCssPath' => 'styles/theme/nova/theme.css',
            'bodyclass' => 'full',
            'adminShell' => !$bare,
            // also on the notice pages, which carry no page variables
            'signalColors' => $USER['signalColors'] ?? null,
            // body id: the requested page (styling hook)
            'bodyId' => preg_replace('/\W/', '', $this->currentPage()) ?: 'overview',
        ] + ($bare ? [] : $this->data());
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $isAdmin = (int) $USER['authlevel'] === AuthLevel::Admin->value;
        $currentPage = strtolower($this->currentPage() ?: 'overview');
        $currentPage = self::PAGE_ALIASES[$currentPage] ?? $currentPage;
        $currentSearch = (string) ($this->state->requestQuery['search'] ?? '');
        $currentMode = $this->currentMode();

        $openTickets = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%TICKETS%% WHERE `universe` = :universe AND `status` = 0;',
            [':universe' => $this->universe()->getEmulated()],
            'anz',
        );

        $menu = [];
        $groups = $this->menuGroups($isAdmin);
        $currentTitle = $LNG['adm_cp_index'];
        $currentTab = null;
        $tabs = [];
        foreach ($groups as $groupKey => $entries) {
            $items = [];
            foreach ($entries as [$labelKey, $icon, $entryTabs]) {
                [$allowed, $activeTab] = $this->allowedTabs($entryTabs, $currentPage, $currentSearch, $currentMode);

                if ($allowed === []) {
                    continue;
                }

                $label = $LNG[$labelKey];
                if ($activeTab !== null) {
                    $currentTitle = $label;
                    $currentTab = count($allowed) > 1 ? $activeTab['label'] : null;
                    $tabs = count($allowed) > 1 ? $allowed : [];
                }
                $firstPage = $allowed[0]['page'];
                $items[] = [
                    'href' => $allowed[0]['href'],
                    'label' => $label,
                    'icon' => $icon,
                    'page' => $firstPage,
                    'active' => $activeTab !== null,
                    // the filter also finds an entry by the names of its tabs
                    'keywords' => implode(' ', array_column($allowed, 'label')),
                    'badge' => $firstPage === 'support' && $openTickets > 0 ? $openTickets : null,
                    'confirm' => isset(self::CONFIRM[$firstPage]) ? $LNG[self::CONFIRM[$firstPage]] : null,
                ];
            }
            if ($items !== []) {
                $menu[] = ['label' => $LNG[$groupKey], 'items' => $items];
            }
        }

        $universes = $this->universeOptions($isAdmin);

        return [
            'adminMenu' => $menu,
            'adminTitle' => $currentTitle,
            'adminTab' => $currentTab,
            'adminTabs' => $tabs,
            'adminUniverses' => $universes,
            'adminUniverse' => $this->universe()->getEmulated(),
            'adminUser' => $USER['username'] ?? '',
            'adminAuthLevel' => (int) $USER['authlevel'],
            'adminOpenTickets' => $openTickets,
        ];
    }

    /**
     * The menu groups: the static MENU, plus the system group (universes, rights, reset for the root user) for admins.
     *
     * @return array<string, list<array>> group label key => entries (label key, icon, tabs)
     */
    private function menuGroups(bool $isAdmin): array
    {
        $USER = $this->state->USER;

        $groups = self::MENU;
        if ($isAdmin) {
            $groups['ad_system'] = [
                ['mu_universe', 'server', [['universe', '', 'mu_universe', null, 'admin_universe_show']]],
                ['mu_moderation_page', 'lock', [
                    ['rights', 'mode=rights', 'mu_moderation_page', null, 'admin_rights_rights'],
                    ['rights', 'mode=users', 'ad_authlevel_title', null, 'admin_rights_users'],
                ]],
            ];
            if ((int) $USER['id'] === 1) {
                $groups['ad_system'][] = ['re_reset_universe', 'triangle-alert', [['reset', '', 're_reset_universe', null, 'admin_reset_show']]];
            }
        }

        return $groups;
    }

    /**
     * Filters the tabs of one menu entry by the admin's rights (a right like "A|B" is met by either) and marks the tab
     * of the current page (search lists also compare the `search`, tabs with a `mode` compare the mode).
     *
     * @param list<array{0: string, 1: string, 2: string, 3: ?string, 4: string}> $entryTabs page, query, label key, right, route
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>} the allowed tabs and the active one
     */
    private function allowedTabs(array $entryTabs, string $currentPage, string $currentSearch, string $currentMode): array
    {
        $LNG = $this->state->LNG;

        $allowed = [];
        $activeTab = null;
        foreach ($entryTabs as $entryTab) {
            [$page, $query, $tabLabelKey, $right] = $entryTab;
            // a right like "A|B" is met by either of them
            if ($right !== null && !array_filter(explode('|', $right), fn(string $one): bool => (bool) $this->viewHelpers()->allowedTo($one))) {
                continue;
            }
            parse_str($query, $params);
            $active = strtolower($page) === $currentPage
                && ($page !== 'search' || ($params['search'] ?? '') === $currentSearch)
                && (!isset($params['mode']) || $params['mode'] === $currentMode);
            $tab = [
                'href' => $this->tabHref($params, $entryTab[4]),
                'label' => $LNG[$tabLabelKey],
                'active' => $active,
            ];
            if ($active) {
                $activeTab = $tab;
            }
            $allowed[] = $tab + ['page' => $page, 'tabMode' => $params['mode'] ?? ''];
        }

        // a page called without its mode (e.g. admin/bots) shows its first tab, so that tab is the active one
        if ($activeTab === null && $currentMode === '') {
            foreach ($allowed as $i => $tab) {
                if (strtolower($tab['page']) === $currentPage && $tab['tabMode'] !== '') {
                    $allowed[$i]['active'] = true;
                    $activeTab = array_diff_key($allowed[$i], ['page' => true, 'tabMode' => true]);
                    break;
                }
            }
        }

        return [$allowed, $activeTab];
    }

    /** the page of the request: attribute `_page` of the route (the former `?page=`) */
    private function currentPage(): string
    {
        return (string) ($this->state->request?->attributes->get('_page') ?? '');
    }

    /** the action of the request: attribute `_mode` of the route ("show" is no tab) */
    private function currentMode(): string
    {
        $mode = (string) ($this->state->request?->attributes->get('_mode') ?? '');

        return $mode !== 'show' ? $mode : '';
    }

    /**
     * Link of a menu tab: its route (with the extra query as parameters).
     *
     * @param array<string, mixed> $params the parsed extra query
     */
    private function tabHref(array $params, string $route): string
    {
        // the mode of a tab is part of its route, not a query parameter
        unset($params['mode']);

        return $this->state->twig->getExtension(\Symfony\Bridge\Twig\Extension\RoutingExtension::class)->getPath($route, $params);
    }

    /**
     * The universe switcher options (admins only): "name (ID n)" by universe id.
     *
     * @return array<int, string>
     */
    private function universeOptions(bool $isAdmin): array
    {
        $universes = [];
        if ($isAdmin) {
            foreach ($this->universe()->availableUniverses() as $uniId) {
                $universes[$uniId] = sprintf('%s (ID %d)', $this->configs->get($uniId)->uni_name, $uniId);
            }
            ksort($universes);
        }

        return $universes;
    }
}
