<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\Discord;
use App\Game\Notifications\NotificationDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure rules behind the notifications: which webhook URLs are accepted and which preference switches which type.
 */
final class NotificationPreferencesTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function webhooks(): iterable
    {
        yield 'discord.com' => ['https://discord.com/api/webhooks/123/abcdefghij0123456789_-AB', true];
        yield 'discordapp.com' => ['https://discordapp.com/api/webhooks/123/abcdefghij0123456789_-AB', true];
        yield 'canary' => ['https://canary.discord.com/api/webhooks/1/abcdefghij0123456789_-AB', true];
        yield 'empty' => ['', false];
        yield 'http' => ['http://discord.com/api/webhooks/1/abcdefghij0123456789_-AB', false];
        yield 'other host' => ['https://example.com/api/webhooks/1/abcdefghij0123456789_-AB', false];
        yield 'lookalike host' => ['https://discord.com.evil.test/api/webhooks/1/abcdefghij0123456789_-AB', false];
        yield 'userinfo trick' => ['https://discord.com@evil.test/api/webhooks/1/abcdefghij0123456789_-AB', false];
        yield 'wrong path' => ['https://discord.com/channels/1/2', false];
        yield 'path traversal' => ['https://discord.com/api/webhooks/../../x', false];
        yield 'dot segments' => ['https://discord.com/api/webhooks/1/../abcdefghij0123456789_-AB', false];
        yield 'query' => ['https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB?x=1', false];
        yield 'fragment' => ['https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB#x', false];
        yield 'short token' => ['https://discord.com/api/webhooks/1/abc', false];
        yield 'non numeric id' => ['https://discord.com/api/webhooks/abc/abcdefghij0123456789_-AB', false];
        yield 'trailing newline' => ["https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB
", false];
        yield 'port' => ['https://discord.com:8443/api/webhooks/1/abcdefghij0123456789_-AB', false];
    }

    #[DataProvider('webhooks')]
    public function testWebhookValidation(string $url, bool $allowed): void
    {
        self::assertSame($allowed, Discord::isAllowedWebhook($url));
    }

    public function testEveryTypeFollowsItsOwnPreference(): void
    {
        $settings = ['notify_attack' => 1, 'notify_spy' => 0, 'notify_fleet_return' => 0, 'notify_queue_build' => 1, 'notify_queue_research' => 0, 'notify_queue_shipyard' => 0];

        self::assertTrue(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_ATTACK));
        self::assertFalse(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_SPY));
        self::assertFalse(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_FLEET_RETURN));
        self::assertTrue(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_QUEUE_BUILD));
        self::assertFalse(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_QUEUE_RESEARCH));
        self::assertFalse(NotificationDispatcher::wants($settings, NotificationDispatcher::TYPE_QUEUE_SHIPYARD));
    }

    public function testTestAndUnknownTypesIgnoreThePreferences(): void
    {
        self::assertTrue(NotificationDispatcher::wants([], NotificationDispatcher::TYPE_TEST));
        self::assertTrue(NotificationDispatcher::wants([], 'something_new'));
    }
}
