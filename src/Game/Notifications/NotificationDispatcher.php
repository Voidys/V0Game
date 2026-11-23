<?php

namespace App\Game\Notifications;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\Singleton;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends a player notification to every channel the player set up: Web Push to all subscribed browsers and a Discord
 * webhook. The per-type preferences (users_settings.notify_*) decide whether anything is sent at all.
 *
 * Usage from game code: `$this->notifications()->notify($userId, NotificationDispatcher::TYPE_ATTACK, $title, $body, 'game/overview');`
 * (GameUtilities accessor) or inject it as a container service. Expired push subscriptions (HTTP 404/410) are deleted.
 */
class NotificationDispatcher
{
    public const TYPE_ATTACK = 'attack';
    public const TYPE_SPY = 'spy';
    public const TYPE_FLEET_RETURN = 'fleet_return';
    public const TYPE_QUEUE_BUILD = 'queue_build';
    public const TYPE_QUEUE_RESEARCH = 'queue_research';
    public const TYPE_QUEUE_SHIPYARD = 'queue_shipyard';
    public const TYPE_FARM_AWAKE = 'farm_awake';
    /** the settings page's test button: ignores the preferences */
    public const TYPE_TEST = 'test';

    /** type => users_settings column that switches it on; a type not listed here is sent whenever a channel exists */
    public const PREFERENCE_COLUMNS = [
        self::TYPE_ATTACK => 'notify_attack',
        self::TYPE_SPY => 'notify_spy',
        self::TYPE_FLEET_RETURN => 'notify_fleet_return',
        self::TYPE_QUEUE_BUILD => 'notify_queue_build',
        self::TYPE_QUEUE_RESEARCH => 'notify_queue_research',
        self::TYPE_QUEUE_SHIPYARD => 'notify_queue_shipyard',
        self::TYPE_FARM_AWAKE => 'notify_farm_awake',
    ];

    private readonly NotificationTransport $transport;

    /**
     * @param NotificationTransport|null $transport null builds the real one (VAPID keys from the config table)
     * @param string $defaultUri public base URL (env DEFAULT_URI), used to make relative links absolute when run from the console
     */
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        ?NotificationTransport $transport = null,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri = '',
    ) {
        $this->transport = $transport ?? new HttpNotificationTransport(new VapidKeys($db), $defaultUri);
    }

    /**
     * Notifies a player. Nothing happens when the type is switched off in the player's settings or no channel is set up.
     *
     * @param string $type one of the TYPE_* constants (other strings are sent without a preference check)
     * @param string $url link the notification opens, relative to the site root (e.g. `game/overview`) or absolute
     */
    public function notify(int $userId, string $type, string $title, string $body, string $url): void
    {
        $settings = $this->db->selectSingle('SELECT * FROM %%USERS_SETTINGS%% WHERE `user_id` = :user;', [':user' => $userId]);
        if (!is_array($settings) || !self::wants($settings, $type)) {
            return;
        }

        $url = $this->absoluteUrl($url);
        $subscriptions = $this->db->select('SELECT `id`, `endpoint`, `p256dh`, `auth` FROM %%PUSH_SUBSCRIPTIONS%% WHERE `user_id` = :user;', [':user' => $userId]);
        if ($subscriptions !== []) {
            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $type], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($subscriptions as $subscription) {
                $this->push($subscription, $payload);
            }
        }

        $hook = trim((string) ($settings['notify_discord_webhook'] ?? ''));
        if ($hook !== '' && Discord::isAllowedWebhook($hook)) {
            $this->transport->sendDiscord($hook, '**' . $title . '**' . "\n" . $body . "\n" . $url);
        }
    }

    /** Whether the player's settings row switches this notification type on (unknown types: always). */
    public static function wants(array $settings, string $type): bool
    {
        $column = self::PREFERENCE_COLUMNS[$type] ?? null;

        return $column === null || !empty($settings[$column]);
    }

    /**
     * Sends one push message; an expired subscription is deleted, a delivered one gets its last_used_at stamp.
     *
     * @param array<string, mixed> $subscription row of push_subscriptions
     */
    private function push(array $subscription, string $payload): void
    {
        $result = $this->transport->sendPush($subscription, $payload);
        if ($result === NotificationTransport::PUSH_GONE) {
            $this->db->delete('DELETE FROM %%PUSH_SUBSCRIPTIONS%% WHERE `id` = :id;', [':id' => $subscription['id']]);
        } elseif ($result === NotificationTransport::PUSH_OK) {
            $this->db->update('UPDATE %%PUSH_SUBSCRIPTIONS%% SET `last_used_at` = :now WHERE `id` = :id;', [
                ':now' => $this->state->runtime->timestamp,
                ':id' => $subscription['id'],
            ]);
        }
    }

    /** Relative links get the site URL (configured DEFAULT_URI from the console, else the request's own URL). */
    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $base = $this->defaultUri !== '' ? rtrim($this->defaultUri, '/') . '/' : (string) $this->state->runtime->httpPath;

        return $base . ltrim($url, '/');
    }
}
