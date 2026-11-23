<?php

namespace App\Game\Notifications;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The real transport: Web Push through minishlink/web-push (signed with the server's VAPID keys), Discord webhooks
 * through the Symfony HTTP client (redirects not followed, no mentions). Failures never throw.
 */
class HttpNotificationTransport implements NotificationTransport
{
    private ?WebPush $webPush = null;
    private ?HttpClientInterface $httpClient;

    /** @param string $siteUri public site URL (env DEFAULT_URI): the VAPID claim's contact when it is https, else a placeholder mailto: address */
    public function __construct(
        private readonly VapidKeys $vapid,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $siteUri = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    /** Signs and sends the message; HTTP 404/410 of the push service means PUSH_GONE, every other failure PUSH_ERROR. */
    public function sendPush(array $subscription, string $payload): string
    {
        try {
            $report = $this->webPush()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription['endpoint'],
                    'keys' => ['p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
                ]),
                $payload,
            );
        } catch (Throwable) {
            return self::PUSH_ERROR;
        }
        if ($report->isSuccess()) {
            return self::PUSH_OK;
        }

        return $report->isSubscriptionExpired() ? self::PUSH_GONE : self::PUSH_ERROR;
    }

    /** Queues every message and flushes once; the report of each is matched to its key by the (unique) endpoint. */
    public function sendPushBatch(array $messages): array
    {
        $results = array_fill_keys(array_keys($messages), self::PUSH_ERROR);
        if ($messages === []) {
            return $results;
        }
        $byEndpoint = [];
        try {
            $webPush = $this->webPush();
            foreach ($messages as $key => $message) {
                $subscription = $message['subscription'];
                try {
                    $webPush->queueNotification(Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'keys' => ['p256dh' => $subscription['p256dh'], 'auth' => $subscription['auth']],
                    ]), $message['payload']);
                    $byEndpoint[$subscription['endpoint']] = $key;
                } catch (Throwable) {
                    // stays PUSH_ERROR
                }
            }
            foreach ($webPush->flush() as $report) {
                $key = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($key === null) {
                    continue;
                }
                if ($report->isSuccess()) {
                    $results[$key] = self::PUSH_OK;
                } elseif ($report->isSubscriptionExpired()) {
                    $results[$key] = self::PUSH_GONE;
                }
            }
        } catch (Throwable) {
            // whatever was not reported stays PUSH_ERROR
        }

        return $results;
    }

    /** Starts all POSTs (the client runs them concurrently), then reads the status codes. */
    public function sendDiscordBatch(array $messages): array
    {
        $client = $this->httpClient ??= HttpClient::create();
        $responses = [];
        $results = array_fill_keys(array_keys($messages), false);
        foreach ($messages as $key => $message) {
            try {
                $responses[$key] = $client->request('POST', $message['webhook'], [
                    'max_redirects' => 0,
                    'timeout' => 5,
                    'headers' => ['Content-type' => 'application/json'],
                    'body' => json_encode(['content' => $message['content'], 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable) {
                // stays false
            }
        }
        foreach ($responses as $key => $response) {
            try {
                $status = $response->getStatusCode();
                $results[$key] = $status >= 200 && $status < 300;
            } catch (Throwable) {
                // stays false
            }
        }

        return $results;
    }

    /** POSTs the content (no mentions, no redirects, 5 s timeout) and reports whether Discord answered 2xx. */
    public function sendDiscord(string $webhookUrl, string $content): bool
    {
        try {
            $status = ($this->httpClient ??= HttpClient::create())->request('POST', $webhookUrl, [
                'max_redirects' => 0,
                'timeout' => 5,
                'headers' => ['Content-type' => 'application/json'],
                'body' => json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ])->getStatusCode();
        } catch (Throwable) {
            return false;
        }

        return $status >= 200 && $status < 300;
    }

    private function webPush(): WebPush
    {
        $keys = $this->vapid->get();

        return $this->webPush ??= new WebPush(['VAPID' => [
            'subject' => str_starts_with($this->siteUri, 'https://') ? $this->siteUri : 'mailto:noreply@localhost.invalid',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 3600], 8);
    }
}
