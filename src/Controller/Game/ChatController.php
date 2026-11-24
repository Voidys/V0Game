<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Community\Chat;
use App\Game\Chat\ChatSettings;
use App\Routing\GameRoute;
use App\Routing\LeanBoot;
use App\Game\Core\Log;
use App\Game\Core\StoredData;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON endpoints of the in-game chat (module MODULE_CHAT): poll (messages, deleted ids, unread counts), send, and the
 * moderator actions delete and mute. The chat widget (public/scripts/game/chat.js) polls; nothing is pushed.
 */
#[\App\Controller\RequireModule('MODULE_CHAT')]
class ChatController extends AbstractGameController
{
    /** polls must not touch the planet (no resource update, no online time: the session is not written for a poll) */
    protected $disableEcoSystem = true;

    private Chat $chat;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setChat(Chat $chat): void
    {
        $this->chat = $chat;
    }

    private function settings(): ChatSettings
    {
        return ChatSettings::fromConfig($this->configs->get(), (string) preg_replace('/:\d+$/', '', (string) $this->state->runtime->httpHost));
    }

    /** JSON answer of the chat endpoints: application/json, `<>&'"` escaped, no content sniffing (the project helper sends text/html) */
    private function json(array $data): Response
    {
        $this->beforeResponse();

        return new JsonResponse($data, 200, ['X-Content-Type-Options' => 'nosniff']);
    }

    /** Moderator of the chat: staff with the same admin right as the chat page of the admin panel (`ShowAbuseReportPage`). */
    private function isModerator(): bool
    {
        $user = $this->state->USER;
        if (!$this->chat->isModerator($user)) {
            return false;
        }
        if ((int) $user['authlevel'] === \App\Game\Enum\AuthLevel::Admin->value) {
            return true;
        }
        // game requests keep the rights column undecoded (only the admin boot decodes it)
        $rights = is_array($user['rights'] ?? null) ? $user['rights'] : StoredData::tryDecode($user['rights'] ?? '');

        return is_array($rights) && ($rights['ShowAbuseReportPage'] ?? 0) == 1;
    }

    /** Admin action log entry (type 8, target = message id), the same as written by the admin chat page. */
    private function auditLog(int $id, string $action): void
    {
        $log = new Log($this->db, $this->configs, $this->state, '8');
        $log->target = $id;
        $log->universe = (int) $this->state->USER['universe'];
        $log->old = ['chat_message' => $id];
        $log->new = ['chat_action' => $action];
        $log->save();
    }

    /**
     * One poll. `channel` (global|alliance, optional) = the open tab: its new messages after the id `after` (0 = the latest
     * page), and the ids deleted since `from` (the oldest id the client shows, 0 = none). `rg` / `ra` = last read ids of the
     * global / alliance channel for the unread badge; a missing value = never read: no count, the latest id is returned
     * (`latest`) to start from. Alliance entries only exist for alliance members.
     */
    #[GameRoute('game', 'game/chat/poll', 'chat', 'poll')]
    #[LeanBoot(LeanBoot::PLANET, LeanBoot::DATA, LeanBoot::LANGUAGE)]
    public function poll(): Response
    {
        $user = $this->state->USER;
        $universe = (int) $user['universe'];
        $userId = (int) $user['id'];
        $input = $this->input();
        $global = $this->settings()->globalEnabled;

        $response = ['messages' => [], 'deleted' => [], 'unread' => [], 'latest' => [], 'mod' => $this->isModerator(), 'slow' => $this->chat->slowModes($user, $this->settings())];
        $active = $input->string('channel');
        $activeChannel = null;
        if ($active !== '') {
            $activeChannel = $this->chat->channelFor($user, $active, $global);
            if ($activeChannel === null) {
                return $this->json(['error' => Chat::ERR_CHANNEL]);
            }
        }
        $readable = [];
        foreach ([Chat::GLOBAL => 'rg', Chat::ALLIANCE => 'ra'] as $name => $param) {
            $channel = $this->chat->channelFor($user, $name, $global);
            if ($channel !== null) {
                $readable[$name] = $channel;
            }
        }

        // one cheap check (newest id per channel) lets a poll with nothing new skip the message, deleted and unread queries
        $latest = $this->chat->latestIds($universe, array_values(array_unique(array_filter([...array_values($readable), $activeChannel]))));
        if ($activeChannel !== null) {
            $after = max(0, $input->int('after'));
            $newest = $latest[$activeChannel] ?? 0;
            if ($after <= 0 || $newest > $after) {
                $response['messages'] = $this->chat->messages($universe, $activeChannel, $userId, $after);
            }
            $from = max(0, $input->int('from'));
            if ($from > 0 && $newest >= $from) {
                $response['deleted'] = $this->chat->deletedSince($universe, $activeChannel, $from);
            }
        }

        foreach ($readable as $name => $channel) {
            $param = $name === Chat::GLOBAL ? 'rg' : 'ra';
            if ($input->has($param) && $input->int($param) >= 0) {
                $response['unread'][$name] = $latest[$channel] > $input->int($param)
                    ? $this->chat->unread($universe, $channel, $userId, $input->int($param))
                    : 0;
            } else {
                $response['latest'][$name] = $latest[$channel];
                $response['unread'][$name] = 0;
            }
        }

        return $this->json($response);
    }

