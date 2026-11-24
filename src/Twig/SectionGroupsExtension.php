<?php

namespace App\Twig;

use App\Game\Core\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Grouped game pages: one side-menu entry each (main.navigation) plus a tab strip on the member
 * pages (main.sectiontabs). The module and tutorial gates of every member live only here.
 *
 * - `section_group_of(key)`: the group of a page key (`_page`, shipyard split into shipyard/defense) or null.
 * - `section_group(group, noobInterface, tutorial)`: the visible members, each
 *   `{key, route, params, icon, label}` (label = LNG key).
 */
final class SectionGroupsExtension extends AbstractExtension
{
    /** group => [key, route, route params, icon, LNG key, module (0 = always), min tutorial step (null = hidden during the tutorial)] */
    private const GROUPS = [
        'empire' => [
            ['empire', 'game_empire_show', [], 'globe', 'lm_empire', MODULE_IMPERIUM, 11],
            ['queues', 'game_queues_show', [], 'list', 'lm_queues', MODULE_IMPERIUM, 11],
            ['resources', 'game_resources_show', [], 'chart-column', 'lm_resources', MODULE_RESSOURCE_LIST, 0],
        ],
        'research' => [
            ['research', 'game_research_show', [], 'flask-conical', 'lm_research', MODULE_RESEARCH, 6],
            ['techtree', 'game_techtree_show', [], 'git-fork', 'lm_technology', MODULE_TECHTREE, 6],
        ],
        'shipyard' => [
            ['shipyard', 'game_shipyard_show', ['kind' => 'shipyard'], 'rocket', 'lm_shipshard', MODULE_SHIPYARD_FLEET, 4],
            ['defense', 'game_shipyard_show', ['kind' => 'defense'], 'shield', 'lm_defenses', MODULE_SHIPYARD_DEFENSIVE, 4],
            ['repairdock', 'game_repairdock_show', [], 'wrench', 'lm_repairdock', MODULE_REPAIR_DOCK, null],
        ],
        'rankings' => [
            ['statistics', 'game_statistics_show', [], 'trophy', 'lm_statistics', MODULE_STATISTICS, 0],
            ['records', 'game_records_show', [], 'medal', 'lm_records', MODULE_RECORDS, 0],
            ['battlehall', 'game_battle_hall_show', [], 'skull', 'lm_topkb', 0, 0],
            ['achievements', 'game_achievements_show', [], 'award', 'Achievements', 0, null],
            ['advancedstats', 'game_advanced_stats_show', [], 'chart-line', 'advancedStats', 0, null],
            ['banlist', 'game_ban_list_show', [], 'ban', 'lm_banned', MODULE_BANLIST, 0],
        ],
    ];

    public function __construct(
        private readonly ViewHelpers $viewHelpers,
    ) {}

    /** @return list<TwigFunction> section_group, section_group_of */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('section_group', $this->members(...)),
            new TwigFunction('section_group_of', $this->groupOf(...)),
        ];
    }

    /** Group of a page key (empire, research, shipyard, rankings) or null for ungrouped pages. */
    public function groupOf(string $key): ?string
    {
        foreach (self::GROUPS as $group => $members) {
            if (in_array($key, array_column($members, 0), true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Visible members of a group, in menu order.
     *
     * @param mixed $tutorial the template's `tutorial` value (falsy or an array with `id`)
     *
     * @return list<array{key: string, route: string, params: array<string, string>, icon: string, label: string}>
     */
    public function members(string $group, mixed $noobInterface, mixed $tutorial): array
    {
        $step = $noobInterface && $tutorial ? (int) ($tutorial['id'] ?? 0) : null;
        $visible = [];
        foreach (self::GROUPS[$group] ?? [] as [$key, $route, $params, $icon, $label, $module, $minStep]) {
            if ($module !== 0 && !$this->viewHelpers->isModuleAvailable($module)) {
                continue;
            }
            if ($step !== null && ($minStep === null || $step < $minStep)) {
                continue;
            }
            $visible[] = ['key' => $key, 'route' => $route, 'params' => $params, 'icon' => $icon, 'label' => $label];
        }

        return $visible;
    }
}
