<?php

namespace App\Game\Core;

/**
 * The game's error handling (formerly the exceptionHandler()/errorHandler() globals): renders the
 * game error page, logs to var/log/game.log, reports to Discord and, in debug mode, opens a support
 * ticket. Used by GameResponseFactory for exceptions thrown in game controllers and registered by
 * GameBootstrap (register()) as PHP's error/exception handler.
 */
class ErrorReporter
{
    use \App\Game\Core\GameUtilities;

    /** names of the PHP error levels in the log and the debug ticket */
    private const ERROR_TYPES = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSING ERROR',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE ERROR',
        E_CORE_WARNING => 'CORE WARNING',
        E_COMPILE_ERROR => 'COMPILE ERROR',
        E_COMPILE_WARNING => 'COMPILE WARNING',
        E_USER_ERROR => 'USER ERROR',
        E_USER_WARNING => 'USER WARNING',
        E_USER_NOTICE => 'USER NOTICE',
        E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER DEPRECATED',
        E_ALL => 'ALL',
    ];

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        // exception details (message, file, trace) are only written into the error page in debug mode
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.debug%')]
        protected bool $debug = false,
    ) {}

    /**
     * Error settings of the game requests: only fatal errors are reported (handleError() turns them
     * into exceptions), logged to var/log/game.log.
     */
    public function register(): void
    {
        error_reporting(E_ERROR);
        // texts with the wrong number of arguments fail loudly in debug, are logged in production
        Language::$strictFormat = $this->debug;
        ini_set('log_errors', '1');
        ini_set('error_log', $this->state->runtime->rootPath . 'var/log/game.log');
        set_exception_handler($this->handleException(...));
        set_error_handler($this->handleError(...));
    }

    /**
     * Last-resort handler for uncaught exceptions and fatal errors: answers 503 with a bilingual error page (details
     * and backtrace only in debug mode), reports the exception to Discord (not during install), appends the error with
     * stack trace to var/log/game.log and, when the `debug` setting is on, opens a support ticket of category 9 with
     * the error text.
     */
    public function handleException(\ErrorException|\Throwable $exception): void
    {
        $this->http()->sendHeader('HTTP/1.1 503 Service Unavailable');

        if (method_exists($exception, 'getSeverity')) {
            $errno = $exception->getSeverity();
        } else {
            $errno = E_USER_ERROR;
        }

        $errorType = self::ERROR_TYPES;

        [$gameName, $version] = $this->gameNameAndVersion();

        ob_start();
        echo $this->errorPage($exception, $gameName, $version);

        if ($this->state->runtime->mode !== 'INSTALL') {
            (new Discord($this->configs, $this->state))->sendException($exception);
        }

        echo str_replace(['\\', $this->state->runtime->rootPath, substr($this->state->runtime->rootPath, 0, 15)], ['/', '/', 'FILEPATH '], ob_get_clean());

        $errorText = $this->logError($exception, $errorType[$errno], $version);

        /* Debug via Support Ticket */
        $config = $this->configs->get();
        if ($config->debug) {
            $this->openDebugTicket($errorType[$errno], $errorText);
        }
    }

    /**
     * Reports an exception that was caught and isolated (no error page, the request goes on): appends it with stack
     * trace to var/log/game.log and sends it to the Discord exceptions webhook. Used for failed fleet events.
     */
    public function reportIsolated(\Throwable $exception, string $context): void
    {
        $errorText = date('[d-M-Y H:i:s]', $this->state->runtime->timestamp) . ' ISOLATED ERROR (' . $context . '): "'
            . strip_tags($exception->getMessage()) . "\"\r\n"
            . 'File: ' . $exception->getFile() . ' | Line: ' . $exception->getLine() . "\r\n"
            . "Stack trace:\r\n"
            . str_replace('\\', '/', $exception->getTraceAsString()) . "\r\n";
        if (is_writable('var/log/game.log')) {
            file_put_contents('var/log/game.log', $errorText, FILE_APPEND);
        }

        try {
            (new Discord($this->configs, $this->state))->sendException($exception);
        } catch (\Throwable) {
            // reporting must never break the caller
        }
    }

    /**
     * Game name and version for the error page and the log: the configured ones, or `-` / the install VERSION file when
     * the config cannot be read (install, broken database).
     *
     * @return array{0: string, 1: string} game name, version
     */
    private function gameNameAndVersion(): array
    {
        if (file_exists($this->state->runtime->rootPath . 'install/VERSION')) {
            $version = file_get_contents($this->state->runtime->rootPath . 'install/VERSION') . ' (FILE)';
        } else {
            $version = 'UNKNOWN';
        }
        $gameName = '-';

        if ($this->state->runtime->mode !== 'INSTALL') {
            try {
                $config = $this->configs->get();
                $gameName = $config->game_name;
                $version = $config->version;
            } catch (\ErrorException $e) {
                error_log('Error while fetching config: ' . $e->getMessage());
            }
        }

        return [$gameName, $version];
    }

    /**
     * The bilingual error page. In debug mode it carries message, file, line, URL, versions and the backtrace in an
     * HTML comment (`--` made harmless).
     */
    private function errorPage(\ErrorException|\Throwable $exception, mixed $gameName, mixed $version): string
    {
        $DIR = ($this->state->runtime->mode === 'INSTALL' || $this->state->runtime->mode === 'UPGRADE') ? '..' : '.';
        return '<!DOCTYPE html>
    <html lang="de">
    <head>
    	<meta charset="utf-8">
    	<title>' . $gameName . ' - Error</title>
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/resource/css/app/base.css">
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/resource/css/app/theme-contract.css">
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/theme/v0game/theme.css">
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/resource/css/app/content.css">
    	<link rel="shortcut icon" href="./favicon.ico" type="image/x-icon">
    </head>
    <body id="overview" class="full">

    <div class="error-report">
        <h2>Ein Fehler ist aufgetreten</h2>
        <p>
            Unser Entwicklungs-Team wurde bereits von dem Fehler in Kenntnis gesetzt und wird ihn zeitnah beheben.<br>
            Sofern zusätzliche Informationen zu Verfügung stellen, um den Fehler zu reproduzieren, melde diese bitte im
            Discord-Channel #bugs.
        </p>
        <p>
            This error has been reported to our dev-team and it will be fixed soon.<br>
            In case you are able to provide additional information in order to reproduce this issue, please send us a
            message within our Discord channel #bugs.
        </p>

        <a class="button" href="/game">Zurück zur Startseite</a>
    </div>

    ' . ($this->debug ? '<!--
    Message: ' . str_replace('--', '- -', htmlspecialchars($exception->getMessage())) . '
    File: ' . htmlspecialchars($exception->getFile()) . '
    Line: ' . $exception->getLine() . '
    URL: ' . htmlspecialchars($this->state->runtime->protocol . $this->state->runtime->httpHost . ($this->state->requestServer['REQUEST_URI'] ?? '')) . '
    PHP-Version: ' . PHP_VERSION . '
    PHP-API: ' . php_sapi_name() . '
    V0Game Version: ' . htmlspecialchars((string) $version) . '

    Debug Backtrace:
    ' . str_replace('--', '- -', makebr(htmlspecialchars($exception->getTraceAsString()))) . '
    -->' : '') . '
    </body>
    </html>';
    }

    /**
     * Appends the error with its stack trace to var/log/game.log (when writable).
     *
     * @param string $type error type name, e.g. `USER ERROR`
     * @return string the log text (also used for the debug ticket)
     */
    private function logError(\ErrorException|\Throwable $exception, string $type, mixed $version): string
    {
        $errorText = date("[d-M-Y H:i:s]", $this->state->runtime->timestamp) . ' ' . $type . ': "'
            . strip_tags($exception->getMessage()) . "\"\r\n";
        $errorText .= 'File: ' . $exception->getFile() . ' | Line: ' . $exception->getLine() . "\r\n";
        $errorText .= 'URL: ' . $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->requestServer['REQUEST_URI'] . ' | Version: ' . $version . "\r\n";
        $errorText .= "Stack trace:\r\n";
        $errorText .= str_replace(
            $this->state->runtime->rootPath,
            '/',
            htmlspecialchars(str_replace('\\', '/', $exception->getTraceAsString())),
        ) . "\r\n";

        if (is_writable('var/log/game.log')) {
            file_put_contents('var/log/game.log', $errorText, FILE_APPEND);
        }

        return $errorText;
    }

    /**
     * Debug setting on: opens a support ticket of category 9 owned by `System` (staff only; the triggering player is named in
     * the text, never the owner, so he cannot read the stack trace) and puts the log text into its first answer.
     */
    private function openDebugTicket(string $type, string $errorText): void
    {
        // the ticket belongs to "System" (staff only): stack traces and URLs must not be readable by the player who
        // triggered the error; he is only named in the text
        $USER = $this->state->USER;
        if (isset($USER) && is_array($USER) && !empty($USER['id']) && !empty($USER['username'])) {
            $errorText = 'Player: ' . $USER['username'] . ' (#' . (int) $USER['id'] . ')' . "\n" . $errorText;
        }

        $ticketObj = new SupportTickets($this->db, $this->configs, $this->state);
        $ticketID = $ticketObj->createTicket(0, 9, $type);
        $ticketObj->createAnswer($ticketID, 0, 'System', $type, $errorText, 0);
    }

    /** PHP errors become ErrorExceptions (only those covered by error_reporting()). */
    public function handleError($errno, $errstr, $errfile, $errline): bool
    {
        if (!($errno & error_reporting())) {
            return false;
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
}