    /** Sends a message (`channel`, `message`); answers `{ok: true}` or `{error: code}` (see Chat::ERR_*). */
    #[GameRoute('game', 'game/chat/send', 'chat', 'send', ['POST'])]
    public function send(): Response
    {
        $result = $this->chat->send($this->state->USER, $this->input()->string('channel'), $this->input()->rawString('message'), $this->state->runtime->timestamp, $this->settings());

        if (isset($result['error'])) {
            return $this->json(isset($result['wait']) ? ['error' => $result['error'], 'wait' => $result['wait']] : ['error' => $result['error']]);
        }

        return $this->json(['ok' => true, 'id' => $result['id']]);
    }

    /** Moderators only: deletes the message `id` (it disappears for everyone on their next poll). */
    #[GameRoute('game', 'game/chat/delete', 'chat', 'delete', ['POST'])]
    public function delete(): Response
    {
        if (!$this->isModerator()) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        $id = $this->input()->int('id');
        $done = $this->chat->delete((int) $this->state->USER['universe'], $id, (int) $this->state->USER['id'], $this->state->runtime->timestamp);
        if ($done) {
            $this->auditLog($id, 'delete');
        }

        return $this->json(['ok' => $done]);
    }

    /** Moderators only: mutes the author of the message `id` for `hours` hours (0 = lift the mute). */
    #[GameRoute('game', 'game/chat/mute', 'chat', 'mute', ['POST'])]
    public function mute(): Response
    {
        if (!$this->isModerator()) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }
        $hours = min(24 * 30, max(0, $this->input()->int('hours')));
        $id = $this->input()->int('id');
        $done = $this->chat->muteAuthor((int) $this->state->USER['universe'], $id, $hours, $this->state->runtime->timestamp);
        if ($done) {
            $this->auditLog($id, $hours > 0 ? 'mute' : 'unmute');
        }

        return $this->json(['ok' => $done]);
    }

    /** Reports the message `id` (`reason` spam|insult|cheating|other, optional `comment` up to 200 characters); answers `{ok: true}` or `{error: code}`. */
    #[GameRoute('game', 'game/chat/report', 'chat', 'report', ['POST'])]
    public function report(): Response
    {
        $result = $this->chat->report(
            $this->state->USER,
            $this->input()->int('id'),
            $this->input()->string('reason'),
            $this->input()->rawString('comment'),
            $this->state->runtime->timestamp,
            $this->settings()->globalEnabled,
        );

        return $this->json($result);
    }

    /** Blocks the author of the message `id` (hides his chat messages and direct messages, like the block on the player card). */
    #[GameRoute('game', 'game/chat/block', 'chat', 'block', ['POST'])]
    public function block(): Response
    {
        return $this->json($this->chat->blockAuthor($this->state->USER, $this->input()->int('id'), $this->settings()->globalEnabled));
    }
}
