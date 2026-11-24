<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Notifications\PushEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The push endpoint whitelist (SSRF guard for browser-supplied subscription URLs). */
final class PushEndpointTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function endpoints(): iterable
    {
        yield 'fcm' => ['https://fcm.googleapis.com/fcm/send/abc', true];
        yield 'fcm uppercase' => ['https://FCM.GoogleAPIs.com/fcm/send/abc', true];
        yield 'mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', true];
        yield 'mozilla sub' => ['https://updates-autopush.push.services.mozilla.com/wpush/v2/abc', true];
        yield 'apple' => ['https://web.push.apple.com/abc', true];
        yield 'apple sub' => ['https://api.push.apple.com/3/device/abc', true];
        yield 'windows' => ['https://db5.notify.windows.com/?token=abc', true];
        yield 'explicit 443' => ['https://fcm.googleapis.com:443/x', true];
        yield 'http' => ['http://fcm.googleapis.com/x', false];
        yield 'empty' => ['', false];
        yield 'other host' => ['https://example.com/x', false];
        yield 'suffix without dot boundary' => ['https://evilnotify.windows.com.evil.test/x', false];
        yield 'suffix glued' => ['https://evilpush.apple.com/x', false];
        yield 'bare suffix' => ['https://push.apple.com/x', false];
        yield 'lookalike' => ['https://fcm.googleapis.com.evil.test/x', false];
        yield 'userinfo' => ['https://fcm.googleapis.com@evil.test/x', false];
        yield 'userinfo reverse' => ['https://evil.test@fcm.googleapis.com/x', false];
        yield 'password' => ['https://a:b@fcm.googleapis.com/x', false];
        yield 'port' => ['https://fcm.googleapis.com:8443/x', false];
        yield 'ipv4' => ['https://127.0.0.1/x', false];
        yield 'ipv6' => ['https://[::1]/x', false];
        yield 'localhost' => ['https://localhost/x', false];
        yield 'backslash' => ['https://evil.test\\@fcm.googleapis.com/x', false];
        yield 'whitespace' => ["https://fcm.googleapis.com/x\n", false];
        yield 'too long' => ['https://fcm.googleapis.com/' . str_repeat('a', 600), false];
    }

    #[DataProvider('endpoints')]
    public function testEndpoint(string $url, bool $allowed): void
    {
        self::assertSame($allowed, PushEndpoint::isAllowed($url), $url);
    }
}
