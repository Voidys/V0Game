<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Community\Chat;
use App\Community\ChatRetention;
use App\Game\Admin\Chat\ChatModeration;
use App\Game\Core\Log;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat review (`admin/chat`): the history of the global channel (or one alliance channel) of the universe with deleted and
 * filter-masked messages, filters (player, text, dates, deleted only), keyset pagination, delete / restore, and the queue of
 * reported messages with context. Same right as the abuse report. Every action is written to the admin log (type 8, target = message id).
 */
class ChatController extends AbstractAdminController
{
    private const ACTIONS = ['delete', 'restore', 'dismiss'];

    /** Shares the right of the abuse report instead of a right of its own. */
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowAbuseReportPage')) {
            $this->deny();
        }
    }

    private function moderation(): ChatModeration
    {
        return new ChatModeration($this->db);
    }

    /**
     * `view` = history (default) or reports; history filters `ally` (alliance id, empty = global), `name`, `text`, `from`, `to`
     * (YYYY-MM-DD), `deleted` (1) and `before` (keyset: last id of the previous page).
     */
    #[GameRoute('admin', 'admin/chat', 'chat')]
    public function show(): Response
    {
        $universe = (int) $this->universe()->getEmulated();
        $moderation = $this->moderation();
        $view = $this->input()->string('view') === 'reports' ? 'reports' : 'history';
        $date = fn(int $time): string => $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], $time, $this->state->USER['timezone'] ?? null);
        $context = [
            'view' => $view,
            'openReports' => $moderation->openReportCount($universe),
            'retentionDays' => ChatRetention::RETENTION_DAYS,
            'reasons' => Chat::REASONS,
        ];

        if ($view === 'reports') {
            $queue = $moderation->reported($universe, max(0, $this->input()->int('after')));
            $reported = $queue['items'];
            foreach ($reported as &$entry) {
                foreach (['message'] as $key) {
                    $entry[$key]['date'] = $date((int) $entry[$key]['created_at']);
                }
                foreach (['before', 'after'] as $key) {
                    foreach ($entry[$key] as &$row) {
                        $row['date'] = $date((int) $row['created_at']);
                    }
                    unset($row);
                }
            }
            unset($entry);

            return $this->render('admin/ChatPage.html.twig', $context + ['reported' => $reported, 'next' => $queue['next']]);
        }

        $ally = max(0, $this->input()->int('ally'));
        $filters = [
            'name' => $this->input()->string('name', '', true),
            'text' => $this->input()->string('text', '', true),
            'from' => $this->dayStart($this->input()->string('from')),
            'to' => $this->dayStart($this->input()->string('to'), true),
            'deleted' => $this->input()->int('deleted') === 1,
        ];
        $history = $moderation->history($universe, $ally > 0 ? 'ally:' . $ally : Chat::GLOBAL, $filters, max(0, $this->input()->int('before')));
        foreach ($history['rows'] as &$row) {
            $row['date'] = $date((int) $row['created_at']);
            $row['deletedDate'] = $row['deleted_at'] ? $date((int) $row['deleted_at']) : '';
        }
        unset($row);

        return $this->render('admin/ChatPage.html.twig', $context + [
            'rows' => $history['rows'],
            'next' => $history['next'],
            'ally' => $ally,
            'filters' => [
                'name' => $filters['name'], 'text' => $filters['text'], 'from' => $this->input()->string('from'),
                'to' => $this->input()->string('to'), 'deleted' => $filters['deleted'],
            ],
        ]);
    }

    /** POST: `do` = delete|restore|dismiss for the message `id`; logs it and goes back to `back` (history|reports). */
    #[GameRoute('admin', 'admin/chat', 'chat', 'act', ['POST'])]
    public function act(): Response
    {
        $universe = (int) $this->universe()->getEmulated();
        $id = $this->input()->int('id');
        $do = $this->input()->string('do');
        $now = $this->state->runtime->timestamp;
        $admin = (int) $this->state->USER['id'];
        $moderation = $this->moderation();
        $done = match ($do) {
            'delete' => $moderation->delete($universe, $id, $admin, $now),
            'restore' => $moderation->restore($universe, $id),
            'dismiss' => $moderation->dismiss($universe, $id, $admin, $now),
            default => false,
        };
        if ($done && in_array($do, self::ACTIONS, true)) {
            $log = new Log($this->db, $this->configs, $this->state, '8');
            $log->target = $id;
            $log->universe = $universe;
            $log->old = ['chat_message' => $id];
            $log->new = ['chat_action' => $do];
            $log->save();
        }

        return $this->redirectToRoute('admin_chat_show', array_filter([
            'view' => $this->input()->string('back') === 'reports' ? 'reports' : null,
            'ally' => $this->input()->int('ally') ?: null,
            'name' => $this->input()->string('name', '', true) ?: null,
            'text' => $this->input()->string('text', '', true) ?: null,
            'from' => $this->input()->string('from') ?: null,
            'to' => $this->input()->string('to') ?: null,
            'deleted' => $this->input()->int('deleted') ?: null,
            'before' => $this->input()->int('before') ?: null,
        ]));
    }

    /** Start (or with `$end` the last second) of a YYYY-MM-DD day in the server time zone; 0 for an empty or invalid date. */
    private function dayStart(string $day, bool $end = false): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return 0;
        }
        $time = strtotime($day . ' 00:00:00');

        return $time === false ? 0 : $time + ($end ? 86399 : 0);
    }
}
