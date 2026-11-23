<?php

declare(strict_types=1);

namespace App\Game\Admin\AbuseReport;

use App\Game\Enum\Mission;
use App\Game\Core\Database;
use App\Game\Core\FleetString;
use App\Game\Model\ElementCatalog;

/**
 * Read-only queries behind the admin "Abuse report" (audit F-02, F-15, F-16, F-19 and the operator checklist): one-sided
 * transports/holds, repeated market trades with a skewed value ratio, rebuild-protection grants and recalls at or after
 * the arrival time. Every query is limited to LIMIT rows and reads only the log tables (nothing is written).
 *
 * Value weighting: all cargo is converted to metal units with the universe's stored market ratios
 * (marketplace.metDeutRatio / crysDeutRatio, defaults 3.0 and 2.0): 1 metal = 1, 1 crystal = metDeut / crysDeut,
 * 1 deuterium = metDeut. With the defaults that is 1 : 1.5 : 3.
 *
 * Linked accounts: two players count as linked when they share the same non-empty users.user_lastip or a multi group
 * (multi_to_users); a group both players were marked as known/allowed in is reported as "known". A shared /24 IPv4 (or
 * /64 IPv6) prefix of user_lastip with different addresses is only a weaker "same subnet" marker (`subnet`).
 *
 * Device marker (`device`): the two players share a device_hash in USER_DEVICES (strong, like the IP); without that table
 * there is no device data and the flag stays off.
 *
 * Transfers: transports and holds count with their cargo; transfer missions (ships handed over) count with their cargo
 * plus the ships at their build cost (the log keeps the fleet string), so the ship value needs the element catalog.
 */
final class AbuseReportQuery
{
    public const LIMIT = 100;
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 90;
    /** smallest one-way cargo value (metal units) per pair that is listed */
    public const MIN_TRANSFER_VALUE = 1000;
    /** a pair is one-sided when the return flow is at most this share of the outgoing value (i.e. 80 % one way) */
    public const MAX_RETURN_SHARE = 0.25;
    /** trades between the same pair that make it a "repeated" pair */
    public const MIN_REPEATED_TRADES = 3;
    /** value ratio between the two sides of a pair that is flagged (the market's push tolerance of 30 %) */
    public const TRADE_OUTLIER_RATIO = 1.3;

    /** look-back window (days) of the trade graph, fixed independent of the `days` filter */
    public const GRAPH_DAYS = 30;
    /** an edge of the trade graph counts from this many MIN_TRANSFER_VALUEs moved in total (both directions) ... */
    public const GRAPH_MIN_VALUE_FACTOR = 5;
    /** ... and from this many flows (transport/hold/transfer fleets, trades, looting attacks) */
    public const GRAPH_MIN_FLOWS = 3;
    /** clusters listed at most */
    public const GRAPH_MAX_CLUSTERS = 50;
    /** rows read per source of the trade graph */
    public const GRAPH_MAX_SOURCE_ROWS = 20000;
    /** accounts listed in the activity hint (fleet launches of the last 24 hours) */
    public const ACTIVITY_LIMIT = 20;

    /** cap of the directed pairs / transfer log rows read before the pair filter (the output stays capped by LIMIT) */
    private const MAX_SOURCE_ROWS = 5000;

    private const DEFAULT_MET_DEUT = 3.0;
    private const DEFAULT_CRYS_DEUT = 2.0;

    /** @var string|null|false prefixed device table name, null = does not exist, false = not looked up yet */
    private string|false|null $deviceTable = false;

    public function __construct(private readonly Database $db, private readonly ?ElementCatalog $elements = null) {}

    /**
     * @return array{
     *     days: int,
     *     since: int,
     *     weights: array{metal: float, crystal: float, deuterium: float},
     *     transfers: list<array<string, mixed>>,
     *     trades: list<array<string, mixed>>,
     *     protections: list<array<string, mixed>>,
     *     recalls: list<array<string, mixed>>,
     *     graph: list<array<string, mixed>>,
     *     activity: list<array<string, mixed>>
     * }
     */
    public function report(int $universe, int $days, int $now): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $since = $now - $days * 86400;
        $weights = $this->weights($universe);

