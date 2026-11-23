<?php

namespace App\Game\Notifications;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\ErrorReporter;
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

    /** @var array<string, array<int, true>>|null user ids that switched a notify_* flag on, by column; loaded once per run */
    private ?array $optedIn = null;

    /** every notify_* column the finders look at */
    private const FLAGS = ['notify_attack', 'notify_spy', 'notify_fleet_return', 'notify_queue_build', 'notify_queue_research', 'notify_queue_shipyard', 'notify_farm_awake'];

    /**
     * Ids of the players with a notify flag switched on (one query for all flags, kept for the life of the detector).
     *
     * @return array<int, true> keyed by user id
     */
    private function optedIn(string $flag): array
    {
        if ($this->optedIn === null) {
            $this->optedIn = array_fill_keys(self::FLAGS, []);
            $rows = $this->db->select('SELECT `user_id`, ' . implode(', ', array_map(static fn(string $f): string => '`' . $f . '`', self::FLAGS)) . '
                FROM %%USERS_SETTINGS%% WHERE ' . implode(' = 1 OR ', array_map(static fn(string $f): string => '`' . $f . '`', self::FLAGS)) . ' = 1;');
            foreach ($rows as $row) {
                foreach (self::FLAGS as $f) {
                    if ((int) $row[$f] === 1) {
                        $this->optedIn[$f][(int) $row['user_id']] = true;
                    }
                }
            }
        }

        return $this->optedIn[$flag];
    }

    /** SQL fragment restricting a column to the opted-in players (`AND col IN (...)`); the caller returns early for an empty set. */
    private function ownerIn(string $column, array $optedIn): string
    {
        return count($optedIn) > 2000 ? '' : ' AND ' . $column . ' IN (' . implode(',', array_keys($optedIn)) . ')';
    }

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
            f.`target_owner` AS `notify_user`
            FROM %%FLEETS%% f
            WHERE f.`status` = :outward AND f.`owner` != f.`target_owner` AND f.`start_time` > :since AND f.`arrival_time` > :now
            AND f.`mission` IN (:attack, :acs, :destroy, :missile, :spy)
            {OWNERS} ORDER BY f.`id`;';
        $attack = $this->optedIn('notify_attack');
        $spyers = $this->optedIn('notify_spy');
        if ($attack === [] && $spyers === []) {
            return [];
        }
        $sql = str_replace('{OWNERS}', $this->ownerIn('f.`target_owner`', $attack + $spyers), $sql);
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
            $owner = (int) $row['target_owner'];
            if (!isset(($spy ? $spyers : $attack)[$owner])) {
                continue;
            }
            unset($row['notify_user']);
            $row['notify_attack'] = isset($attack[$owner]) ? 1 : 0;
            $row['notify_spy'] = isset($spyers[$owner]) ? 1 : 0;
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
        $this->refreshWatch();

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
     * Brings notification_watch in line with the fleets of players with the return preference; only rows that are new
     * or whose return time changed are written.
     */
    private function refreshWatch(): void
    {
        $optedIn = $this->optedIn('notify_fleet_return');
        if ($optedIn === []) {
            return;
        }
        $fleets = $this->db->select('SELECT f.`id`, f.`owner`, f.`return_time` FROM %%FLEETS%% f
            WHERE f.`mission` != :missile AND f.`status` != :destroyed AND f.`owner` != 0' . $this->ownerIn('f.`owner`', $optedIn) . ';', [
            ':missile' => Mission::Missile->value,
            ':destroyed' => FLEET_DESTROYED,
        ]);
        $watched = array_column($this->db->select('SELECT `fleet_id`, `return_time` FROM %%NOTIFICATION_WATCH%%;'), 'return_time', 'fleet_id');
        foreach ($fleets as $fleet) {
            if (!isset($optedIn[(int) $fleet['owner']]) || (isset($watched[$fleet['id']]) && (int) $watched[$fleet['id']] === (int) $fleet['return_time'])) {
                continue;
            }
            $this->db->insert('INSERT INTO %%NOTIFICATION_WATCH%% (`fleet_id`, `user_id`, `return_time`) VALUES (:id, :user, :time)
                ON DUPLICATE KEY UPDATE `return_time` = VALUES(`return_time`);', [':id' => $fleet['id'], ':user' => $fleet['owner'], ':time' => $fleet['return_time']]);
        }
    }

    /**
     * Building queues whose current element ended (b_building in the past) but has not been processed by a page
     * visit yet, for players with the building preference.
     *
     * @return list<array<string, mixed>>
     */
    public function finishedBuildings(int $now): array
    {
        $optedIn = $this->optedIn('notify_queue_build');
        if ($optedIn === []) {
            return [];
        }
        $rows = $this->db->select('SELECT p.`id`, p.`id_owner`, p.`name`, p.`b_building`, p.`b_building_id`
            FROM %%PLANETS%% p
            WHERE p.`destruyed` = 0 AND p.`b_building` BETWEEN :since AND :now AND p.`b_building_id` != \'\'' . $this->ownerIn('p.`id_owner`', $optedIn) . ';', [
            ':since' => $now - self::WINDOW,
            ':now' => $now,
        ]);
        $events = [];
        foreach ($rows as $row) {
            if (!isset($optedIn[(int) $row['id_owner']])) {
                continue;
            }
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
        $optedIn = $this->optedIn('notify_queue_research');
        if ($optedIn === []) {
            return [];
        }
        $rows = $this->db->select('SELECT u.`id`, u.`b_tech`, u.`b_tech_id`, u.`b_tech_planet`
            FROM %%USERS%% u
            WHERE u.`b_tech_id` > 0 AND u.`b_tech` BETWEEN :since AND :now' . $this->ownerIn('u.`id`', $optedIn) . ';', [
            ':since' => $now - self::WINDOW,
            ':now' => $now,
        ]);
        $events = [];
        foreach ($rows as $row) {
            if (!isset($optedIn[(int) $row['id']])) {
                continue;
            }
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
        $optedIn = $this->optedIn('notify_queue_shipyard');
        if ($optedIn === []) {
            return [];
        }
        $planets = $this->db->select('SELECT p.* FROM %%PLANETS%% p
            WHERE p.`destruyed` = 0 AND p.`b_hangar_id` != \'\'' . $this->ownerIn('p.`id_owner`', $optedIn) . ' ORDER BY p.`id`;');
        $planets = array_values(array_filter($planets, static fn(array $planet): bool => isset($optedIn[(int) $planet['id_owner']])));
        $users = $this->usersById(array_map('intval', array_column($planets, 'id_owner')));
        $events = [];
        foreach ($planets as $planet) {
            $user = $users[(int) $planet['id_owner']] ?? null;
            if ($user === null) {
                continue;
            }
            try {
                $end = $this->hangarEndTime($user, $planet);
            } catch (\Throwable $exception) {
                // one malformed queue must not stop the whole run
                (new ErrorReporter($this->db, $this->configs, $this->state))->reportIsolated($exception, 'shipyard notification of planet ' . $planet['id']);
                continue;
            }
            if ($end === null || $end > $now || $end < $now - self::WINDOW) {
                continue;
            }
            $events[] = ['key' => 'hng:' . $planet['id'] . ':' . $end, 'user_id' => (int) $user['id'], 'type' => NotificationDispatcher::TYPE_QUEUE_SHIPYARD, 'data' => ['id' => $planet['id'], 'name' => $planet['name']]];
        }

        return $this->unseen($events);
    }

    /**
     * Users rows by id, loaded with one query.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function usersById(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $users = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->select('SELECT * FROM %%USERS%% WHERE `id` IN (' . implode(',', $chunk) . ');') as $user) {
                $users[(int) $user['id']] = $user;
            }
        }

        return $users;
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
        $optedIn = $this->optedIn('notify_farm_awake');
        $players = $optedIn === [] ? [] : $this->db->select('SELECT u.`id`, u.`universe` FROM %%USERS%% u
            WHERE u.`id` IN (' . implode(',', array_keys($optedIn)) . ') ORDER BY u.`id`;');
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
