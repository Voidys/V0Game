<?php

namespace App\Game\Core;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Posts messages to Discord webhooks (configured in the settings): server errors (discord_exceptions_hook) and
 * log/cronjob messages (discord_logs_hook). Does nothing when the matching hook is empty. Needs no database, so it
 * can report errors even when the database is down.
 */
class Discord
{
    private HttpClientInterface $httpClient;

    /**
     * No database on purpose: reports errors even when the database is down.
     *
     * @param HttpClientInterface|null $httpClient transport; null builds a default client (this class is created
     *                                             with `new` in many places, also from the error reporter)
     */
    public function __construct(
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /** Only https://discord.com|discordapp.com (also ptb./canary.) /api/webhooks/{id}/{token} URLs, no query/fragment/port/userinfo (no SSRF via hooks). */
    public static function isAllowedWebhook(string $url): bool
    {
        return preg_match('#^https://(?:discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/\d{1,25}/[A-Za-z0-9_-]{20,100}$#D', $url) === 1;
    }

    /**
     * Sends an exception (message, user, file, line, URL, backtrace) to the exceptions webhook.
     */
    public function sendException(Throwable $exception): void
    {
        $config = $this->configs->get();

        if (!isset($config->discord_exceptions_hook) || empty($config->discord_exceptions_hook)) {
            return;
        }

        $USER = $this->state->USER;
        if (isset($USER) && is_array($USER) && !empty($USER['id']) && !empty($USER['username'])) {
            $ErrSource = $USER['id'];
            $ErrName = $USER['username'];
        } else {
            $ErrSource = 1;
            $ErrName = 'System';
        }

        $message = str_replace('<br>', PHP_EOL, $exception->getMessage());

        $this->send(
            $config->discord_exceptions_hook,
            '**' . $message . '**' . PHP_EOL
            . '```'
            . 'User-Info: ' . $ErrSource . ' ' . $ErrName . PHP_EOL
            . 'File: ' . $exception->getFile() . PHP_EOL
            . 'Line: ' . $exception->getLine() . PHP_EOL
            . 'URL: ' . $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->requestServer['REQUEST_URI'] . PHP_EOL
            . 'Debug Backtrace: ' . PHP_EOL . htmlspecialchars($exception->getTraceAsString())
            . '```',
        );
    }

    /**
     * Sends a titled log message to the logs webhook; the data lines come from $data, or from the exception's properties when only $exception is given.
     * @param array<string,mixed>|null $data key => value lines.
     */
    public function sendLog(string $title, ?array $data = null, ?Throwable $exception = null): void
    {
        $config = $this->configs->get();

        if (!isset($config->discord_logs_hook) || empty($config->discord_logs_hook)) {
            return;
        }

        $data = $data ?? (array) $exception;
        $message = '**' . $title . '**' . PHP_EOL . '```';
        foreach ($data ?? [] as $key => $row) {
            if (is_array($row)) {
                $message .= $key . ': ' . json_encode($row) . PHP_EOL;
            } else {
                $message .= $key . ': ' . $row . PHP_EOL;
            }
        }
        $message .= '```';

        $this->send($config->discord_logs_hook, $message);
    }

    /**
     * You can grab the discord IDs by turning on developer mode (settings -> (App-Settings) Advanced), and then right clicking on the relevant channel/user and using "copy ID"
     * <#channel_id>
     * <@user_id>
     * <@&role_id> \@role to get ID
     * <:emojiName:emojiId> \:customEmojiName: to get ID
     *
     * @param array<mixed> $content
     */
    public function sendMessage(string $webHookUrl, string $title, array $content): void
    {
        if (!isset($webHookUrl) || empty($webHookUrl) || empty($title) || empty($content)) {
            return;
        }

        $message = '**' . $title . '**' . PHP_EOL . '```';
        foreach ($content ?? [] as $key => $row) {
            if (is_array($row)) {
                $message .= $key . ': ' . json_encode($row) . PHP_EOL;
            } else {
                $message .= $key . ': ' . $row . PHP_EOL;
            }
        }
        $message .= '```';

        $this->send($webHookUrl, $message);
    }

    /**
     * Builds the webhook JSON payload (content, username = game name, tts, embeds).
     *
     * @return string JSON, slashes and unicode unescaped
     */
    public function buildPayload(string $message): string
    {
        return json_encode([
            'content' => $message,
            'username' => (string) $this->configs->get()->game_name,
            'tts' => false,
            'allowed_mentions' => ['parse' => []],
            'embeds' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * POSTs the message to the webhook (only Discord webhook URLs, redirects not followed, response ignored). Failures are swallowed on purpose:
     * reporting an error must never raise another one.
     */
    private function send(string $webHookUrl, string $message): void
    {
        if (!self::isAllowedWebhook($webHookUrl)) {
            return;
        }
        try {
            $this->httpClient->request('POST', $webHookUrl, [
                'max_redirects' => 0,
                'timeout' => 5,
                'headers' => ['Content-type' => 'application/json'],
                'body' => $this->buildPayload($message),
            ])->getStatusCode();
        } catch (Throwable) {
            // webhook unreachable or invalid: nothing sensible left to report to
        }
    }
}
