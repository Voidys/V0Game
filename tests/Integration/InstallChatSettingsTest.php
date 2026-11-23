<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Runtime\InstallService;
use App\Tests\Support\GameTestCase;

/**
 * The installer's chat options (module MODULE_CHAT, global channel, link blocking) and the bots switch, plus the
 * field names of the game step validation.
 */
final class InstallChatSettingsTest extends GameTestCase
{
    private function service(): InstallService
    {
        return self::container()->get(InstallService::class);
    }

    private function module(): int
    {
        return (int) $this->db->selectSingle('SELECT `state` FROM %%CONFIG_UNIVERSE_MODULES%% WHERE `uni` = 1 AND `module` = :m', [':m' => MODULE_CHAT], 'state');
    }

    private function stored(string $key): string
    {
        return (string) $this->db->selectSingle('SELECT `value` FROM %%CONFIG_UNIVERSE%% WHERE `uni` = 1 AND `key` = :key', [':key' => $key], 'value');
    }

    public function testChatOnWithChoices(): void
    {
        $this->service()->applyChatSettings(true, false, true);

        $this->assertSame(1, $this->module());
        $this->assertSame('0', $this->stored('chat_global_enabled'));
        $this->assertSame('1', $this->stored('chat_block_links'));
    }

    public function testChatOffKeepsFilterDefaults(): void
    {
        $this->service()->applyChatSettings(false);

        $this->assertSame(0, $this->module());
        $this->assertSame('1', $this->stored('chat_global_enabled'));
        $this->assertSame('1', $this->stored('chat_block_links'));
    }

    public function testBotsSwitch(): void
    {
        $this->service()->applyBotsEnabled(5);
        $this->assertSame('1', $this->stored('bots_enabled'));
        $this->service()->applyBotsEnabled(0);
        $this->assertSame('0', $this->stored('bots_enabled'));
    }

    public function testGameValidationNamesTheField(): void
    {
        $checked = $this->service()->validateGameSettings(['uni_name' => str_repeat('x', 61)], 'en');

        $this->assertNotNull($checked['error']);
        $this->assertSame('uni_name', $checked['field']);
        $this->assertSame('', $this->service()->validateGameSettings([], 'en')['field']);
    }

    /** the options step (chat, bots, folded e-mail and legal blocks) renders with the progress and its posted values */
    public function testOptionsStepRenders(): void
    {
        $this->service()->useLanguage('en');
        $html = self::container()->get('twig')->render('install/ins_options.html.twig', [
            'currentStep' => 4,
            'lang' => 'en',
            'options' => ['chat' => true, 'chat_global' => false, 'chat_block_links' => true, 'bots' => 3],
            'mail' => ['active' => true, 'method' => 2, 'from' => 'a@example.com', 'host' => 'smtp.example.com', 'port' => '587', 'encryption' => 'tls', 'user' => 'bob', 'password' => '', 'user_valid' => false],
            'legal' => ['privacy_region' => 'other', 'rules_default' => 'blank', 'cookie_consent' => false, 'disclamer_mail' => '', 'disclamer_address' => ''],
            'errors' => ['mail' => 'Boom'],
            'testTo' => '',
            'testOk' => null,
            'asset_version' => 1,
        ]);

        $this->assertStringContainsString('action="./?mode=install&amp;step=5"', $html);
        $this->assertMatchesRegularExpression('/name="chat" value="1"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="chat_global" value="1"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="chat_block_links" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('name="bots" value="3"', $html);
        $this->assertMatchesRegularExpression('/<option value="tls" selected/', $html);
        $this->assertMatchesRegularExpression('/value="other" checked/', $html);
        $this->assertStringContainsString('name="test"', $html);
        $this->assertStringContainsString('Boom', $html);
        $this->assertDoesNotMatchRegularExpression('/name="password" value="[^"]/', $html);
        $this->assertStringContainsString('Step 4 of 5', $html);
        // requirements, database, game & admin, options, done
        $this->assertSame(5, substr_count($html, 'install-steps__dot"'));
    }
}
