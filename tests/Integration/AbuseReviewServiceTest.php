<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Admin\AbuseReport\AbuseReportQuery;
use App\Game\Admin\AbuseReport\AbuseReviewService;
use App\Game\Enum\Mission;
use App\Tests\Support\GameTestCase;

/** Review queue: refresh inserts pairs at the threshold, dismissed pairs re-open only on a rise of 20, status/note actions. */
final class AbuseReviewServiceTest extends GameTestCase
{
    private const NOW = 1800000000;
    private static int $fleetId = 9200000;

    private function service(): AbuseReviewService
    {
        return new AbuseReviewService($this->db, new AbuseReportQuery($this->db, $this->elements()));
    }

    private function transport(int $from, int $to, int $metal): void
    {
        $this->db->insert(
            'INSERT INTO %%LOG_FLEETS%% SET `id` = :id, `universe` = 1, `mission` = :mission, `owner` = :from, `target_owner` = :to, `start_id` = 1, `end_id` = 2,
            `start_time` = :t, `arrival_time` = :t, `stay_time` = 0, `hasCanceled` = 0, `resource_metal` = :metal, `resource_crystal` = 0, `resource_deuterium` = 0;',
            [':id' => ++self::$fleetId, ':mission' => Mission::Transport->value, ':from' => $from, ':to' => $to, ':t' => self::NOW - 3600, ':metal' => $metal],
        );
    }

    public function testDecideRules(): void
    {
        $this->assertSame('skip', AbuseReviewService::decide(null, 49, 50));
        $this->assertSame('insert', AbuseReviewService::decide(null, 50, 50));
        $this->assertSame('update', AbuseReviewService::decide(['status' => 'open', 'score' => 60], 10, 50));
        $this->assertSame('update', AbuseReviewService::decide(['status' => 'confirmed', 'score' => 60], 60, 50));
        $this->assertSame('skip', AbuseReviewService::decide(['status' => 'dismissed', 'score' => 55], 74, 50));
        $this->assertSame('reopen', AbuseReviewService::decide(['status' => 'dismissed', 'score' => 55], 75, 50));
        $this->assertSame('skip', AbuseReviewService::decide(['status' => 'dismissed', 'score' => 20], 45, 50));
    }

    public function testRefreshUpsertAndDismissFlow(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.77.1.1']);
        [$b] = $this->createPlayer(['user_lastip' => '10.77.1.1']);
        $a = (int) $a['id'];
        $b = (int) $b['id'];
        $lo = min($a, $b);
        $hi = max($a, $b);
        $service = $this->service();

        // shared IP (30) + one-sided transfer of 100 000 metal (30) = 60
        $this->transport($a, $b, 100000);
        $this->assertSame(1, $service->refresh(1, 50, self::NOW)['inserted']);
        $row = $this->db->selectSingle('SELECT * FROM %%ABUSE_REVIEWS%% WHERE `universe` = 1 AND `user_a` = :a AND `user_b` = :b;', [':a' => $lo, ':b' => $hi]);
        $this->assertSame(60, (int) $row['score']);
        $this->assertSame('open', $row['status']);
        $this->assertSame(60, (int) json_decode($row['signals'], true)['parts']['ip'] + 30);

        // a second refresh updates, no duplicate
        $this->assertSame(1, $service->refresh(1, 50, self::NOW)['updated']);
        $this->assertCount(1, $this->db->select('SELECT 1 FROM %%ABUSE_REVIEWS%% WHERE `user_a` = :a AND `user_b` = :b;', [':a' => $lo, ':b' => $hi]));

        // dismissed: stays dismissed while the score is unchanged
        $this->assertTrue($service->setStatus(1, (int) $row['id'], 'dismissed', 1, self::NOW));
        $this->assertSame(1, $service->refresh(1, 50, self::NOW)['skipped']);
        $this->assertSame('dismissed', $service->find(1, (int) $row['id'])['status']);

        // score rises by 30 (device, same hash) -> reopens
        $this->db->update('UPDATE %%ABUSE_REVIEWS%% SET `score` = 40 WHERE `id` = :id;', [':id' => $row['id']]);
        $this->assertSame(1, $service->refresh(1, 50, self::NOW)['reopened']);
        $reopened = $service->find(1, (int) $row['id']);
        $this->assertSame('open', $reopened['status']);
        $this->assertSame(60, (int) $reopened['score']);
    }

    public function testNoteAndInvalidStatus(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.77.2.2']);
        [$b] = $this->createPlayer(['user_lastip' => '10.77.2.2']);
        $this->transport((int) $a['id'], (int) $b['id'], 100000);
        $service = $this->service();
        $service->refresh(1, 50, self::NOW);
        $review = $service->listReviews(1, 'open', 'score', true)[0];

        $this->assertFalse($service->setStatus(1, (int) $review['id'], 'bogus', 1, self::NOW));
        $this->assertTrue($service->setNote(1, (int) $review['id'], 'same household?', 1, self::NOW));
        $this->assertSame('same household?', $service->find(1, (int) $review['id'])['note']);
        $this->assertNull($service->find(2, (int) $review['id']));
    }
}
