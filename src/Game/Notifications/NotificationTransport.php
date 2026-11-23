<?php

namespace App\Game\Notifications;

/**
 * The network side of a notification (kept behind an interface so tests and other features can swap it):
 * one Web Push message to one browser subscription, one message to a Discord webhook.
 */
interface NotificationTransport
{
    public const PUSH_OK = 'ok';
    /** the subscription is gone for good (HTTP 404/410): the caller deletes it */
    public const PUSH_GONE = 'gone';
    public const PUSH_ERROR = 'error';

    /**
     * Sends a Web Push message.
     *
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     * @param string $payload JSON the service worker shows (title, body, url)
     * @return string one of the PUSH_* constants
     */
    public function sendPush(array $subscription, string $payload): string;

    /** Posts a message to a Discord webhook; true when Discord accepted it. */
    public function sendDiscord(string $webhookUrl, string $content): bool;
}
