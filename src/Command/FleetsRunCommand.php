<?php

declare(strict_types=1);

namespace App\Command;

use App\Game\Core\EndOfRequest;
use App\Game\Cronjob\FleetEventCronjob;
use App\Runtime\GameKernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Processes the due fleet events (arrivals, returns, stays) of all universes once, independent of page views.
 * One run is one process because the game fixes its request time once per process: the DDEV daemon and the
 * production setup call it in a short loop (every 5 seconds, see "Cron job" in README.md). Safe to run in parallel with
 * page requests and the cron task (atomic event claims).
 */
#[AsCommand(name: 'app:fleets:run', description: 'Processes the due fleet events of all universes')]
final class FleetsRunCommand extends Command
{
    public function __construct(private readonly GameKernel $kernel, private readonly FleetEventCronjob $fleetEvents)
    {
        parent::__construct();
    }

    /**
     * Boots the game in CLI mode and runs the due fleet events. FAILURE when the game is not ready (install/upgrade pending).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->kernel->bootCli();
        } catch (EndOfRequest) {
            $output->writeln('<error>The game is not ready (installation or database upgrade pending).</error>');

            return Command::FAILURE;
        }

        $claimed = $this->fleetEvents->processDueEvents();
        $output->writeln(sprintf('%d fleet event(s) processed.', $claimed), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
