<?php

namespace App\Controller\Game;

use App\Game\Core\Discord;
use App\Game\Notifications\NotificationDispatcher;
use App\Game\Notifications\PushEndpoint;
use App\Game\Notifications\VapidKeys;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web Push endpoints of the settings page: the server's public VAPID key, subscribe / unsubscribe of a browser
 * (form fields endpoint, p256dh, auth; JSON answers) and the test notification.
 */
class PushController extends AbstractGameController
{
    /** most browsers/devices one player can have subscribed (the oldest are dropped) */
    private const MAX_SUBSCRIPTIONS = 10;

    /** The public VAPID key the browser needs to subscribe. */
    #[GameRoute('game', 'game/push/key', 'push', 'key')]
    public function key(): Response
    {
        return $this->jsonResponse(['key' => (new VapidKeys($this->db))->publicKey()]);
    }

    /**
     * Stores (or re-assigns) the browser's push subscription for the player.
     */
    #[GameRoute('game', 'game/push/subscribe', 'push', 'subscribe', ['POST'])]
    public function subscribe(): Response
    {
        $endpoint = trim($this->input()->rawString('endpoint'));
        $p256dh = trim($this->input()->rawString('p256dh'));
        $auth = trim($this->input()->rawString('auth'));
        if (
            !PushEndpoint::isAllowed($endpoint)
            || $p256dh === '' || strlen($p256dh) > 255 || $auth === '' || strlen($auth) > 255
        ) {
            return $this->jsonResponse(['ok' => false]);
        }

        $userId = (int) $this->state->USER['id'];
        $now = $this->state->runtime->timestamp;
        $this->db->insert('INSERT INTO %%PUSH_SUBSCRIPTIONS%% (`user_id`, `endpoint`, `p256dh`, `auth`, `created_at`, `last_used_at`)
            VALUES (:user, :endpoint, :p256dh, :auth, :now, 0)
            ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `p256dh` = VALUES(`p256dh`), `auth` = VALUES(`auth`);', [
            ':user' => $userId, ':endpoint' => $endpoint, ':p256dh' => $p256dh, ':auth' => $auth, ':now' => $now,
        ]);
        $this->db->delete('DELETE FROM %%PUSH_SUBSCRIPTIONS%% WHERE `user_id` = :user AND `id` NOT IN (
            SELECT `id` FROM (SELECT `id` FROM %%PUSH_SUBSCRIPTIONS%% WHERE `user_id` = :user2 ORDER BY `id` DESC LIMIT ' . self::MAX_SUBSCRIPTIONS . ') keep);', [
            ':user' => $userId, ':user2' => $userId,
        ]);

        return $this->jsonResponse(['ok' => true]);
    }

    /** Removes the browser's push subscription (only the player's own). */
    #[GameRoute('game', 'game/push/unsubscribe', 'push', 'unsubscribe', ['POST'])]
    public function unsubscribe(): Response
    {
        $this->db->delete('DELETE FROM %%PUSH_SUBSCRIPTIONS%% WHERE `user_id` = :user AND `endpoint` = :endpoint;', [
            ':user' => $this->state->USER['id'],
            ':endpoint' => trim($this->input()->rawString('endpoint')),
        ]);

        return $this->jsonResponse(['ok' => true]);
    }

    /**
     * Sends a test notification to the player's channels (ignores the preferences); the answer tells whether any channel exists.
     */
    #[GameRoute('game', 'game/push/test', 'push', 'test', ['POST'])]
    public function test(): Response
    {
        $userId = (int) $this->state->USER['id'];
        $LNG = $this->state->LNG;
        $subscriptions = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%PUSH_SUBSCRIPTIONS%% WHERE `user_id` = :user;', [':user' => $userId], 'c');
        $hook = (string) $this->db->selectSingle('SELECT `notify_discord_webhook` FROM %%USERS_SETTINGS%% WHERE `user_id` = :user;', [':user' => $userId], 'notify_discord_webhook');
        $discord = $hook !== '' && Discord::isAllowedWebhook($hook);
        if ($subscriptions > 0 || $discord) {
            $this->notifications()->notify($userId, NotificationDispatcher::TYPE_TEST, $LNG['notif_test_title'], $LNG['notif_test_body'], 'game/settings');
        }

        return $this->jsonResponse(['ok' => $subscriptions > 0 || $discord, 'push' => $subscriptions, 'discord' => $discord]);
    }
}
