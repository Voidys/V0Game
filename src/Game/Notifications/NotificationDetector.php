<?php

namespace App\Game\Notifications;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use App\Game\Core\SpyReportAnalysis;
use App\Game\Core\SpyReportTools;
use App\Game\Core\StoredData;
use App\Game\Enum\Mission;

/**
 * Finds the things a player may want to hear about: hostile or spying fleets on their way, own fleets that came home
 * and finished building / research / shipyard queues. Only players who switched the matching preference on are looked
 * at. Every event has a unique key (`atk:<fleet>`, `ret:<fleet>`, `bld:<planet>:<end time>`, ...); keys listed in
 * `notification_events` were notified already and are filtered out, so a minutely cron run never repeats itself.
 *
 * Each finder returns events of the shape
 * `['key' => string, 'user_id' => int, 'type' => NotificationDispatcher::TYPE_*, 'data' => array]`.
 */
class NotificationDetector
{
    use GameUtilities;

    /** how far back launched fleets / unprocessed queue ends are still reported (seconds) */
    public const WINDOW = 3600;
    /** a returned fleet's row is deleted by the fleet handler a little after its return time */
    private const RETURN_SLACK = 90;
    /** spy reports older than this are not watched for a target waking up (seconds) */
    private const FARM_REPORT_AGE = 1209600;
    /** a target counts as awake when it was online this recently (seconds) */
    private const FARM_ACTIVE = 900;
    /** newest spy report messages read per player */
    private const FARM_MAX_REPORTS = 1500;
    /** event keys are remembered this long (seconds) */
    private const KEEP_EVENTS = 172800;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Hostile fleets (attack, ACS attack, destruction, missile) or spy probes on their way to a planet or moon of a
     * player with the matching preference; the members of one ACS group count once.
     *
     * @return list<array<string, mixed>>
     */
    public function incomingFleets(int $now): array
    {
        $sql = 'SELECT f.`id`, f.`group`, f.`mission`, f.`target_owner`, f.`ship_amount`, f.`arrival_time`,
            f.`start_galaxy`, f.`start_system`, f.`start_planet`, f.`end_galaxy`, f.`end_system`, f.`end_planet`, f.`end_type`,
            s.`notify_attack`, s.`notify_spy`
            FROM %%FLEETS%% f INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = f.`target_owner`
            WHERE f.`status` = :outward AND f.`owner` != f.`target_owner` AND f.`start_time` > :since AND f.`arrival_time` > :now
            AND f.`mission` IN (:attack, :acs, :destroy, :missile, :spy)
            AND (s.`notify_attack` = 1 OR s.`notify_spy` = 1) ORDER BY f.`id`;';
        $rows = $this->db->select($sql, [
            ':outward' => FLEET_OUTWARD,
            ':since' => $now - self::WINDOW,
            ':now' => $now,
            ':attack' => Mission::Attack->value,
            ':acs' => Mission::Acs->value,
            ':destroy' => Mission::Destruction->value,
            ':missile' => Mission::Missile->value,
            ':spy' => Mission::Spy->value,
        ]);

        $events = [];
        foreach ($rows as $row) {
            $spy = (int) $row['mission'] === Mission::Spy->value;
            if (!(int) $row[$spy ? 'notify_spy' : 'notify_attack']) {
                continue;
            }
            $key = ($spy ? 'spy:' : 'atk:') . ((int) $row['group'] > 0 ? 'g' . $row['group'] : $row['id']);
            $events[$key] ??= [
                'key' => $key,
                'user_id' => (int) $row['target_owner'],
                'type' => $spy ? NotificationDispatcher::TYPE_SPY : NotificationDispatcher::TYPE_ATTACK,
                'data' => $row,
            ];
        }

        return $this->unseen(array_values($events));
    }

    /**
     * Own fleets that came home. Fleet rows are deleted on return, so each fleet of a player with the preference is
     * remembered in `notification_watch` (with its current return time, refreshed on every run so recalls count); a
     * watched fleet whose row has vanished around its return time came home. Fleets that vanish earlier (colonised,
     * deployed, destroyed) are forgotten silently.
     *
     * @return list<array<string, mixed>>
     */
    public function returnedFleets(int $now): array
    {
        $this->db->insert('INSERT INTO %%NOTIFICATION_WATCH%% (`fleet_id`, `user_id`, `return_time`)
            SELECT f.`id`, f.`owner`, f.`return_time` FROM %%FLEETS%% f
            INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = f.`owner`
            WHERE s.`notify_fleet_return` = 1 AND f.`mission` != :missile AND f.`status` != :destroyed AND f.`owner` != 0
            ON DUPLICATE KEY UPDATE `return_time` = VALUES(`return_time`);', [
            ':missile' => Mission::Missile->value,
            ':destroyed' => FLEET_DESTROYED,
        ]);

