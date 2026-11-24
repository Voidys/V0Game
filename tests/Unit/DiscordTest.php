<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\Config;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Discord;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pins the Discord webhook request (POST, JSON payload with game name as username) and that a failing webhook
 * never throws (the error reporter uses this class).
 */
final class DiscordTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private function discord(MockResponse $response, string $hook = 'https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB'): Discord
    {
        $registry = $this->createStub(ConfigRegistry::class);
        $config = new Config(['game_name' => 'V0Game', 'discord_logs_hook' => $hook], $registry);
        $registry->method('get')->willReturn($config);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        return new Discord($registry, new Singleton(new RuntimeContext(dirname(__DIR__, 2))), $http);
    }

    public function testSendLogPostsJsonPayload(): void
    {
        $this->discord(new MockResponse('', ['http_code' => 204]))->sendLog('cron', ['successful' => true, 'list' => [1, 2]]);

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB', $this->requests[0]['url']);
        self::assertContains('Content-type: application/json', $this->requests[0]['options']['headers']);
        self::assertSame(
            ['content' => "**cron**\n```successful: 1\nlist: [1,2]\n```", 'username' => 'V0Game', 'tts' => false, 'allowed_mentions' => ['parse' => []], 'embeds' => []],
            json_decode($this->requests[0]['options']['body'], true),
        );
    }

    public function testFailingWebhookDoesNotThrow(): void
    {
        $this->discord(new MockResponse('', ['error' => 'connection refused']))->sendLog('cron', ['a' => 'b']);

        self::assertCount(1, $this->requests);
    }

    public function testNoHookConfiguredSendsNothing(): void
    {
        $this->discord(new MockResponse(''), '')->sendLog('cron', ['a' => 'b']);

        self::assertSame([], $this->requests);
    }

    public function testNonDiscordHooksAreRefusedAndNeverRequested(): void
    {
        foreach (['http://127.0.0.1/x', 'https://discord.com.evil.test/api/webhooks/1', 'https://evil.test/api/webhooks/1', 'http://discord.com/api/webhooks/1', 'https://discord.com/other', 'https://user@discord.com/api/webhooks/1', 'file:///etc/passwd'] as $hook) {
            $this->discord(new MockResponse(''), $hook)->sendLog('cron', ['a' => 'b']);
        }

        self::assertSame([], $this->requests);
    }

    public function testAllowlist(): void
    {
        foreach (['https://discord.com/api/webhooks/1/abcdefghij0123456789_-AB', 'https://discordapp.com/api/webhooks/1/abcdefghij0123456789_-AB', 'https://ptb.discord.com/api/webhooks/1/abcdefghij0123456789_-AB', 'https://canary.discord.com/api/webhooks/1/abcdefghij0123456789_-AB'] as $url) {
            self::assertTrue(Discord::isAllowedWebhook($url), $url);
        }
        self::assertFalse(Discord::isAllowedWebhook('https://evil.discord.com.test/api/webhooks/1'));
        self::assertFalse(Discord::isAllowedWebhook('https://discord.com:8443/api/webhooks/1'));
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $this->discord(new MockResponse('', ['http_code' => 204]))->sendLog('cron', ['a' => 'b']);

        self::assertSame(0, $this->requests[0]['options']['max_redirects']);
    }
}
