<?php

declare(strict_types=1);

namespace App\Game\Admin\AbuseReport;

/**
 * Suspicion score (0-100) of a player pair for the anti-multi / push-trade review queue. Pure: combines the signals of the
 * abuse report with fixed weights, the sum is capped at 100.
 *
 * Weights (points):
 *  - same last IP: 30 (a /24 or /64 neighbour only counts the weaker subnet signal, 10)
 *  - shared device hash: 30
 *  - shared multi group that is not marked as known/allowed for both: 20 (a known group counts nothing)
 *  - one-sided transfer value (net metal units): 10 from MIN_TRANSFER_VALUE up to 30 at TRANSFER_FULL_VALUE, linear between
 *  - skewed market trades (outlier ratio): 10 for the first MIN_TRADES trades, +2 per further trade, at most 20
 *  - member of the same value-flow cluster (trade graph): 15
 */
final class SuspicionScore
{
    public const MAX = 100;
    public const W_IP = 30;
    public const W_DEVICE = 30;
    public const W_MULTI = 20;
    public const W_SUBNET = 10;
    public const W_CLUSTER = 15;
    public const TRANSFER_MIN = 10;
    public const TRANSFER_MAX = 30;
    public const TRANSFER_FULL_VALUE = 100000;
    public const TRADES_BASE = 10;
    public const TRADES_STEP = 2;
    public const TRADES_MAX = 20;
    public const MIN_TRADES = AbuseReportQuery::MIN_REPEATED_TRADES;

    /**
     * @param array{ip?: bool, device?: bool, multi?: bool, multiKnown?: bool, subnet?: bool, transferNet?: float|int, skewedTrades?: int, cluster?: bool} $signals
     * @return array{score: int, parts: array<string, int>} total (0-100) and the points per signal that contributed
     */
    public function score(array $signals): array
    {
        $parts = [];
        if (!empty($signals['ip'])) {
            $parts['ip'] = self::W_IP;
        } elseif (!empty($signals['subnet'])) {
            $parts['subnet'] = self::W_SUBNET;
        }
        if (!empty($signals['device'])) {
            $parts['device'] = self::W_DEVICE;
        }
        if (!empty($signals['multi']) && empty($signals['multiKnown'])) {
            $parts['multi'] = self::W_MULTI;
        }
        $transfer = $this->transferPoints((float) ($signals['transferNet'] ?? 0));
        if ($transfer > 0) {
            $parts['transfer'] = $transfer;
        }
        $trades = $this->tradePoints((int) ($signals['skewedTrades'] ?? 0));
        if ($trades > 0) {
            $parts['trades'] = $trades;
        }
        if (!empty($signals['cluster'])) {
            $parts['cluster'] = self::W_CLUSTER;
        }

        return ['score' => min(self::MAX, array_sum($parts)), 'parts' => $parts];
    }

    /** One-sided net value in metal units to points (0 below the report minimum). */
    private function transferPoints(float $net): int
    {
        if ($net < AbuseReportQuery::MIN_TRANSFER_VALUE) {
            return 0;
        }
        $share = min(1.0, ($net - AbuseReportQuery::MIN_TRANSFER_VALUE) / (self::TRANSFER_FULL_VALUE - AbuseReportQuery::MIN_TRANSFER_VALUE));

        return self::TRANSFER_MIN + (int) round($share * (self::TRANSFER_MAX - self::TRANSFER_MIN));
    }

    /** Number of skewed trades to points (0 below MIN_TRADES). */
    private function tradePoints(int $skewed): int
    {
        if ($skewed < self::MIN_TRADES) {
            return 0;
        }

        return min(self::TRADES_MAX, self::TRADES_BASE + ($skewed - self::MIN_TRADES) * self::TRADES_STEP);
    }
}
