<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Game\Core\SpyReportAnalysis;
use App\Game\Core\SpyReportTools;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Farm list: the latest spy report of every target of the player with loot, defense and fleet value, cargos needed and
 * distance, sortable and filterable, with shortcuts to attack, open the report and spy again.
 */
#[\App\Controller\RequireModule('MODULE_MESSAGES')]
class FarmListController extends AbstractGameController
{
    /** newest spy report messages read per request (the latest report per target is picked out of them) */
    private const MAX_REPORTS = 1500;

    private const SORTS = ['coords', 'player', 'state', 'age', 'loot', 'defense', 'fleet', 'cargos', 'distance'];

    /**
     * The farm list. Request (GET): sort (one of SORTS, default loot), dir (asc|desc), inactive (1 = only inactive players), max_defense
     * (largest defense value in resource units, 0 = no limit), max_age (oldest report in hours, 0 = no limit).
     */
    #[GameRoute('game', 'game/farms', 'farms')]
    public function show(): Response
    {
        $this->state->LNG->includeData(['FLEET']);
        $input = $this->input();
        $sort = $input->string('sort', 'loot');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'loot';
        $dir = $input->string('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $filter = [
            'inactive' => $input->bool('inactive'),
            'max_defense' => max(0, $input->int('max_defense')),
            'max_age' => max(0, $input->int('max_age')),
        ];

        $rows = array_values(array_filter($this->farmRows(), fn(array $row): bool => $this->passes($row, $filter)));
        usort($rows, fn(array $a, array $b): int => ($dir === 'asc' ? 1 : -1) * ($a['sort'][$sort] <=> $b['sort'][$sort]));

        return $this->render('game/page.farms.default.html.twig', [
            'rows' => $rows,
            'sort' => $sort,
            'dir' => $dir,
            'filter' => $filter,
            'sortKeys' => self::SORTS,
        ]);
    }

    /**
     * One row per target planet: the latest spy report of the player's own, not deleted spy report messages, analysed from the current
     * planet.
     *
     * @return list<array<string, mixed>>
     */
    private function farmRows(): array
    {
        $USER = $this->state->USER;
        $now = $this->state->runtime->timestamp;
        $tools = new SpyReportTools($this->db, $this->configs, $this->state);

        $reports = $tools->latestReports((int) $USER['id'], $this->universe()->current(), self::MAX_REPORTS);

        $rows = [];
        foreach ($reports as $report) {
            $data = json_decode((string) $report['message_text'], true);
            if (!is_array($data) || !isset($data['targetPlanet']['galaxy'], $data['time'])) {
                continue;
            }
            $rows[$report['planet_id']] = $this->buildRow($tools, $data, $report, $now);
        }

        return array_values($rows);
    }

    /**
     * @param array<string, mixed> $data decoded report
     * @param array<string, mixed> $report message/planet/user row
     * @return array<string, mixed>
     */
    private function buildRow(SpyReportTools $tools, array $data, array $report, int $now): array
    {
        $a = $tools->analyse($data, $now);
        $target = $data['targetPlanet'];
        $online = $report['onlinetime'] === null ? null : (int) $report['onlinetime'];
        $state = $online === null ? '' : SpyReportAnalysis::inactiveState($online, $now, INACTIVE, INACTIVE_LONG);
        $woke = $online !== null && SpyReportAnalysis::wokeUp($data['targetInactive'] ?? null, (int) $data['time'], $online, $now, INACTIVE);
        $player = $report['username'] ?? $data['targetUserName'];
        $coords = sprintf('%03d:%03d:%03d', $target['galaxy'], $target['system'], $target['planet']) . (int) $target['planet_type'];

        return $a + [
            'target' => $target,
            'player' => $player,
            'playerId' => (int) $report['id_owner'],
            'state' => $state,
            'woke' => $woke,
            'reportTime' => (int) $data['time'],
            'now' => $now,
            'sort' => [
                'coords' => $coords,
                'player' => mb_strtolower((string) $player),
                'state' => $state === 'I' ? 2 : ($state === 'i' ? 1 : 0),
                'age' => $a['age'],
                'loot' => $a['lootTotal'],
                'defense' => $a['defenseValue'],
                'fleet' => $a['fleetValue'],
                'cargos' => $a['large'],
                'distance' => $a['distance'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{inactive: bool, max_defense: int, max_age: int} $filter
     */
    private function passes(array $row, array $filter): bool
    {
        return (!$filter['inactive'] || $row['state'] !== '')
            && ($filter['max_defense'] === 0 || $row['defenseValue'] <= $filter['max_defense'])
            && ($filter['max_age'] === 0 || $row['age'] <= $filter['max_age'] * 3600);
    }
}
