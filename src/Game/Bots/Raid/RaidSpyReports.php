<?php

namespace App\Game\Bots\Raid;

use App\Game\Enum\MessageType;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Reads the espionage reports the game generated for a bot (message type "spy report", JSON in the message text, grouped by the
 * spied planet id) and reduces them to what the report shows. Nothing else about the target is available: resources are always
 * in a report, fleet / defense / buildings / research only when the probes were numerous enough for that class.
 */
class RaidSpyReports
{
    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
    ) {}

    private function elements(): \App\Game\Model\ElementCatalog
    {
        return $this->state->elementCatalog ?? throw new \LogicException('game data not loaded');
    }

    /**
     * The newest report of the bot about a planet, not older than `$since` (unix time of the probes' arrival), marked as read.
     *
     * @return array<string, mixed>|null see parse(), plus `message_id`; null when there is none
     */
    public function latest(int $botId, int $planetId, int $universe, int $since): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT message_id, message_text FROM %%MESSAGES%%
            WHERE message_owner = :bot AND message_type = :type AND message_group = :grp AND message_universe = :uni
              AND message_time >= :since AND message_deleted IS NULL
            ORDER BY message_time DESC, message_id DESC LIMIT 1;',
            [':bot' => $botId, ':type' => MessageType::SpyReport->value, ':grp' => 'm_' . $planetId, ':uni' => $universe, ':since' => $since],
        );
        if (empty($row)) {
            return null;
        }
        $report = $this->parse((string) $row['message_text'], $planetId);
        if ($report === null) {
            return null;
        }
        $this->db->update('UPDATE %%MESSAGES%% SET message_unread = 0 WHERE message_id = :id;', [':id' => $row['message_id']]);
        $report['message_id'] = (int) $row['message_id'];

        return $report;
    }

    /**
     * Report JSON to the data the bot may use. `fleet` is null when the report has no fleet section (probes too few), `defense`
     * likewise; `techs` (weapons, shields, armor, spy tech of the target) only with the research section. Missiles are no defense
     * in a fight and dropped; zero entries are dropped.
     *
     * @return array{planet_id: int, time: int, resources: array<int, float>, fleet: array<int, int>|null, defense: array<int, int>|null, interceptors: int,
     *     buildings: array<int, int>|null, techs: array<int, int>|null, name: string, coords: string}|null null for text that is no report
     */
    public function parse(string $json, int $planetId): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['spyData'][900])) {
            return null;
        }
        $spy = $data['spyData'];
        $e = $this->elements();
        $res = [];
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $id) {
            $res[$id] = (float) ($spy[900][$id] ?? 0);
        }
        $target = $data['targetPlanet'] ?? [];

        return [
            'planet_id' => $planetId,
            'time' => (int) ($data['time'] ?? 0),
            'resources' => $res,
            'fleet' => isset($spy[200]) ? $this->counts((array) $spy[200], $e->ids('fleet')) : null,
            'defense' => isset($spy[400]) ? $this->counts((array) $spy[400], $e->ids('defense')) : null,
            'interceptors' => (int) floor((float) ($spy[400][INTERCEPTOR_MISSILE] ?? 0)),
            'buildings' => isset($spy[0]) ? $this->counts((array) $spy[0], $e->ids('build')) : null,
            'techs' => isset($spy[100]) ? $this->counts((array) $spy[100], $e->ids('tech')) : null,
            'name' => (string) ($target['name'] ?? ''),
            'coords' => ($target['galaxy'] ?? '?') . ':' . ($target['system'] ?? '?') . ':' . ($target['planet'] ?? '?'),
        ];
    }

    /**
     * Non-empty amounts of the listed element ids.
     *
     * @param array<int|string, mixed> $section report section (element id => amount)
     * @param list<int> $allowed element ids to keep
     * @return array<int, int>
     */
    private function counts(array $section, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $id) {
            $amount = (int) floor((float) ($section[$id] ?? 0));
            if ($amount > 0) {
                $out[$id] = $amount;
            }
        }

        return $out;
    }
}
