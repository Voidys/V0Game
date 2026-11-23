<?php

declare(strict_types=1);

namespace App\Game\Admin\AbuseReport;

use App\Game\Core\Database;

/**
 * Review queue of the anti-multi / push-trade detection (table abuse_reviews). Builds the candidate pairs from the
 * abuse report (transfers, trades, value-flow clusters: no query is duplicated), scores them with SuspicionScore and
 * keeps the queue up to date: pairs at or above the threshold are inserted as open; a dismissed pair re-opens only when
 * its score rose by REOPEN_RISE or more since the dismissal (the stored score of a dismissed pair is the one at the
 * dismissal); confirmed/actioned pairs keep their status and only get fresh evidence.
 */
final class AbuseReviewService
{
    public const DEFAULT_THRESHOLD = 50;
    public const REOPEN_RISE = 20;
    public const STATUSES = ['open', 'dismissed', 'confirmed', 'actioned'];
    public const REFRESH_DAYS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly AbuseReportQuery $query,
        private readonly SuspicionScore $scorer = new SuspicionScore(),
    ) {}

    /**
     * What to do with a scored pair: `insert`, `update` (refresh score/signals, keep status), `reopen` or `skip`.
     *
     * @param array{status: string, score: int}|null $existing the stored review of the pair
     */
    public static function decide(?array $existing, int $score, int $threshold): string
    {
        if ($existing === null) {
            return $score >= $threshold ? 'insert' : 'skip';
        }
        if ($existing['status'] !== 'dismissed') {
            return 'update';
        }

        return $score >= $threshold && $score - (int) $existing['score'] >= self::REOPEN_RISE ? 'reopen' : 'skip';
    }

    /**
     * Candidate pairs of a report with their signals and score.
     *
     * @param array<string, mixed> $report result of AbuseReportQuery::report()
     * @return array<string, array{a: int, b: int, score: int, signals: array<string, mixed>}> keyed "a:b" with a < b
     */
    public function candidates(array $report, int $universe): array
    {
        $pairs = [];
        $add = function (int $x, int $y, array $signals) use (&$pairs): void {
            if ($x === $y || $x < 1 || $y < 1) {
                return;
            }
            [$a, $b] = $x < $y ? [$x, $y] : [$y, $x];
            $key = $a . ':' . $b;
            $cur = $pairs[$key] ?? ['a' => $a, 'b' => $b, 'signals' => []];
            foreach (['ip', 'device', 'multi', 'subnet'] as $flag) {
                $cur['signals'][$flag] = !empty($cur['signals'][$flag]) || !empty($signals[$flag]);
            }
            $cur['signals']['multiKnown'] = ($cur['signals']['multiKnown'] ?? true) && ($signals['multiKnown'] ?? true);
            foreach (['transferNet', 'transferSent', 'skewedTrades', 'trades'] as $number) {
                $cur['signals'][$number] = max((float) ($cur['signals'][$number] ?? 0), (float) ($signals[$number] ?? 0));
            }
            $pairs[$key] = $cur;
        };

        foreach ($report['transfers'] ?? [] as $row) {
            $add((int) $row['from'], (int) $row['to'], $row + ['transferNet' => (float) $row['net'], 'transferSent' => (float) $row['sent']]);
        }
        foreach ($report['trades'] ?? [] as $row) {
            $add((int) $row['giver'], (int) $row['receiver'], $row + ['skewedTrades' => !empty($row['outlier']) ? (int) $row['count'] : 0, 'trades' => (int) $row['count']]);
        }
        $clusterOf = [];
        foreach ($report['graph'] ?? [] as $index => $cluster) {
            foreach ($cluster['members'] as $member) {
                $clusterOf[(int) $member['id']] = $index;
            }
            $top = $cluster['top'];
            $add((int) $top['from'], (int) $top['to'], $this->query->pairLinks($universe, (int) $top['from'], (int) $top['to']));
        }

        foreach ($pairs as $key => $pair) {
            $pairs[$key]['signals']['cluster'] = isset($clusterOf[$pair['a']]) && ($clusterOf[$pair['a']] === ($clusterOf[$pair['b']] ?? null));
            $result = $this->scorer->score($pairs[$key]['signals']);
            $pairs[$key]['signals']['parts'] = $result['parts'];
            $pairs[$key]['score'] = $result['score'];
        }

        return $pairs;
    }

    /**
     * Recomputes the scores of the universe from the last REFRESH_DAYS days and applies decide() to every candidate.
     *
     * @return array{inserted: int, updated: int, reopened: int, skipped: int}
     */
    public function refresh(int $universe, int $threshold, int $now): array
    {
        $report = $this->query->report($universe, self::REFRESH_DAYS, $now);
        $count = ['inserted' => 0, 'updated' => 0, 'reopened' => 0, 'skipped' => 0];

        foreach ($this->candidates($report, $universe) as $pair) {
            $existing = $this->db->selectSingle(
                'SELECT `id`, `status`, `score` FROM %%ABUSE_REVIEWS%% WHERE `universe` = :uni AND `user_a` = :a AND `user_b` = :b;',
                [':uni' => $universe, ':a' => $pair['a'], ':b' => $pair['b']],
            );
            $existing = is_array($existing) ? $existing : null;
            $json = json_encode($pair['signals'], JSON_THROW_ON_ERROR);
            switch (self::decide($existing, $pair['score'], $threshold)) {
                case 'insert':
                    $this->db->insert(
                        'INSERT INTO %%ABUSE_REVIEWS%% SET `universe` = :uni, `user_a` = :a, `user_b` = :b, `score` = :score, `signals` = :signals,
                        `status` = "open", `created_at` = :now, `updated_at` = :now;',
                        [':uni' => $universe, ':a' => $pair['a'], ':b' => $pair['b'], ':score' => $pair['score'], ':signals' => $json, ':now' => $now],
                    );
                    ++$count['inserted'];
                    break;
                case 'update':
                    $this->db->update(
                        'UPDATE %%ABUSE_REVIEWS%% SET `score` = :score, `signals` = :signals, `updated_at` = :now WHERE `id` = :id;',
                        [':score' => $pair['score'], ':signals' => $json, ':now' => $now, ':id' => $existing['id']],
                    );
                    ++$count['updated'];
                    break;
                case 'reopen':
                    $this->db->update(
                        'UPDATE %%ABUSE_REVIEWS%% SET `score` = :score, `signals` = :signals, `status` = "open", `updated_at` = :now WHERE `id` = :id;',
                        [':score' => $pair['score'], ':signals' => $json, ':now' => $now, ':id' => $existing['id']],
                    );
                    ++$count['reopened'];
                    break;
                default:
                    ++$count['skipped'];
            }
        }

        return $count;
    }

    /**
     * The queue of a universe with player names, signals decoded.
     *
     * @param string $status one of STATUSES or '' for all
     * @param string $sort score|pair|status|updated
     * @return list<array<string, mixed>>
     */
    public function listReviews(int $universe, string $status, string $sort, bool $desc): array
    {
        $order = ['score' => 'r.`score`', 'pair' => 'ua.`username`', 'status' => 'r.`status`', 'updated' => 'r.`updated_at`'][$sort] ?? 'r.`score`';
        $where = in_array($status, self::STATUSES, true) ? ' AND r.`status` = :status' : '';
        $params = [':uni' => $universe] + ($where !== '' ? [':status' => $status] : []);
        $rows = $this->db->select(
            "SELECT r.*, ua.`username` AS nameA, ub.`username` AS nameB FROM %%ABUSE_REVIEWS%% r
            LEFT JOIN %%USERS%% ua ON ua.`id` = r.`user_a` LEFT JOIN %%USERS%% ub ON ub.`id` = r.`user_b`
            WHERE r.`universe` = :uni$where ORDER BY $order " . ($desc ? 'DESC' : 'ASC') . ', r.`id` DESC LIMIT 500;',
            $params,
        );

        return array_map(fn(array $row): array => $this->decorate($row), $rows);
    }

    /** One review of the universe (null when missing). @return array<string, mixed>|null */
    public function find(int $universe, int $id): ?array
    {
        $row = $this->db->selectSingle(
            'SELECT r.*, ua.`username` AS nameA, ub.`username` AS nameB, ur.`username` AS reviewer FROM %%ABUSE_REVIEWS%% r
            LEFT JOIN %%USERS%% ua ON ua.`id` = r.`user_a` LEFT JOIN %%USERS%% ub ON ub.`id` = r.`user_b`
            LEFT JOIN %%USERS%% ur ON ur.`id` = r.`reviewed_by`
            WHERE r.`universe` = :uni AND r.`id` = :id;',
            [':uni' => $universe, ':id' => $id],
        );

        return is_array($row) ? $this->decorate($row) : null;
    }

    /**
     * Sets the status of a review (open/dismissed/confirmed/actioned) and the reviewer.
     *
     * @return bool false for an unknown status or review
     */
    public function setStatus(int $universe, int $id, string $status, int $adminId, int $now): bool
    {
        if (!in_array($status, self::STATUSES, true) || $this->find($universe, $id) === null) {
            return false;
        }
        $this->db->update(
            'UPDATE %%ABUSE_REVIEWS%% SET `status` = :status, `reviewed_by` = :admin, `reviewed_at` = :now, `updated_at` = :now
            WHERE `universe` = :uni AND `id` = :id;',
            [':status' => $status, ':admin' => $adminId, ':now' => $now, ':uni' => $universe, ':id' => $id],
        );

        return true;
    }

    /** Stores the reviewer note (max 2000 characters). */
    public function setNote(int $universe, int $id, string $note, int $adminId, int $now): bool
    {
        if ($this->find($universe, $id) === null) {
            return false;
        }
        $this->db->update(
            'UPDATE %%ABUSE_REVIEWS%% SET `note` = :note, `reviewed_by` = :admin, `reviewed_at` = :now, `updated_at` = :now
            WHERE `universe` = :uni AND `id` = :id;',
            [':note' => mb_substr($note, 0, 2000), ':admin' => $adminId, ':now' => $now, ':uni' => $universe, ':id' => $id],
        );

        return true;
    }

    /**
     * The evidence of the existing report for one pair: transfers (either direction), trades and the value-flow cluster
     * that contains both players.
     *
     * @return array{transfers: list<array<string, mixed>>, trades: list<array<string, mixed>>, cluster: array<string, mixed>|null, links: array<string, mixed>}
     */
    public function evidence(int $universe, int $a, int $b, int $now): array
    {
        $report = $this->query->report($universe, self::REFRESH_DAYS, $now);
        $isPair = static fn(int $x, int $y): bool => ($x === $a && $y === $b) || ($x === $b && $y === $a);
        $cluster = null;
        foreach ($report['graph'] as $candidate) {
            $ids = array_map(static fn(array $m): int => (int) $m['id'], $candidate['members']);
            if (in_array($a, $ids, true) && in_array($b, $ids, true)) {
                $cluster = $candidate;
                break;
            }
        }

        return [
            'transfers' => array_values(array_filter($report['transfers'], static fn(array $r): bool => $isPair((int) $r['from'], (int) $r['to']))),
            'trades' => array_values(array_filter($report['trades'], static fn(array $r): bool => $isPair((int) $r['giver'], (int) $r['receiver']))),
            'cluster' => $cluster,
            'links' => $this->query->pairLinks($universe, $a, $b),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        $signals = json_decode((string) $row['signals'], true);
        $row['signals'] = is_array($signals) ? $signals : [];
        $row['nameA'] ??= '#' . $row['user_a'];
        $row['nameB'] ??= '#' . $row['user_b'];

        return $row;
    }
}
