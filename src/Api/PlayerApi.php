<?php

declare(strict_types=1);

namespace App\Api;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;
use App\Game\Core\StoredData;

/**
 * The data of the read-only player API: profile, empire and the player's own spy and combat reports as plain arrays
 * (JSON-ready). Reads only, except that the planets are brought up to date through ResourceUpdate (saved, as a page view of the
 * empire overview would), so the numbers match what the game shows right now.
 */
final class PlayerApi
{
    /** hard cap of reports per call */
    public const MAX_REPORTS = 50;
    public const DEFAULT_REPORTS = 20;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Profile of the player: id, name, universe, points and rank (overall statistics), alliance id and planet count.
     *
     * @return array<string, mixed>
     */
    public function me(int $userId): array
    {
        $row = $this->db->selectSingle(
            'SELECT u.`id`, u.`username`, u.`universe`, u.`ally_id`, u.`urlaubs_modus`, s.`total_points`, s.`total_rank`
            FROM %%USERS%% u LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1 WHERE u.`id` = :id;',
            [':id' => $userId],
        );
        $planets = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE `id_owner` = :id AND `destruyed` = 0 AND `planet_type` = \'1\';',
            [':id' => $userId],
            'c',
        );

        return [
            'id' => (int) $row['id'],
            'name' => $row['username'],
            'universe' => (int) $row['universe'],
            'alliance_id' => (int) $row['ally_id'],
            'vacation' => (int) $row['urlaubs_modus'] === 1,
            'points' => (int) ($row['total_points'] ?? 0),
            'rank' => (int) ($row['total_rank'] ?? 0),
            'planets' => $planets,
        ];
    }

    /**
     * All planets and moons of the player with coordinates, fields, current resources (updated to $now and saved like a page view), hourly production, storage,
     * energy, building levels, ships and defense, plus the research levels. Needs the booted game (element catalog, universe config).
     *
     * @return array<string, mixed>
     */
    public function empire(int $userId, int $now): array
    {
        // like a page view of the empire overview: lock the user and planet rows, bring every planet up to date and save
        // (queues, messages and wreckfield expiry are applied exactly once), commit, then read
        $this->db->startTransaction();
        $user = $this->db->selectSingle(
            'SELECT * FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` WHERE u.`id` = :id FOR UPDATE;',
            [':id' => $userId],
        );
        $rows = $this->db->select(
            'SELECT * FROM %%PLANETS%% WHERE `id_owner` = :id AND `destruyed` = 0 ORDER BY `id` ASC FOR UPDATE;',
            [':id' => $userId],
        );

        $e = $this->state->elementCatalog;
        $update = new ResourceUpdate($this->db, $this->configs, $this->state);
        $current = [];
        foreach ($rows as $row) {
            [$user, $planet] = $update->CalcResource($user, $row, true, $now, false);
            $current[] = $planet;
        }
        $this->db->commit();

        $planets = [];
        foreach ($current as $planet) {
            $planets[] = [
                'id' => (int) $planet['id'],
                'name' => $planet['name'],
                'type' => (int) $planet['planet_type'] === 3 ? 'moon' : 'planet',
                'coords' => ['galaxy' => (int) $planet['galaxy'], 'system' => (int) $planet['system'], 'planet' => (int) $planet['planet']],
                'fields' => ['used' => (int) $planet['field_current'], 'max' => (int) $planet['field_max']],
                'temperature' => ['min' => (int) $planet['temp_min'], 'max' => (int) $planet['temp_max']],
                'resources' => $this->resources($planet, ''),
                'storage' => $this->resources($planet, '_max'),
                'production_per_hour' => $this->resources($planet, '_perhour'),
                'energy' => ['production' => (int) $planet['energy'], 'consumption' => abs((int) $planet['energy_used'])],
                'buildings' => $this->levels($e->ids('build'), $planet),
                'ships' => $this->levels($e->ids('fleet'), $planet),
                'defense' => $this->levels($e->ids('defense'), $planet),
            ];
        }

        return ['research' => $this->levels($e->ids('tech'), $user), 'planets' => $planets];
    }

    /**
     * Spy reports the player received (message type 0), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function spyReports(int $userId, int $universe, int $since, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT m.`message_id`, m.`message_time`, m.`message_text` FROM %%MESSAGES%% m
            INNER JOIN %%USERS_TO_MESSAGES%% um ON um.`message_id` = m.`message_id`
            WHERE um.`user_id` = :user AND um.`deleted_at` IS NULL AND m.`message_type` = 0 AND m.`message_universe` = :uni AND m.`message_time` >= :since
            ORDER BY m.`message_time` DESC, m.`message_id` DESC LIMIT ' . $this->limit($limit) . ';',
            [':user' => $userId, ':uni' => $universe, ':since' => $since],
        );

        $reports = [];
        foreach ($rows as $row) {
            $data = json_decode((string) $row['message_text'], true);
            if (!is_array($data) || !isset($data['targetPlanet'])) {
                continue; // old-format (HTML) report
            }
            $reports[] = [
                'id' => (int) $row['message_id'],
                'time' => (int) $row['message_time'],
                'target' => [
                    'player' => $data['targetUserName'] ?? '',
                    'planet' => $data['targetPlanet']['name'] ?? '',
                    'type' => (int) ($data['targetPlanet']['planet_type'] ?? 1) === 3 ? 'moon' : 'planet',
                    'coords' => [
                        'galaxy' => (int) ($data['targetPlanet']['galaxy'] ?? 0),
                        'system' => (int) ($data['targetPlanet']['system'] ?? 0),
                        'planet' => (int) ($data['targetPlanet']['planet'] ?? 0),
                    ],
                ],
                'data' => $this->spyData((array) ($data['spyData'] ?? [])),
                'cargo_needed' => ['small' => $data['smallCargoNeeded'] ?? null, 'large' => $data['largeCargoNeeded'] ?? null],
                'counter_espionage_chance' => $data['targetChance'] ?? null,
            ];
        }

        return $reports;
    }

    /**
     * Combat reports the player took part in (as attacker or defender), newest first, in short form: result, location, loot, debris, moon chance.
     * The result is from the attacker's view (`a` attacker won, `r` draw, `w` defender won).
     *
     * @return list<array<string, mixed>>
     */
    public function combatReports(int $userId, int $since, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT `rid`, `raport`, `time`, `attacker`, `defender`, `battleengine` FROM %%RW%%
            WHERE `time` >= :since AND (FIND_IN_SET(:user, `attacker`) > 0 OR FIND_IN_SET(:user, `defender`) > 0)
            ORDER BY `time` DESC LIMIT ' . $this->limit($limit) . ';',
            [':since' => $since, ':user' => (string) $userId],
        );

        $reports = [];
        foreach ($rows as $row) {
            $report = $row['battleengine'] === 'rust'
                ? json_decode((string) decompressFromBase64($row['raport']), true)
                : StoredData::decode($row['raport']);
            if (!is_array($report)) {
                continue;
            }
            $coords = (array) ($report['koords'] ?? []);
            $reports[] = [
                'id' => $row['rid'],
                'time' => (int) $row['time'],
                'role' => in_array((string) $userId, explode(',', (string) $row['attacker']), true) ? 'attacker' : 'defender',
                'attackers' => $this->ids($row['attacker']),
                'defenders' => $this->ids($row['defender']),
                'result' => (string) ($report['result'] ?? ''),
                'rounds' => count((array) ($report['rounds'] ?? [])),
                'coords' => ['galaxy' => (int) ($coords[0] ?? 0), 'system' => (int) ($coords[1] ?? 0), 'planet' => (int) ($coords[2] ?? 0), 'type' => (int) ($coords[3] ?? 1) === 3 ? 'moon' : 'planet'],
                'loot' => $this->resourceTriplet((array) ($report['steal'] ?? [])),
                'debris' => $this->resourceTriplet((array) ($report['debris'] ?? [])),
                'url' => '/report/' . $row['rid'],
            ];
        }

        return $reports;
    }

    private function limit(int $limit): int
    {
        return max(1, min(self::MAX_REPORTS, $limit));
    }

    /** @return list<int> numeric ids of a comma separated list (markers like "sim:1" are dropped) */
    private function ids(?string $list): array
    {
        return array_values(array_map('intval', array_filter(explode(',', (string) $list), 'ctype_digit')));
    }

    /**
     * @param array<int|string, mixed> $values by resource id 901..903
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function resourceTriplet(array $values): array
    {
        return ['metal' => (int) ($values[901] ?? 0), 'crystal' => (int) ($values[902] ?? 0), 'deuterium' => (int) ($values[903] ?? 0)];
    }

    /**
     * @param array<string, mixed> $planet
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function resources(array $planet, string $suffix): array
    {
        return [
            'metal' => (int) floor((float) $planet['metal' . $suffix]),
            'crystal' => (int) floor((float) $planet['crystal' . $suffix]),
            'deuterium' => (int) floor((float) $planet['deuterium' . $suffix]),
        ];
    }

    /**
     * Levels or counts of elements by column name (e.g. `metal_mine`), for the columns the row has.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    private function levels(array $ids, array $row): array
    {
        $out = [];
        foreach ($ids as $id) {
            $column = $this->state->elementCatalog->column($id);
            if (array_key_exists($column, $row)) {
                $out[$column] = (int) $row[$column];
            }
        }

        return $out;
    }

    /**
     * Spy data (class id => element id => amount) with the elements named by their column, so the groups read as resources, ships, defense,
     * buildings and research.
     *
     * @param array<int|string, mixed> $spyData
     * @return array<string, array<string, int>>
     */
    private function spyData(array $spyData): array
    {
        $groups = [900 => 'resources', 200 => 'ships', 400 => 'defense', 0 => 'buildings', 100 => 'research'];
        $out = [];
        foreach ($spyData as $classId => $elements) {
            $group = $groups[(int) $classId] ?? 'class_' . $classId;
            foreach ((array) $elements as $elementId => $amount) {
                $name = $this->state->elementCatalog->has((int) $elementId) ? $this->state->elementCatalog->column((int) $elementId) : (string) $elementId;
                $out[$group][$name] = (int) $amount;
            }
        }

        return $out;
    }
}
