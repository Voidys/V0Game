<?php

namespace App\Game\Achievements;

use App\Game\Core\Database;

/**
 * Keeps achievements cosmetic: a failing achievement check (missing column before a migration ran, bad data, ...) is
 * logged and swallowed instead of rolling back the battle, fleet event or purchase it was called from.
 */
class AchievementGuard
{
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Runs one achievement check. Any Throwable is logged (one line) and swallowed, except deadlocks and lock wait
     * timeouts: InnoDB already rolled the surrounding transaction back, so the caller must see them (the retry in
     * Database::transactional depends on it).
     *
     * @param callable(): mixed $check
     * @throws \Throwable deadlock (1213) or lock wait timeout (1205)
     */
    public function run(callable $check): void
    {
        try {
            $check();
        } catch (\Throwable $exception) {
            if (Database::isRetryable($exception)) {
                throw $exception;
            }
            $this->log($exception);
        }
    }

    private function log(\Throwable $exception): void
    {
        try {
            $line = date('[d-M-Y H:i:s]', $this->state->runtime->timestamp) . ' achievement check failed: "'
                . strip_tags($exception->getMessage()) . '" in ' . basename($exception->getFile()) . ':' . $exception->getLine() . "\r\n";
            $file = \App\Runtime\ProjectPaths::ROOT . 'var/log/game.log';
            if (is_writable($file)) {
                file_put_contents($file, $line, FILE_APPEND);
            } else {
                error_log(trim($line));
            }
        } catch (\Throwable) {
            // logging must never break the caller
        }
    }
}
