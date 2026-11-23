<?php

namespace App\Game\Cronjob;

use App\Game\Core\Language;
use App\Game\Notifications\NotificationDetector;
use App\Game\Notifications\NotificationDispatcher;

/**
 * Notifications (every minute): asks NotificationDetector for new hostile / spying fleets, returned fleets and
 * finished queues and hands each one to the NotificationDispatcher in the receiving player's language.
 */
class NotificationCronjob extends AbstractCronjobTask
{
    /** @var array<string, Language> language packs by code */
    private array $languages = [];

    /**
     * Detects the events since the last run, sends them (event first marked as seen, so a failing channel never causes
     * a flood) and prunes old event keys.
     */
    public function run(): void
    {
        $now = $this->state->runtime->timestamp;
        $detector = new NotificationDetector($this->db, $this->configs, $this->state);
        $events = [
            ...$detector->incomingFleets($now),
            ...$detector->returnedFleets($now),
            ...$detector->finishedBuildings($now),
            ...$detector->finishedResearch($now),
            ...$detector->finishedShipyards($now),
            ...$detector->awakeFarms($now),
        ];
        $detector->markSeen($events, $now);

        $this->dispatch($events);

        $detector->purge($now);
    }

    /**
     * Sends the events as one batch: the receiving players (language, timezone, universe) are loaded with one query.
     *
     * @param list<array<string, mixed>> $events
     */
    private function dispatch(array $events): void
    {
        if ($events === []) {
            return;
        }
        $ids = array_values(array_unique(array_map(static fn(array $event): int => (int) $event['user_id'], $events)));
        $users = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->db->select('SELECT u.`id`, u.`universe`, s.`lang`, s.`timezone` FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = u.`id` WHERE u.`id` IN (' . implode(',', $chunk) . ');');
            foreach ($rows as $row) {
                $users[(int) $row['id']] = $row;
            }
        }
        $notifications = [];
        foreach ($events as $event) {
            $user = $users[(int) $event['user_id']] ?? null;
            if ($user === null) {
                continue;
            }
            $LNG = $this->language((string) $user['lang']);
            [$title, $body, $path] = $this->texts($event, $LNG, (string) $user['timezone']);
            $prefix = (int) $user['universe'] > 1 ? 'uni' . (int) $user['universe'] . '/' : '';
            $notifications[] = ['user_id' => (int) $event['user_id'], 'type' => $event['type'], 'title' => $title, 'body' => $body, 'url' => $prefix . $path];
        }
        $this->gameUtility(NotificationDispatcher::class)->notifyBatch($notifications);
    }

    private function language(string $code): Language
    {
        if (!isset($this->languages[$code])) {
            $this->languages[$code] = new Language($this->configs, $code);
            $this->languages[$code]->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        }

        return $this->languages[$code];
    }

    /**
     * Title, body and target page (relative to the universe) of an event, in the language of the player.
     *
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function texts(array $event, Language $LNG, string $timezone): array
    {
        $data = $event['data'];
        $fmt = fn(int|string $time): string => $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $time, $timezone);
        $coords = static fn(string $prefix): string => '[' . $data[$prefix . 'galaxy'] . ':' . $data[$prefix . 'system'] . ':' . $data[$prefix . 'planet'] . ']';

        switch ($event['type']) {
            case NotificationDispatcher::TYPE_ATTACK:
                return [$LNG['notif_attack_title'], $LNG->format('notif_attack_body', $LNG['type_mission_' . (int) $data['mission']], $coords('start_'), $coords('end_'), $fmt($data['arrival_time'])), 'game'];
            case NotificationDispatcher::TYPE_SPY:
                return [$LNG['notif_spy_title'], $LNG->format('notif_spy_body', $coords('start_'), $coords('end_'), $fmt($data['arrival_time'])), 'game'];
            case NotificationDispatcher::TYPE_FLEET_RETURN:
                return [$LNG['notif_return_title'], $LNG->format('notif_return_body', (int) ($data['ship_amount'] ?? 0)), 'game/fleet'];
            case NotificationDispatcher::TYPE_QUEUE_BUILD:
                return [$LNG['notif_build_title'], $LNG->format('notif_build_body', $LNG['tech'][$data['element']] ?? $data['element'], $data['level'], $data['name']), 'game/buildings'];
            case NotificationDispatcher::TYPE_QUEUE_RESEARCH:
                return [$LNG['notif_research_title'], $LNG->format('notif_research_body', $LNG['tech'][$data['b_tech_id']] ?? $data['b_tech_id']), 'game/research'];
            case NotificationDispatcher::TYPE_FARM_AWAKE:
                return [$LNG['notif_farm_awake_title'], $LNG->format('notif_farm_awake_body', $data['username']), 'game/farms'];
            default:
                return [$LNG['notif_shipyard_title'], $LNG->format('notif_shipyard_body', $data['name']), 'game/shipyard'];
        }
    }
}