        return [
            'days' => $days,
            'since' => $since,
            'weights' => $weights,
            'transfers' => $this->transfers($universe, $since, $weights),
            'trades' => $this->trades($universe, $since, $weights),
            'protections' => $this->protections($universe, $since, $weights),
            'recalls' => $this->recalls($universe, $since),
            'graph' => $this->tradeGraph($universe, $now - self::GRAPH_DAYS * 86400, $weights),
            'activity' => $this->activity($universe, $now),
        ];
    }

    /**
     * Link markers between two players (same flags as the report rows) plus their names.
     *
     * @return array{ip: bool, subnet: bool, multi: bool, multiKnown: bool, device: bool, names: array<int, string>}
     */
    public function pairLinks(int $universe, int $a, int $b): array
    {
        $links = $this->linkData([$a, $b], $universe);

        return $this->compare($links, $a, $b) + ['names' => $links['names']];
    }

    /** @return array{metal: float, crystal: float, deuterium: float} value of one unit in metal units */
    public function weights(int $universe): array
    {
        $row = $this->db->selectSingle('SELECT `metDeutRatio`, `crysDeutRatio` FROM %%MARKETPLACE%% WHERE `universeId` = :universe;', [':universe' => $universe]);
        $metDeut = is_array($row) ? (float) $row['metDeutRatio'] : 0.0;
        $crysDeut = is_array($row) ? (float) $row['crysDeutRatio'] : 0.0;
        $metDeut = $metDeut > 0.0 ? $metDeut : self::DEFAULT_MET_DEUT;
        $crysDeut = $crysDeut > 0.0 ? $crysDeut : self::DEFAULT_CRYS_DEUT;

        return ['metal' => 1.0, 'crystal' => $metDeut / $crysDeut, 'deuterium' => $metDeut];
    }

    /**
     * Pairs where transports/holds/transfers (not recalled) of the window carried at least MIN_TRANSFER_VALUE one way and
     * at most MAX_RETURN_SHARE of that value came back, biggest net flow first. Transfer missions add their ships at build
     * cost to the value.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>> from, to, count, sent, back, backCount, net, last (timestamp) + link flags and names
     */
    private function transfers(int $universe, int $since, array $weights): array
    {
        $flow = $this->transferFlow($universe, $since, $weights, self::MAX_SOURCE_ROWS);

        $rows = [];
        foreach ($flow as $from => $targets) {
            foreach ($targets as $to => [$count, $sent, $last]) {
                $back = $flow[$to][$from] ?? [0, 0.0, 0];
                if ($sent >= self::MIN_TRANSFER_VALUE && $back[1] <= $sent * self::MAX_RETURN_SHARE) {
                    $rows[] = [
                        'from' => $from, 'to' => $to, 'count' => $count, 'sent' => $sent, 'last' => $last,
                        'back' => $back[1], 'backCount' => $back[0], 'net' => $sent - $back[1],
                    ];
                }
            }
        }
        usort($rows, static fn(array $x, array $y): int => $y['net'] <=> $x['net']);

        return $this->withLinks(array_slice($rows, 0, self::LIMIT), 'from', 'to', $universe);
    }

    /**
     * Directed value flows of transports/holds/transfers (not recalled) in the window.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @param int $limit cap of the pair / transfer rows read
     * @return array<int, array<int, array{0: int, 1: float, 2: int}>> flow[from][to] = [count, value, last]
     */
    private function transferFlow(int $universe, int $since, array $weights, int $limit): array
    {
        $value = sprintf('SUM(`resource_metal` + `resource_crystal` * %F + `resource_deuterium` * %F)', $weights['crystal'], $weights['deuterium']);
        // flow[from][to] = [count, value, last]
        $flow = [];
        $add = static function (int $from, int $to, int $count, float $value, int $last) use (&$flow): void {
            $entry = $flow[$from][$to] ?? [0, 0.0, 0];
            $flow[$from][$to] = [$entry[0] + $count, $entry[1] + $value, max($entry[2], $last)];
        };

        $pairs = $this->db->select(
            "SELECT `owner` AS a, `target_owner` AS b, COUNT(*) AS n, $value AS v, MAX(`start_time`) AS last
            FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `mission` IN (:transport, :hold) AND `owner` <> `target_owner` AND `target_owner` > 0
                AND `hasCanceled` = 0 AND `start_time` >= :since
            GROUP BY `owner`, `target_owner` ORDER BY v DESC LIMIT " . $limit . ';',
            [':universe' => $universe, ':transport' => Mission::Transport->value, ':hold' => Mission::Hold->value, ':since' => $since],
        );
        foreach ($pairs as $row) {
            $add((int) $row['a'], (int) $row['b'], (int) $row['n'], (float) $row['v'], (int) $row['last']);
        }

        $transfers = $this->db->select(
            'SELECT `owner`, `target_owner`, `ships`, `start_time`, `resource_metal`, `resource_crystal`, `resource_deuterium`
            FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `mission` = :transfer AND `owner` <> `target_owner` AND `target_owner` > 0
                AND `hasCanceled` = 0 AND `start_time` >= :since
            ORDER BY `start_time` DESC LIMIT ' . $limit . ';',
            [':universe' => $universe, ':transfer' => Mission::Transfer->value, ':since' => $since],
        );
        foreach ($transfers as $row) {
            $cargo = (float) $row['resource_metal'] + (float) $row['resource_crystal'] * $weights['crystal'] + (float) $row['resource_deuterium'] * $weights['deuterium'];
            $add((int) $row['owner'], (int) $row['target_owner'], 1, $cargo + $this->shipValue((string) $row['ships'], $weights), (int) $row['start_time']);
        }

        return $flow;
    }

    /**
     * Build cost of a fleet string ("id,count;id,count") in metal units; unknown ids and a missing catalog count as 0.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     */
    private function shipValue(string $ships, array $weights): float
    {
        if ($this->elements === null || $ships === '') {
            return 0.0;
        }
        $value = 0.0;
        foreach ((new FleetString())->unserialize($ships) as $id => $count) {
            $cost = $this->elements->find((int) $id)?->cost;
            if ($cost !== null) {
                $value += ($cost->metal + $cost->crystal * $weights['crystal'] + $cost->deuterium * $weights['deuterium']) * (float) $count;
            }
        }

        return $value;
    }

    /**
     * Resource trades (market type 0) between the same two players, at least MIN_REPEATED_TRADES times in the window.
     * Both sides of every trade are valued with the reference ratios; `ratio` is the larger side divided by the smaller
     * one (1.00 = at the reference) and the pair is an outlier above TRADE_OUTLIER_RATIO. Largest imbalance first.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>>
     */
    private function trades(int $universe, int $since, array $weights): array
    {
        $unit = static fn(string $type, string $amount): string => sprintf(
            '(%s * CASE %s WHEN 1 THEN 1 WHEN 2 THEN %F WHEN 3 THEN %F ELSE 0 END)',
            $amount,
            $type,
            $weights['crystal'],
            $weights['deuterium'],
        );
        $offered = $unit('t.`resource_type`', 't.`resource_amount`');
        $wanted = $unit('t.`ex_resource_type`', 't.`ex_resource_amount`');

        $sql = "SELECT LEAST(s.`owner`, b.`owner`) AS lo, GREATEST(s.`owner`, b.`owner`) AS hi, COUNT(*) AS `count`,
                SUM(CASE WHEN s.`owner` < b.`owner` THEN $offered ELSE $wanted END) AS loGave,
                SUM(CASE WHEN s.`owner` < b.`owner` THEN $wanted ELSE $offered END) AS hiGave,
                UNIX_TIMESTAMP(MAX(t.`buy_time`)) AS last
            FROM %%TRADES%% t
            JOIN %%LOG_FLEETS%% s ON s.`id` = t.`seller_fleet_id`
            JOIN %%LOG_FLEETS%% b ON b.`id` = t.`buyer_fleet_id`
            WHERE t.`transaction_type` = 0 AND t.`buy_time` >= :since AND s.`universe` = :universe AND s.`owner` <> b.`owner`
            GROUP BY lo, hi
            HAVING `count` >= :minTrades
            ORDER BY ABS(loGave - hiGave) DESC
            LIMIT " . self::LIMIT . ';';

        $rows = $this->db->select($sql, [
            ':since' => $this->db->formatDate($since),
            ':universe' => $universe,
            ':minTrades' => self::MIN_REPEATED_TRADES,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $lo = (float) $row['loGave'];
            $hi = (float) $row['hiGave'];
            $big = max($lo, $hi);
            $small = min($lo, $hi);
            $row['net'] = $big - $small;
            $row['giver'] = $lo >= $hi ? (int) $row['lo'] : (int) $row['hi'];
            $row['receiver'] = $lo >= $hi ? (int) $row['hi'] : (int) $row['lo'];
            $row['ratio'] = $small > 0.0 ? $big / $small : null;
            $row['outlier'] = $small <= 0.0 ? $big > 0.0 : $big / $small > self::TRADE_OUTLIER_RATIO;
            $out[] = $row;
        }

        return $this->withLinks($out, 'giver', 'receiver', $universe);
    }

    /**
     * Players that got a rebuild protection in the window (rebuild_protection_locked_until is set to the fight time plus
     * one month). The fight is found through the combat report stored at that exact time; the opponents of the
     * protected player (the other side of the report) are checked for links and for the loot of their fleets of that report.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>> user, name, granted, report id, opponents (id, name, loot, linked flags), loot, linked
     */
    private function protections(int $universe, int $since, array $weights): array
    {
        $users = $this->db->select(
            'SELECT `id`, `username`, `rebuild_protection_locked_until` - ' . TIME_1_MONTH . ' AS granted
            FROM %%USERS%% WHERE `universe` = :universe AND `rebuild_protection_locked_until` >= :minLocked
            ORDER BY `rebuild_protection_locked_until` DESC LIMIT ' . self::LIMIT . ';',
            [':universe' => $universe, ':minLocked' => $since + TIME_1_MONTH],
        );

        $out = [];
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $reports = $this->db->select(
                'SELECT `rid`, `attacker`, `defender` FROM %%RW%% WHERE `time` = :time AND (FIND_IN_SET(:asDefender, `defender`) OR FIND_IN_SET(:asAttacker, `attacker`)) LIMIT 1;',
                [':time' => (int) $user['granted'], ':asDefender' => $userId, ':asAttacker' => $userId],
            );
            $entry = [
                'user' => $userId,
                'name' => (string) $user['username'],
                'granted' => (int) $user['granted'],
                'report' => '',
                'opponents' => [],
                'loot' => 0.0,
                'linked' => false,
                'linkedKnown' => false,
            ];

            if ($reports !== []) {
                $report = $reports[0];
                $inDefenders = in_array((string) $userId, explode(',', (string) $report['defender']), true);
                $entry['report'] = (string) $report['rid'];
                $opponentIds = $this->ids((string) ($inDefenders ? $report['attacker'] : $report['defender']));
                $entry['opponents'] = $this->opponents($opponentIds, $entry['report'], $weights, $userId, $universe);
                foreach ($entry['opponents'] as $opponent) {
                    $entry['loot'] += $opponent['loot'];
                    if ($opponent['ip'] || $opponent['multi']) {
                        $entry['linked'] = true;
                    }
                }
                $entry['linkedKnown'] = $entry['linked'] && array_reduce(
                    $entry['opponents'],
                    static fn(bool $all, array $o): bool => $all && (!($o['ip'] || $o['multi']) || $o['multiKnown']),
                    true,
                );
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Fleets recalled when the arrival time was already reached (log stay_time = recall time >= arrival_time) on
     * attack, ACS and destroy missions: the recall-after-arrival pattern of audit F-05.
     *
     * @return list<array<string, mixed>>
     */
    private function recalls(int $universe, int $since): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `mission`, `owner`, `owner_name`, `target_owner`, `target_owner_name`, `arrival_time`, `stay_time`,
                `stay_time` - `arrival_time` AS late, (`report_id` <> \'\') AS fought
            FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `mission` IN (:attack, :acs, :destroy) AND `hasCanceled` = 1 AND `start_time` >= :since
                AND `stay_time` >= `arrival_time`
            ORDER BY `stay_time` DESC LIMIT ' . self::LIMIT . ';',
            [
                ':universe' => $universe,
                ':attack' => Mission::Attack->value,
                ':acs' => Mission::Acs->value,
                ':destroy' => Mission::Destruction->value,
                ':since' => $since,
            ],
        );

        return $rows;
    }

    /** @return list<int> */
    private function ids(string $list): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $list)), static fn(int $id): bool => $id > 0));
    }

    /**
     * @param list<int> $opponentIds
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array{id: int, name: string, loot: float, ip: bool, subnet: bool, multi: bool, multiKnown: bool}>
     */
    private function opponents(array $opponentIds, string $reportId, array $weights, int $userId, int $universe): array
    {
        if ($opponentIds === []) {
            return [];
        }

        $in = implode(',', $opponentIds);
        $loot = [];
        $rows = $this->db->select(
            "SELECT `owner`, SUM(`gained_metal` + `gained_crystal` * :crystal + `gained_deuterium` * :deuterium) AS loot
            FROM %%LOG_FLEETS%% WHERE `owner` IN ($in) AND `report_id` = :report GROUP BY `owner`;",
            [':crystal' => $weights['crystal'], ':deuterium' => $weights['deuterium'], ':report' => $reportId],
        );
        foreach ($rows as $row) {
            $loot[(int) $row['owner']] = (float) $row['loot'];
        }

        $links = $this->linkData(array_merge([$userId], $opponentIds), $universe);
        $out = [];
        foreach ($opponentIds as $id) {
            $link = $this->compare($links, $userId, $id);
            $out[] = [
                'id' => $id,
                'name' => $links['names'][$id] ?? ('#' . $id),
                'loot' => $loot[$id] ?? 0.0,
            ] + $link;
        }

        return $out;
    }

    /**
     * Adds the names and the link flags (ip, subnet, multi, multiKnown) of the two players in every row.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withLinks(array $rows, string $keyA, string $keyB, int $universe): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row[$keyA];
            $ids[] = (int) $row[$keyB];
        }
        $links = $this->linkData($ids, $universe);

        foreach ($rows as &$row) {
            $a = (int) $row[$keyA];
            $b = (int) $row[$keyB];
            $row[$keyA . 'Name'] = $links['names'][$a] ?? ('#' . $a);
            $row[$keyB . 'Name'] = $links['names'][$b] ?? ('#' . $b);
            $row += $this->compare($links, $a, $b);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array{names: array<int, string>, ips: array<int, string>, groups: array<int, array<int, bool>>, devices: array<int, array<string, true>>} multi groups per user (id => allowed), device hashes per user
     */
    private function linkData(array $ids, int $universe): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        $data = ['names' => [], 'ips' => [], 'groups' => [], 'devices' => []];
        if ($ids === []) {
            return $data;
        }

        $in = implode(',', $ids);
        foreach ($this->db->select("SELECT `id`, `username`, `user_lastip` FROM %%USERS%% WHERE `id` IN ($in);") as $row) {
            $data['names'][(int) $row['id']] = (string) $row['username'];
            $data['ips'][(int) $row['id']] = (string) $row['user_lastip'];
        }
        foreach ($this->db->select(
            "SELECT mu.`userID`, mu.`multiID`, mu.`allowed` FROM %%MULTI_TO_USERS%% mu JOIN %%MULTI%% m ON m.`multiID` = mu.`multiID`
            WHERE mu.`userID` IN ($in) AND m.`universe` = :universe;",
            [':universe' => $universe],
        ) as $row) {
            $data['groups'][(int) $row['userID']][(int) $row['multiID']] = (int) $row['allowed'] === 1;
        }
        $devices = $this->deviceTable();
        if ($devices !== null) {
            foreach ($this->db->select("SELECT `user_id`, `device_hash` FROM `$devices` WHERE `user_id` IN ($in);") as $row) {
                $data['devices'][(int) $row['user_id']][(string) $row['device_hash']] = true;
            }
        }

        return $data;
    }

    /**
     * @param array{names: array<int, string>, ips: array<int, string>, groups: array<int, array<int, bool>>, devices: array<int, array<string, true>>} $links
     * @return array{ip: bool, subnet: bool, multi: bool, multiKnown: bool, device: bool}
     */
    private function compare(array $links, int $a, int $b): array
    {
        $ipA = $links['ips'][$a] ?? '';
        $ipB = $links['ips'][$b] ?? '';
        $ip = $ipA !== '' && $ipA === $ipB;
        $prefix = $this->subnetPrefix($ipA);
        $subnet = !$ip && $prefix !== '' && $prefix === $this->subnetPrefix($ipB);
        $shared = array_intersect_key($links['groups'][$a] ?? [], $links['groups'][$b] ?? []);
        $known = $shared !== [];
        foreach (array_keys($shared) as $multiId) {
            $known = $known && $links['groups'][$a][$multiId] && $links['groups'][$b][$multiId];
        }

        $device = array_intersect_key($links['devices'][$a] ?? [], $links['devices'][$b] ?? []) !== [];

        return ['ip' => $ip, 'subnet' => $subnet, 'multi' => $shared !== [], 'multiKnown' => $known, 'device' => $device];
    }

    /** Prefixed name of the device table (USER_DEVICES), null while it does not exist (then there is no device data). */
    private function deviceTable(): ?string
    {
        if ($this->deviceTable === false) {
            try {
                $name = $this->db->table('USER_DEVICES');
            } catch (\Throwable) {
                $name = $this->db->getTablePrefix() . 'user_devices';
            }
            $found = $this->db->selectSingle(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name;',
                [':name' => $name],
            );
            $this->deviceTable = $found ? $name : null;
        }

        return $this->deviceTable;
    }

    /**
     * Value-flow clusters of the last GRAPH_DAYS days. Undirected graph of players: the edge weight is the value moved
     * between two players in both directions by transports/holds/transfers (ships at build cost), completed market trades
     * (both sides at the market ratios) and attack loot (gained resources of attack/ACS/destroy fleets, taken from the
     * target). Only edges of at least GRAPH_MIN_VALUE_FACTOR x MIN_TRANSFER_VALUE and GRAPH_MIN_FLOWS flows connect
     * players; connected components of at least two players are clusters, biggest total value first.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>> members (id, name, link flags), value, flows, top (most one-sided edge)
     */
    private function tradeGraph(int $universe, int $since, array $weights): array
    {
        $edges = $this->tradeEdges($universe, $since, $weights);
        $clusters = $this->strongClusters($edges);

        return $this->describeClusters($clusters, $universe);
    }

    /**
     * Value moved between players: edge[lo][hi] = [flows, value lo -> hi, value hi -> lo], from transfers, completed
     * market trades and attack loot (see tradeGraph()).
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     *
     * @return array<int, array<int, array{0: int, 1: float, 2: float}>>
     */
    private function tradeEdges(int $universe, int $since, array $weights): array
    {
        $limit = self::GRAPH_MAX_SOURCE_ROWS;
        // edge[lo][hi] = [flows, value lo -> hi, value hi -> lo]
        $edges = [];
        $add = static function (int $from, int $to, int $count, float $value) use (&$edges): void {
            if ($from === $to || $from <= 0 || $to <= 0) {
                return;
            }
            $forward = $from < $to;
            $lo = $forward ? $from : $to;
            $hi = $forward ? $to : $from;
            $edge = $edges[$lo][$hi] ?? [0, 0.0, 0.0];
            $edge[0] += $count;
            $edge[$forward ? 1 : 2] += $value;
            $edges[$lo][$hi] = $edge;
        };

        foreach ($this->transferFlow($universe, $since, $weights, $limit) as $from => $targets) {
            foreach ($targets as $to => [$count, $value]) {
                $add($from, $to, $count, $value);
            }
        }

        $unit = static fn(string $type, string $amount): string => sprintf(
            '(%s * CASE %s WHEN 1 THEN 1 WHEN 2 THEN %F WHEN 3 THEN %F ELSE 0 END)',
            $amount,
            $type,
            $weights['crystal'],
            $weights['deuterium'],
        );
        $offered = $unit('t.`resource_type`', 't.`resource_amount`');
        $wanted = $unit('t.`ex_resource_type`', 't.`ex_resource_amount`');
        foreach ($this->db->select(
            "SELECT s.`owner` AS seller, b.`owner` AS buyer, COUNT(*) AS n, SUM($offered) AS offered, SUM($wanted) AS wanted
            FROM %%TRADES%% t
            JOIN %%LOG_FLEETS%% s ON s.`id` = t.`seller_fleet_id`
            JOIN %%LOG_FLEETS%% b ON b.`id` = t.`buyer_fleet_id`
            WHERE t.`transaction_type` = 0 AND t.`buy_time` >= :since AND s.`universe` = :universe AND s.`owner` <> b.`owner`
            GROUP BY s.`owner`, b.`owner` LIMIT $limit;",
            [':since' => $this->db->formatDate($since), ':universe' => $universe],
        ) as $row) {
            // the seller gave the offered resources, the buyer the wanted ones
            $add((int) $row['seller'], (int) $row['buyer'], (int) $row['n'], (float) $row['offered']);
            $add((int) $row['buyer'], (int) $row['seller'], 0, (float) $row['wanted']);
        }

        // loot: the attacker takes resources from the target
        $loot = sprintf('SUM(`gained_metal` + `gained_crystal` * %F + `gained_deuterium` * %F)', $weights['crystal'], $weights['deuterium']);
        foreach ($this->db->select(
            "SELECT `owner`, `target_owner`, COUNT(*) AS n, $loot AS v
            FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `mission` IN (:attack, :acs, :destroy) AND `owner` <> `target_owner` AND `target_owner` > 0
                AND `start_time` >= :since AND `gained_metal` + `gained_crystal` + `gained_deuterium` > 0
            GROUP BY `owner`, `target_owner` LIMIT $limit;",
            [':universe' => $universe, ':attack' => Mission::Attack->value, ':acs' => Mission::Acs->value, ':destroy' => Mission::Destruction->value, ':since' => $since],
        ) as $row) {
            $add((int) $row['target_owner'], (int) $row['owner'], (int) $row['n'], (float) $row['v']);
        }

        return $edges;
    }

    /**
     * Connected components (union-find) over the edges that reach the flow and value thresholds, biggest total value
     * first, at most GRAPH_MAX_CLUSTERS. Each cluster has its player ids, total value, flows and most one-sided edge.
     *
     * @param array<int, array<int, array{0: int, 1: float, 2: float}>> $edges
     *
     * @return list<array{ids: array<int, true>, value: float, flows: int, top: array{from: int, to: int, net: float, value: float, share: float}|null}>
     */
    private function strongClusters(array $edges): array
    {
        // union-find over the edges that reach the thresholds
        $parent = [];
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $strong = [];
        foreach ($edges as $lo => $targets) {
            foreach ($targets as $hi => [$flows, $forward, $backward]) {
                if ($flows < self::GRAPH_MIN_FLOWS || $forward + $backward < self::GRAPH_MIN_VALUE_FACTOR * self::MIN_TRANSFER_VALUE) {
                    continue;
                }
                $parent[$lo] ??= $lo;
                $parent[$hi] ??= $hi;
                $parent[$find($lo)] = $find($hi);
                $strong[] = [$lo, $hi, $flows, $forward, $backward];
            }
        }

        /** @var array<int, array{ids: array<int, true>, value: float, flows: int, top: array{from: int, to: int, net: float, value: float, share: float}|null}> $clusters */
        $clusters = [];
        foreach ($strong as [$lo, $hi, $flows, $forward, $backward]) {
            $root = $find($lo);
            $cluster = &$clusters[$root];
            $cluster ??= ['ids' => [], 'value' => 0.0, 'flows' => 0, 'top' => null];
            $cluster['ids'][$lo] = $cluster['ids'][$hi] = true;
            $cluster['value'] += $forward + $backward;
            $cluster['flows'] += $flows;
            $net = abs($forward - $backward);
            if ($cluster['top'] === null || $net > $cluster['top']['net']) {
                $cluster['top'] = [
                    'from' => $forward >= $backward ? $lo : $hi, 'to' => $forward >= $backward ? $hi : $lo,
                    'net' => $net, 'value' => $forward + $backward, 'share' => $forward + $backward > 0.0 ? $net / ($forward + $backward) : 0.0,
                ];
            }
            unset($cluster);
        }
        usort($clusters, static fn(array $x, array $y): int => $y['value'] <=> $x['value']);
        $clusters = array_slice($clusters, 0, self::GRAPH_MAX_CLUSTERS);

        return $clusters;
    }

    /**
     * Adds member names, the IP/subnet/multi/device link flags between the members and the names of the top edge to
     * each cluster.
     *
     * @param list<array<string, mixed>> $clusters result of strongClusters()
     *
     * @return list<array<string, mixed>>
     */
    private function describeClusters(array $clusters, int $universe): array
    {
        $allIds = [];
        foreach ($clusters as $cluster) {
            array_push($allIds, ...array_keys($cluster['ids']));
        }
        $links = $this->linkData($allIds, $universe);

        $out = [];
        foreach ($clusters as $cluster) {
            $ids = array_keys($cluster['ids']);
            $members = [];
            foreach ($ids as $id) {
                $member = ['id' => $id, 'name' => $links['names'][$id] ?? ('#' . $id), 'ip' => false, 'subnet' => false, 'multi' => false, 'multiKnown' => true, 'device' => false];
                foreach ($ids as $other) {
                    if ($other === $id) {
                        continue;
                    }
                    $pair = $this->compare($links, $id, $other);
                    $member['ip'] = $member['ip'] || $pair['ip'];
                    $member['subnet'] = $member['subnet'] || $pair['subnet'];
                    $member['device'] = $member['device'] || $pair['device'];
                    if ($pair['multi']) {
                        $member['multi'] = true;
                        $member['multiKnown'] = $member['multiKnown'] && $pair['multiKnown'];
                    }
                }
                $members[] = $member;
            }
            $top = $cluster['top'];
            $top['fromName'] = $links['names'][$top['from']] ?? ('#' . $top['from']);
            $top['toName'] = $links['names'][$top['to']] ?? ('#' . $top['to']);
            $out[] = ['members' => $members, 'value' => $cluster['value'], 'flows' => $cluster['flows'], 'top' => $top];
        }

        return $out;
    }

    /**
     * Accounts with the most fleet launches (LOG_FLEETS start_time) in the 24 hours before $now, with the busiest clock
     * hour of that period (launches/hour peak) to spot scripted play.
     *
     * @return list<array{id: int, name: string, launches: int, peak: int}>
     */
    private function activity(int $universe, int $now): array
    {
        $since = $now - 86400;
        $rows = $this->db->select(
            'SELECT `owner`, MAX(`owner_name`) AS name, COUNT(*) AS n FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `owner` > 0 AND `start_time` >= :since AND `start_time` <= :now
            GROUP BY `owner` ORDER BY n DESC, `owner` ASC LIMIT ' . self::ACTIVITY_LIMIT . ';',
            [':universe' => $universe, ':since' => $since, ':now' => $now],
        );
        if ($rows === []) {
            return [];
        }

        $in = implode(',', array_map(static fn(array $row): int => (int) $row['owner'], $rows));
        $peak = [];
        foreach ($this->db->select(
            "SELECT `owner`, COUNT(*) AS n FROM %%LOG_FLEETS%%
            WHERE `owner` IN ($in) AND `universe` = :universe AND `start_time` >= :since AND `start_time` <= :now
            GROUP BY `owner`, FLOOR(`start_time` / 3600);",
            [':universe' => $universe, ':since' => $since, ':now' => $now],
        ) as $row) {
            $peak[(int) $row['owner']] = max($peak[(int) $row['owner']] ?? 0, (int) $row['n']);
        }

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['owner'],
            'name' => (string) $row['name'],
            'launches' => (int) $row['n'],
            'peak' => $peak[(int) $row['owner']] ?? 0,
        ], $rows);
    }

    /** The /24 prefix of an IPv4 address or the /64 prefix of an IPv6 address as hex, '' when empty or not an address. */
    private function subnetPrefix(string $ip): string
    {
        $packed = $ip === '' ? false : @inet_pton($ip);
        if ($packed === false) {
            return '';
        }

        return bin2hex(substr($packed, 0, strlen($packed) === 4 ? 3 : 8));
    }
}