        $gone = $this->db->select('SELECT w.`fleet_id`, w.`user_id`, w.`return_time`, l.`ship_amount`, l.`start_id`
            FROM %%NOTIFICATION_WATCH%% w
            LEFT JOIN %%FLEETS%% f ON f.`id` = w.`fleet_id`
            LEFT JOIN %%LOG_FLEETS%% l ON l.`id` = w.`fleet_id`
            WHERE f.`id` IS NULL;');
        $events = [];
        foreach ($gone as $row) {
            $this->db->delete('DELETE FROM %%NOTIFICATION_WATCH%% WHERE `fleet_id` = :id;', [':id' => $row['fleet_id']]);
            if ((int) $row['return_time'] > $now + self::RETURN_SLACK || (int) $row['return_time'] < $now - self::WINDOW) {
                continue;
            }
            $events[] = ['key' => 'ret:' . $row['fleet_id'], 'user_id' => (int) $row['user_id'], 'type' => NotificationDispatcher::TYPE_FLEET_RETURN, 'data' => $row];
        }

        return $this->unseen($events);
    }

    /**
     * Building queues whose current element ended (b_building in the past) but has not been processed by a page
     * visit yet, for players with the building preference.
     *
     * @return list<array<string, mixed>>
     */
    public function finishedBuildings(int $now): array
    {
        $rows = $this->db->select('SELECT p.`id`, p.`id_owner`, p.`name`, p.`b_building`, p.`b_building_id`
            FROM %%PLANETS%% p INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = p.`id_owner`
            WHERE s.`notify_queue_build` = 1 AND p.`destruyed` = 0 AND p.`b_building` BETWEEN :since AND :now AND p.`b_building_id` != \'\';', [
            ':since' => $now - self::WINDOW,
            ':now' => $now,
        ]);
        $events = [];
        foreach ($rows as $row) {
            $queue = StoredData::decode($row['b_building_id']);
            if (empty($queue[0])) {
                continue;
            }
            $row['element'] = (int) $queue[0][0];
            $row['level'] = (int) $queue[0][1];
            $events[] = ['key' => 'bld:' . $row['id'] . ':' . $row['b_building'], 'user_id' => (int) $row['id_owner'], 'type' => NotificationDispatcher::TYPE_QUEUE_BUILD, 'data' => $row];
        }

        return $this->unseen($events);
    }

    /**
     * Research that ended (users.b_tech in the past) but was not processed yet, for players with the research preference.
     *
     * @return list<array<string, mixed>>
     */
    public function finishedResearch(int $now): array
    {
        $rows = $this->db->select('SELECT u.`id`, u.`b_tech`, u.`b_tech_id`, u.`b_tech_planet`
            FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = u.`id`
            WHERE s.`notify_queue_research` = 1 AND u.`b_tech_id` > 0 AND u.`b_tech` BETWEEN :since AND :now;', [
            ':since' => $now - self::WINDOW,
            ':now' => $now,
        ]);
        $events = [];
        foreach ($rows as $row) {
            $events[] = ['key' => 'res:' . $row['id'] . ':' . $row['b_tech'], 'user_id' => (int) $row['id'], 'type' => NotificationDispatcher::TYPE_QUEUE_RESEARCH, 'data' => $row];
        }

