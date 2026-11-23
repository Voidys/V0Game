<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Admin\AbuseReport\AbuseReportQuery;
use App\Game\Admin\AbuseReport\AbuseReviewService;
use App\Game\Core\Log;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Review queue of the anti-multi / push-trade detection (`admin/abuse/reviews`): scored player pairs (SuspicionScore,
 * refreshed hourly by AbuseReviewCronjob) with sortable list, evidence of the abuse report for the pair and the actions
 * dismiss / confirm / mark actioned / reopen and note. Same right as the abuse report (ShowAbuseReportPage). Status changes
 * and notes are written to the admin log (type 8, target = review id).
 */
class AbuseReviewsController extends AbstractAdminController
{
    private const ACTIONS = ['dismiss' => 'dismissed', 'confirm' => 'confirmed', 'actioned' => 'actioned', 'reopen' => 'open'];

    /** Shares the right of the abuse report instead of a right of its own. */
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowAbuseReportPage')) {
            $this->deny();
        }
    }

    private function service(): AbuseReviewService
    {
        return new AbuseReviewService($this->db, new AbuseReportQuery($this->db, $this->elements()));
    }

    /** The queue, optional filter `status` (open by default, `all`) and sort (`sort` score|pair|status|updated, `dir` asc|desc). */
    #[GameRoute('admin', 'admin/abuse/reviews', 'abuseReviews')]
    public function show(): Response
    {
        $status = $this->input()->string('status', 'open');
        $sort = $this->input()->string('sort', 'score');
        $desc = $this->input()->string('dir', 'desc') !== 'asc';
        $reviews = $this->service()->listReviews((int) $this->universe()->getEmulated(), $status, $sort, $desc);
        $date = fn(int $time): string => $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], $time, $this->state->USER['timezone'] ?? null);
        foreach ($reviews as &$review) {
            $review['updatedDate'] = $date((int) $review['updated_at']);
        }
        unset($review);

        return $this->render('admin/AbuseReviewsPage.html.twig', [
            'reviews' => $reviews,
            'status' => $status,
            'sort' => $sort,
            'dir' => $desc ? 'desc' : 'asc',
            'statuses' => AbuseReviewService::STATUSES,
            'threshold' => (int) ($this->configs->get()->abuse_review_threshold ?? AbuseReviewService::DEFAULT_THRESHOLD),
        ]);
    }

    /** One review with the evidence of the abuse report (last 30 days) for the pair. */
    #[GameRoute('admin', 'admin/abuse/reviews/{id}', 'abuseReviews', 'detail', requirements: ['id' => '\d+'])]
    public function detail(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $review = $this->service()->find($uni, $this->input()->int('id'));
        if ($review === null) {
            return $this->messageResponse($this->state->LNG['abuse_review_missing'], $this->routeUrl('admin_abuse_reviews_show'));
        }
        $now = $this->state->runtime->timestamp;
        $evidence = $this->service()->evidence($uni, (int) $review['user_a'], (int) $review['user_b'], $now);
        $date = fn(int $time): string => $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], $time, $this->state->USER['timezone'] ?? null);
        foreach (['transfers', 'trades'] as $section) {
            foreach ($evidence[$section] as &$row) {
                $row['lastDate'] = $date((int) $row['last']);
            }
            unset($row);
        }
        $review['updatedDate'] = $date((int) $review['updated_at']);
        $review['reviewedDate'] = $review['reviewed_at'] ? $date((int) $review['reviewed_at']) : '';

        return $this->render('admin/AbuseReviewDetail.html.twig', [
            'review' => $review,
            'evidence' => $evidence,
            'days' => AbuseReviewService::REFRESH_DAYS,
        ]);
    }

    /**
     * POST: `do` = dismiss|confirm|actioned|reopen sets the status, `note` stores the reviewer note (also sent with a status
     * action when filled). Writes admin log entries (type 8) and redirects back to the review.
     */
    #[GameRoute('admin', 'admin/abuse/reviews/{id}', 'abuseReviews', 'act', ['POST'], ['id' => '\d+'])]
    public function act(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $id = $this->input()->int('id');
        $service = $this->service();
        $review = $service->find($uni, $id);
        if ($review === null) {
            return $this->messageResponse($this->state->LNG['abuse_review_missing'], $this->routeUrl('admin_abuse_reviews_show'));
        }
        $now = $this->state->runtime->timestamp;
        $admin = (int) $this->state->USER['id'];
        $do = $this->input()->string('do');
        $note = $this->input()->string('note', '', true);

        if ($note !== '' && $note !== (string) $review['note']) {
            $service->setNote($uni, $id, $note, $admin, $now);
            $this->log($id, ['note' => $review['note']], ['note' => mb_substr($note, 0, 2000)]);
        }
        if (isset(self::ACTIONS[$do]) && self::ACTIONS[$do] !== $review['status']) {
            $service->setStatus($uni, $id, self::ACTIONS[$do], $admin, $now);
            $this->log($id, ['status' => $review['status']], ['status' => self::ACTIONS[$do]]);
        }

        return $this->redirectToRoute('admin_abuse_reviews_detail', ['id' => $id]);
    }

    /** Admin log entry (type 8: abuse review, target = review id). */
    private function log(int $id, array $old, array $new): void
    {
        $log = new Log($this->db, $this->configs, $this->state, '8');
        $log->target = $id;
        $log->universe = $this->universe()->getEmulated();
        $log->old = $old;
        $log->new = $new;
        $log->save();
    }
}
