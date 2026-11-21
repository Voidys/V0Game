<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\SecretAudit;
use PHPUnit\Framework\TestCase;

final class SecretAuditTest extends TestCase
{
    public function testRandomSecretIsFine(): void
    {
        $audit = new SecretAudit(bin2hex(random_bytes(32)), 'prod', false);
        $this->assertFalse($audit->weakSecret());
        $this->assertFalse($audit->developmentMode(false));
    }

    public function testPublicRepositorySecretsAreWeak(): void
    {
        foreach (['969653de3fda3c4eac642eda0382f974', '$ecretf0rt3st', 'ThisTokenIsNotSoSecretChangeIt'] as $secret) {
            $this->assertTrue((new SecretAudit($secret, 'prod', false))->weakSecret(), $secret);
        }
    }

    public function testShortSecretIsWeak(): void
    {
        $this->assertTrue((new SecretAudit(str_repeat('a', 31), 'prod', false))->weakSecret());
        $this->assertFalse((new SecretAudit(str_repeat('a', 32), 'prod', false))->weakSecret());
    }

    public function testDevelopmentModeOnLiveServer(): void
    {
        $secret = bin2hex(random_bytes(32));
        $this->assertTrue((new SecretAudit($secret, 'dev', false))->developmentMode(false));
        $this->assertTrue((new SecretAudit($secret, 'prod', true))->developmentMode(false));
        // local DDEV projects are development setups on purpose
        $this->assertFalse((new SecretAudit($secret, 'dev', true))->developmentMode(true));
    }
}
