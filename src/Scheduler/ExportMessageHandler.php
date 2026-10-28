<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Scheduler;

use Batteryincluded\BatteryincludedBundle\Commands\ExportCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the export of an ExportMessage like `bi:export --force`, the lock of the command prevents parallel runs.
 * The output is logged to the `batteryincluded` channel, a failed export throws.
 */
#[AsMessageHandler]
class ExportMessageHandler
{
    public function __construct(
        private readonly ExportCommand $command,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ExportMessage $message): void
    {
        $input = new ArrayInput(['--collection' => $message->collection, '--provider' => $message->providers, '--force' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $exitCode = $this->command->run($input, $output);
        $log = mb_trim($output->fetch());

        if ($exitCode !== Command::SUCCESS) {
            throw new \RuntimeException(sprintf('The scheduled export into the collection "%s" failed with exit code %d: %s', $message->collection, $exitCode, $log));
        }
        $this->logger?->info('Scheduled export into the collection "{collection}" finished: {output}', ['collection' => $message->collection, 'output' => $log]);
    }
}