        return $this->unseen($events);
    }

    /**
     * Shipyard queues whose whole queue is built by now (b_hangar is the elapsed build time, the end time is the
     * planet's last update plus the missing build time), for players with the shipyard preference.
     *
     * @return list<array<string, mixed>>
     */
    public function finishedShipyards(int $now): array
    {
        $rows = $this->db->select('SELECT p.`id` FROM %%PLANETS%% p INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = p.`id_owner`
            WHERE s.`notify_queue_shipyard` = 1 AND p.`destruyed` = 0 AND p.`b_hangar_id` != \'\';');
        $events = [];
        foreach ($rows as $ref) {
            $planet = $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE `id` = :id;', [':id' => $ref['id']]);
            $user = $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE `id` = :id;', [':id' => $planet['id_owner'] ?? 0]);
            if (!is_array($planet) || !is_array($user)) {
                continue;
            }
            $end = $this->hangarEndTime($user, $planet);
            if ($end === null || $end > $now || $end < $now - self::WINDOW) {
                continue;
            }
            $events[] = ['key' => 'hng:' . $planet['id'] . ':' . $end, 'user_id' => (int) $user['id'], 'type' => NotificationDispatcher::TYPE_QUEUE_SHIPYARD, 'data' => ['id' => $planet['id'], 'name' => $planet['name']]];
        }

        return $this->unseen($events);
    }

    /**
     * Farm targets that woke up: for players with the farm preference, the owners of spy-report targets (reports of the
     * last 14 days, latest report per target) that were inactive at report time and are online now (last 15 minutes, not in
     * vacation). One event per player and target owner and report, so it fires once per inactive period.
     *
     * @return list<array<string, mixed>>
     */
    public function awakeFarms(int $now): array
    {
        $tools = new SpyReportTools($this->db, $this->configs, $this->state);
        $players = $this->db->select('SELECT u.`id`, u.`universe` FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% s ON s.`user_id` = u.`id`
            WHERE s.`notify_farm_awake` = 1;');
        $events = [];
        foreach ($players as $player) {
            $owners = [];
            $reports = $tools->latestReports((int) $player['id'], (int) $player['universe'], self::FARM_MAX_REPORTS, $now - self::FARM_REPORT_AGE, $now - self::FARM_ACTIVE);
            foreach ($reports as $report) {
                $data = json_decode((string) $report['message_text'], true);
                if (!is_array($data) || !isset($data['time']) || !SpyReportAnalysis::wokeUp($data['targetInactive'] ?? null, (int) $data['time'], (int) $report['onlinetime'], $now, self::FARM_ACTIVE)) {
                    continue;
                }
                $key = 'awk:' . $player['id'] . ':' . $report['id_owner'] . ':' . $report['message_id'];
                if (isset($owners[$report['id_owner']])) {
                    continue; // several planets of one owner: the newest report counts
                }
                $owners[$report['id_owner']] = true;
                $events[$key] ??= ['key' => $key, 'user_id' => (int) $player['id'], 'type' => NotificationDispatcher::TYPE_FARM_AWAKE, 'data' => ['username' => $report['username'], 'owner' => (int) $report['id_owner'], 'planet' => (int) $report['planet_id']]];
            }
        }

        return $this->unseen(array_values($events));
    }

    /**
     * When the planet's shipyard queue is completely built (Unix time), null for an empty queue.
     *
     * @param array<string, mixed> $user users row
     * @param array<string, mixed> $planet planets row
     */
    public function hangarEndTime(array $user, array $planet): ?int
    {
        $queue = StoredData::decode($planet['b_hangar_id'] ?? '');
        if (empty($queue)) {
            return null;
        }
        $userModel = $this->models()->user($user);
        $planetModel = $this->models()->planet($planet);
        $total = 0;
        foreach ($queue as $item) {
            $total += (int) $item[1] * $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, (int) $item[0]);
        }

        return (int) ceil($planet['last_update'] + $total - $planet['b_hangar']);
    }

    /**
     * Remembers the events as notified.
     *
     * @param list<array<string, mixed>> $events
     */
    public function markSeen(array $events, int $now): void
    {
        foreach ($events as $event) {
            $this->db->insert('INSERT IGNORE INTO %%NOTIFICATION_EVENTS%% (`event_key`, `created_at`) VALUES (:key, :now);', [':key' => $event['key'], ':now' => $now]);
        }
    }

    /** Forgets the keys of events older than two days (they are outside every detection window). */
    public function purge(int $now): void
    {
        $this->db->delete('DELETE FROM %%NOTIFICATION_EVENTS%% WHERE `created_at` < :before;', [':before' => $now - self::KEEP_EVENTS]);
    }

    /**
     * Drops the events whose key was notified before.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function unseen(array $events): array
    {
        if ($events === []) {
            return [];
        }
        $keys = array_column($events, 'key');
        $in = implode(',', array_map(fn(string $key): string => $this->db->quote($key), $keys));
        $seen = array_column($this->db->select('SELECT `event_key` FROM %%NOTIFICATION_EVENTS%% WHERE `event_key` IN (' . $in . ');'), 'event_key');

        return array_values(array_filter($events, static fn(array $event): bool => !in_array($event['key'], $seen, true)));
    }
}
