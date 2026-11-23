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
