<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Runtime\InstallService;
use App\Tests\Support\GameTestCase;

/**
 * The installer's optional e-mail step: validation, storage with the keys of the admin mail category, skip, test
 * mail failure handling and rendering.
 */
final class InstallMailSettingsTest extends GameTestCase
{
    private function service(): InstallService
    {
        return self::container()->get(InstallService::class);
    }

    private function stored(string $key): ?string
    {
        $value = $this->db->selectSingle('SELECT `value` FROM %%CONFIG%% WHERE `key` = :key', [':key' => $key], 'value');

        return $value === false || $value === null ? null : (string) $value;
    }

    public function testSmtpNeedsHostPortAndSender(): void
    {
        $base = ['active' => '1', 'method' => 2, 'from' => 'noreply@example.com', 'host' => 'smtp.example.com', 'port' => '587'];

        $this->assertNull($this->service()->validateMailSettings($base, 'en')['error']);
        $this->assertNotNull($this->service()->validateMailSettings(['host' => ''] + $base, 'en')['error']);
        $this->assertNotNull($this->service()->validateMailSettings(['port' => 'abc'] + $base, 'en')['error']);
        $this->assertNotNull($this->service()->validateMailSettings(['port' => '70000'] + $base, 'en')['error']);
        $this->assertNotNull($this->service()->validateMailSettings(['from' => 'nope'] + $base, 'en')['error']);
        // PHP mail() needs no host or port; mail off needs nothing
        $this->assertNull($this->service()->validateMailSettings(['method' => 0, 'host' => '', 'port' => ''] + $base, 'en')['error']);
        $this->assertNull($this->service()->validateMailSettings([], 'en')['error']);
    }

    public function testSavesTheAdminKeys(): void
    {
        $checked = $this->service()->validateMailSettings([
            'active' => '1', 'method' => 2, 'from' => 'noreply@example.com', 'host' => 'smtp.example.com',
            'port' => '465', 'encryption' => 'ssl', 'user' => 'bob', 'password' => 's3cret',
        ], 'de');
        $this->assertNull($checked['error']);

        $this->service()->applyMailSettings($checked['values']);

        $this->assertSame('1', $this->stored('mail_active'));
        $this->assertSame('2', $this->stored('mail_use'));
        $this->assertSame('noreply@example.com', $this->stored('smtp_sendmail'));
        $this->assertSame('ssl', $this->stored('smtp_ssl'));
        $this->assertSame('smtp.example.com', $this->stored('smtp_host'));
        $this->assertSame('465', $this->stored('smtp_port'));
        $this->assertSame('bob', $this->stored('smtp_user'));
        $this->assertSame('s3cret', $this->stored('smtp_pass'));
        $this->assertSame('0', $this->stored('user_valid'));
    }

    public function testUserValidNeedsMailOn(): void
    {
        $on = $this->service()->validateMailSettings(['active' => '1', 'method' => 0, 'from' => 'a@example.com', 'user_valid' => '1'], 'en')['values'];
        $this->service()->applyMailSettings($on);
        $this->assertSame('1', $this->stored('user_valid'));

        $off = $this->service()->validateMailSettings(['user_valid' => '1'], 'en')['values'];
        $this->assertFalse($off['user_valid']);
        $this->service()->applyMailSettings($off);
        $this->assertSame('0', $this->stored('user_valid'));
    }

    public function testSkipLeavesMailOff(): void
    {
        $this->service()->applyMailSettings(['active' => true, 'method' => 0, 'from' => 'a@example.com', 'encryption' => '', 'host' => '', 'port' => '', 'user' => '', 'password' => '']);
        $this->service()->applyMailSettings(['active' => false, 'user_valid' => true]);

        $this->assertSame('0', $this->stored('mail_active'));
        $this->assertSame('0', $this->stored('user_valid'));
    }

    public function testTestMailReportsErrorsWithoutThrowingOrLeakingThePassword(): void
    {
        $values = $this->service()->validateMailSettings([
            'active' => '1', 'method' => 2, 'from' => 'noreply@example.com', 'host' => '127.0.0.1',
            'port' => '1', 'user' => 'bob', 'password' => 'topsecret',
        ], 'en')['values'];

        $error = $this->service()->sendTestMail($values, 'me@example.com', 'en');

        $this->assertIsString($error);
        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('topsecret', $error);
    }
}
