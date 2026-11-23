<?php

declare(strict_types=1);

namespace App\Command;

use App\Game\Core\EndOfRequest;
use App\Runtime\SafeFile;
use App\Runtime\GameKernel;
use App\Runtime\InstallService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Command line installer: the same steps as the web wizard (App\Runtime\InstallService), including the chat options
 * (--chat, --chat-global/--no-chat-global, --chat-block-links/--no-chat-block-links; chat is off unless --chat).
 *
 * Phase 1 (this process): requirements, database connection, config/game.php and .env.local.
 * Phase 2 (a child process started with --finish, because the game's database connection reads
 * .env.local when the process starts): tables, game settings, administrator, bots, lock.
 * The administrator password never appears on a command line: it is taken from --admin-password, the
 * environment variable V0GAME_ADMIN_PASSWORD or a hidden prompt, and handed to phase 2 through its environment.
 */
#[AsCommand(name: 'app:install', description: 'Installs a new V0Game (same steps as the web installer)')]
final class InstallCommand extends Command
{
    private const PAYLOAD_ENV = 'V0GAME_INSTALL_PAYLOAD';

    public function __construct(private readonly InstallService $installer, private readonly GameKernel $kernel)
    {
        parent::__construct();
    }

    /**
     * Options of `bin/console app:install`; every option is optional and asked interactively when missing
     * (--no-interaction uses defaults or fails for required values). Secrets can also come from the environment
     * (V0GAME_DB_PASSWORD, V0GAME_ADMIN_PASSWORD); --finish is the internal second phase run after the database step.
     */
    protected function configure(): void
    {
        $this
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database host', null)
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Database port', null)
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Database user')
            ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Database password (or env V0GAME_DB_PASSWORD)')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Database name (created if missing)')
            ->addOption('db-prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix', null)
            ->addOption('game-name', null, InputOption::VALUE_REQUIRED, 'Game name', null)
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Default language (de, en, ...)', null)
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Time zone, e.g. Europe/Berlin (default: server time zone)')
            ->addOption('speed', null, InputOption::VALUE_REQUIRED, 'Speed preset: classic, fast or blitz', null)
            ->addOption('registration', null, InputOption::VALUE_REQUIRED, 'Registration: open or closed', null)
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Administrator user name')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Administrator e-mail address')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Administrator password (or env V0GAME_ADMIN_PASSWORD, or prompt)')
            ->addOption('bots', null, InputOption::VALUE_REQUIRED, 'Number of bots to create, 0 to 50 (0 = bots off)', null)
            ->addOption('chat', null, InputOption::VALUE_NEGATABLE, 'Enable the in-game chat (default: off; --no-chat is the same)', null)
            ->addOption('chat-global', null, InputOption::VALUE_NEGATABLE, 'Chat: global channel (default: on; only with --chat)', null)
            ->addOption('chat-block-links', null, InputOption::VALUE_NEGATABLE, 'Chat: block links in the global channel (default: on; only with --chat)', null)
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public URL of the game, e.g. https://example.com')
            ->addOption('finish', null, InputOption::VALUE_NONE, 'Internal: second phase');
    }

    /**
     * Phase 1 of the unattended installer (or phase 2 with --finish). Values come from options, then (interactive)
     * prompts, then defaults; secrets can come from environment variables. Validates everything up front and returns
     * Command::INVALID for bad input, FAILURE when the requirements or the database step fail. Writes
     * config/game.php and .env.local, then re-runs itself with --finish.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('finish')) {
            return $this->finish($io);
        }

        if ($this->installer->isInstalled()) {
            $io->error('V0Game is already installed (config/game.php exists). Remove config/game.php and .env.local only if you want to start over with an empty database.');

            return Command::FAILURE;
        }

        $interactive = $input->isInteractive();
        $ask = static fn(string $question, ?string $default = null) => $interactive ? $io->ask($question, $default) : $default;

        // language first: it selects the texts of the checks
        $languages = $this->installer->allowedLanguages();
        $language = (string) ($input->getOption('language')
            ?? ($interactive ? $io->choice('Default language', array_keys($languages), 'en') : 'en'));
        if (!isset($languages[$language])) {
            $io->error(sprintf('Unknown language "%s" (available: %s).', $language, implode(', ', array_keys($languages))));

            return Command::INVALID;
        }

        $io->title('V0Game installer');
        $requirements = $this->installer->requirements($language);
        $this->printChecks($io, $requirements['checks']);
        if (!$requirements['passed']) {
            $io->error('The server does not meet the requirements above.');

            return Command::FAILURE;
        }

        $database = $this->collectDatabaseSettings($input, $io, $ask, $interactive);
        if (is_int($database)) {
            return $database;
        }
        ['host' => $host, 'port' => $port, 'user' => $user, 'password' => $password, 'dbName' => $dbName, 'prefix' => $prefix] = $database;

        $game = $this->collectGameSettings($input, $io, $ask, $interactive, $language);
        if (is_int($game)) {
            return $game;
        }
        $checked = $game;

        $chat = $this->collectChatSettings($input, $io, $interactive);

        $admin = $this->collectAdmin($input, $io, $ask, $interactive, $language);
        if (is_int($admin)) {
            return $admin;
        }
        ['name' => $adminName, 'mail' => $adminMail, 'password' => $adminPassword] = $admin;

        // phase 1: database connection and configuration files
        $io->section('Database');
        $result = $this->installer->prepareDatabase(
            ['host' => $host, 'port' => $port, 'user' => $user, 'dbname' => $dbName, 'prefix' => $prefix, 'createdb' => true],
            (string) $password,
            $language,
            (string) ($input->getOption('base-url') ?? ''),
        );
        $this->printChecks($io, $result['checks']);
        if (!$result['passed']) {
            $io->error('The database step failed. Nothing was installed.');

            return Command::FAILURE;
        }

        return $this->runFinishPhase($io, $output, $input, $language, $checked + ['chat' => $chat], $adminName, $adminMail, $adminPassword, $result['generated']);
    }

    /**
     * Reads the database connection settings (options, then environment, then prompts, then defaults).
     *
     * @param callable(string, ?string=): mixed $ask prompt helper (returns the default when not interactive)
     * @return array{host: string, port: string, user: string, password: mixed, dbName: string, prefix: string}|int the settings, or an exit code (Command::INVALID) when user or name are missing
     */
    private function collectDatabaseSettings(InputInterface $input, SymfonyStyle $io, callable $ask, bool $interactive): array|int
    {
        $host = (string) ($input->getOption('db-host') ?? $ask('Database host', 'localhost'));
        $port = (string) ($input->getOption('db-port') ?? '3306');
        $user = (string) ($input->getOption('db-user') ?? $ask('Database user'));
        $password = $input->getOption('db-password') ?? (getenv('V0GAME_DB_PASSWORD') !== false ? getenv('V0GAME_DB_PASSWORD') : null);
        if ($password === null && $interactive) {
            $password = (string) $io->askHidden('Database password (hidden)');
        }
        $dbName = (string) ($input->getOption('db-name') ?? $ask('Database name'));
        $prefix = (string) ($input->getOption('db-prefix') ?? 'uni1_');
        if ($user === '' || $dbName === '') {
            $io->error('Database user (--db-user) and name (--db-name) are required.');

            return Command::INVALID;
        }

        return ['host' => $host, 'port' => $port, 'user' => $user, 'password' => $password, 'dbName' => $dbName, 'prefix' => $prefix];
    }

    /**
     * Reads and validates the game settings (name, time zone, speed preset, registration, bots).
     *
     * @param callable(string, ?string=): mixed $ask
     * @return array{error: ?string, values: array}|int the validation result, or Command::INVALID with the error printed
     */
    private function collectGameSettings(InputInterface $input, SymfonyStyle $io, callable $ask, bool $interactive, string $language): array|int
    {
        $gameName = (string) ($input->getOption('game-name') ?? $ask('Game name', 'V0Game'));
        $timezone = (string) ($input->getOption('timezone') ?? $this->installer->defaultTimezone());
        $speed = (string) ($input->getOption('speed')
            ?? ($interactive ? $io->choice('Game speed (classic = original pace, fast ~3x, blitz up to 10x)', array_keys($this->installer->speedPresets()), 'classic') : 'classic'));
        $registration = (string) ($input->getOption('registration')
            ?? ($interactive ? $io->choice('Registration', ['open', 'closed'], 'open') : 'open'));
        $bots = (int) ($input->getOption('bots') ?? $ask('Number of bots (0-50, 0 = none)', '0'));
        $checked = $this->installer->validateGameSettings([
            'game_name' => $gameName,
            'default_theme' => DEFAULT_THEME,
            'language' => $language,
            'timezone' => $timezone,
            'speed' => $speed,
            'registration' => $registration,
            'mail' => false,
            'bots' => $bots,
        ], $language);
        if ($checked['error'] !== null) {
            $io->error($checked['error']);

            return Command::INVALID;
        }

        return $checked;
    }

    /**
     * Reads the chat choice: --chat / --no-chat (asked when interactive and not given, default off), then the global
     * channel and link blocking (default on).
     *
     * @return array{enabled: bool, global: bool, block_links: bool}
     */
    private function collectChatSettings(InputInterface $input, SymfonyStyle $io, bool $interactive): array
    {
        $enabled = $input->getOption('chat');
        if ($enabled === null) {
            $enabled = $interactive && $io->confirm('Enable the in-game chat?', false);
        }
        $global = $input->getOption('chat-global');
        if ($enabled && $global === null && $interactive) {
            $global = $io->confirm('Chat: open a global channel for all players?', true);
        }

        return [
            'enabled' => (bool) $enabled,
            'global' => $global ?? InstallService::CHAT_DEFAULTS['chat_global'],
            'block_links' => $input->getOption('chat-block-links') ?? InstallService::CHAT_DEFAULTS['chat_block_links'],
        ];
    }

    /**
     * Reads the administrator (name, e-mail, password from option, environment or hidden prompt with repetition) and
     * validates it.
     *
     * @param callable(string, ?string=): mixed $ask
     * @return array{name: string, mail: string, password: string}|int the admin, or Command::INVALID with the problems printed
     */
    private function collectAdmin(InputInterface $input, SymfonyStyle $io, callable $ask, bool $interactive, string $language): array|int
    {
        $adminName = (string) ($input->getOption('admin-name') ?? $ask('Administrator user name'));
        $adminMail = (string) ($input->getOption('admin-email') ?? $ask('Administrator e-mail'));
        $adminPassword = $input->getOption('admin-password') ?? (getenv('V0GAME_ADMIN_PASSWORD') !== false ? getenv('V0GAME_ADMIN_PASSWORD') : null);
        if ($adminPassword === null && $interactive) {
            $adminPassword = (string) $io->askHidden(sprintf('Administrator password (hidden, at least %d characters)', InstallService::MIN_PASSWORD_LENGTH));
            $confirm = (string) $io->askHidden('Repeat the password (hidden)');
            if ($confirm !== $adminPassword) {
                $io->error('The two passwords do not match.');

                return Command::INVALID;
            }
        }
        $adminPassword = (string) $adminPassword;
        $problems = $this->installer->validateAdmin($adminName, $adminPassword, $adminPassword, $adminMail, $language);
        if ($problems !== []) {
            $io->error(array_values($problems));

            return Command::INVALID;
        }

        return ['name' => $adminName, 'mail' => $adminMail, 'password' => $adminPassword];
    }

    /**
     * Phase 2: runs `app:install --finish` in a fresh process (so the game connects with the new .env.local) and passes
     * the settings as JSON payload in the environment. A failure removes config/game.php again.
     *
     * @param array<mixed> $checked validated game settings (`values`) plus the chat choice (`chat`, see collectChatSettings())
     * @return int Command::SUCCESS or FAILURE
     */
    private function runFinishPhase(SymfonyStyle $io, OutputInterface $output, InputInterface $input, string $language, array $checked, string $adminName, string $adminMail, string $adminPassword, mixed $migrationGenerated): int
    {
        // phase 2: fresh process, so the game's connection uses the new .env.local
        $io->section('Installing');
        $payload = json_encode([
            'language' => $language,
            'settings' => $checked['values'],
            'chat' => $checked['chat'],
            'admin' => ['name' => $adminName, 'mail' => $adminMail, 'password' => $adminPassword],
            'baseUrl' => rtrim((string) ($input->getOption('base-url') ?? ''), '/'),
            'migrationGenerated' => $migrationGenerated,
        ], JSON_THROW_ON_ERROR);
        $process = new Process(
            [PHP_BINARY, $this->installer->root() . 'bin/console', 'app:install', '--finish', '--no-interaction'] + ($output->isVerbose() ? [5 => '-v'] : []),
            $this->installer->root(),
            [self::PAYLOAD_ENV => $payload, 'DATABASE_URL' => false, 'TABLE_PREFIX' => false],
            null,
            null,
        );
        $exit = $process->run(static function (string $type, string $buffer) use ($output): void {
            // the image library prints harmless libpng notices on stderr
            $buffer = preg_replace('/^libpng warning:.*\R?/m', '', $buffer);
            $output->write((string) $buffer);
        });
        if ($exit !== 0) {
            $io->error('The installation failed; config/game.php was removed so you can run it again (check var/log/game.log and the database).');
            SafeFile::remove($this->installer->root() . 'config/game.php');
        }

        return $exit === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** phase 2 */
    private function finish(SymfonyStyle $io): int
    {
        $raw = getenv(self::PAYLOAD_ENV);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            $io->error('This option is internal.');

            return Command::FAILURE;
        }
        $language = (string) $payload['language'];

        try {
            try {
                $this->kernel->boot('INSTALL', 'install/index');
            } catch (EndOfRequest $end) {
                throw new \RuntimeException('The game could not be started.', 0, $end);
            }
            $this->installer->useLanguage($language);

            foreach ($this->installer->buildTasks($language) as $task => $label) {
                $io->write(' * ' . $label . ' ... ');
                $this->installer->runBuildTask($task, $language);
                $io->writeln('ok');
            }

            $this->installer->applyGameSettings($payload['settings']);
            $chat = $payload['chat'];
            $this->installer->applyChatSettings($chat['enabled'], $chat['global'], $chat['block_links']);
            $io->writeln(' * ' . $this->installer->lng($language)['ins_step_game'] . ' ... ok');
            $this->installer->createAdmin($payload['admin']['name'], $payload['admin']['password'], $payload['admin']['mail'], $language);
            $io->writeln(' * ' . $this->installer->lng($language)['ins_step_admin'] . ' ... ok');
            $botsCreated = 0;
            try {
                $botsCreated = $this->installer->spawnBots((int) $payload['settings']['bots']);
            } catch (\Throwable $e) {
                $io->warning('The bots could not be created (add them later in the administration): ' . $e->getMessage());
            }
            if ($payload['settings']['bots'] > 0) {
                $io->writeln(sprintf(' * bots: %d created', $botsCreated));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->installer->lock();
        $this->installer->takeGeneratedSecret(); // stays in config/game.php, never printed
        $baseUrl = $payload['baseUrl'] !== '' ? $payload['baseUrl'] : 'https://your-domain.example';
        $io->success('V0Game is installed.');
        $io->definitionList(
            ['Admin login' => $baseUrl . '/admin'],
            ['Administrator' => $payload['admin']['name']],
            ['Cron line (required)' => $this->installer->cronLine()],
            ['Cron setup help' => InstallService::CRON_DOCS_URL],
        );
        $io->listing(array_filter([
            $payload['baseUrl'] === '' ? 'WARNING: no --base-url given, so DEFAULT_URI is not set: registration and password-reset mails are not sent until you set DEFAULT_URI in .env.local.' : null,
            'The install tool is locked.',
            $payload['chat']['enabled'] ? sprintf('Chat is on (global channel %s, links %s).', $payload['chat']['global'] ? 'on' : 'off', $payload['chat']['block_links'] ? 'blocked' : 'allowed') : 'Chat is off; turn it on later in the administration.',
            $payload['migrationGenerated'] ? 'A migration password was generated; it is stored as "migrationpw" in config/game.php.' : null,
            'Use HTTPS in production. Change settings later in Admin - Settings.',
            'Documentation: ' . InstallService::DOCS_URL,
        ]));

        return Command::SUCCESS;
    }

    /** @param list<array{label: string, ok: bool, detail: string, required?: bool}> $checks */
    private function printChecks(SymfonyStyle $io, array $checks): void
    {
        foreach ($checks as $check) {
            $mark = $check['ok'] ? '<info>ok</info>  ' : (($check['required'] ?? true) ? '<error>FAIL</error>' : '<comment>warn</comment>');
            $io->writeln(sprintf(' %s %s %s', $mark, html_entity_decode(strip_tags($check['label'])), $check['detail'] !== '' ? '(' . html_entity_decode(strip_tags($check['detail'])) . ')' : ''));
        }
    }
}
