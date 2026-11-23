<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Admin;

use App\Game\Admin\AbuseReport\SuspicionScore;
use PHPUnit\Framework\TestCase;

/** Weights and caps of the suspicion score (0-100) of a player pair. */
final class SuspicionScoreTest extends TestCase
{
    public function testNoSignalsScoreZero(): void
    {
        $this->assertSame(['score' => 0, 'parts' => []], (new SuspicionScore())->score([]));
    }

    public function testSingleSignalWeights(): void
    {
        $scorer = new SuspicionScore();
        $this->assertSame(30, $scorer->score(['ip' => true])['score']);
        $this->assertSame(30, $scorer->score(['device' => true])['score']);
        $this->assertSame(20, $scorer->score(['multi' => true, 'multiKnown' => false])['score']);
        $this->assertSame(10, $scorer->score(['subnet' => true])['score']);
        $this->assertSame(15, $scorer->score(['cluster' => true])['score']);
    }

    public function testKnownMultiGroupCountsNothingAndIpBeatsSubnet(): void
    {
        $scorer = new SuspicionScore();
        $this->assertSame(0, $scorer->score(['multi' => true, 'multiKnown' => true])['score']);
        $this->assertSame(['ip' => 30], $scorer->score(['ip' => true, 'subnet' => true])['parts']);
    }

    public function testTransferPointsScaleWithValue(): void
    {
        $scorer = new SuspicionScore();
        $this->assertSame(0, $scorer->score(['transferNet' => 999])['score']);
        $this->assertSame(10, $scorer->score(['transferNet' => 1000])['score']);
        $this->assertSame(30, $scorer->score(['transferNet' => 100000])['score']);
        $this->assertSame(30, $scorer->score(['transferNet' => 5000000])['score']);
        $mid = $scorer->score(['transferNet' => 50500])['score'];
        $this->assertSame(20, $mid);
    }

    public function testSkewedTradesNeedTheMinimumAndAreCapped(): void
    {
        $scorer = new SuspicionScore();
        $this->assertSame(0, $scorer->score(['skewedTrades' => 2])['score']);
        $this->assertSame(10, $scorer->score(['skewedTrades' => 3])['score']);
        $this->assertSame(14, $scorer->score(['skewedTrades' => 5])['score']);
        $this->assertSame(20, $scorer->score(['skewedTrades' => 50])['score']);
    }

    public function testScoreIsCappedAt100(): void
    {
        $result = (new SuspicionScore())->score([
            'ip' => true, 'device' => true, 'multi' => true, 'multiKnown' => false, 'transferNet' => 1e9, 'skewedTrades' => 20, 'cluster' => true,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame(30 + 30 + 20 + 30 + 20 + 15, array_sum($result['parts']));
    }
}
