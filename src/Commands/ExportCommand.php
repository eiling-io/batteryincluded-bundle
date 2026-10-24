<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use BatteryIncludedSdk\Dto\ProductBaseDto;
use BatteryIncludedSdk\Service\Response;
use BatteryIncludedSdk\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly iterable $provider,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('Exports all configured data to Batteryincluded.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $transactionId = 'Transaction_' . time();
        $batch[] = new ProductBaseDto('1337'); // to avoid "undefined variable" error
        foreach ($this->provider as $provider) {
            foreach ($provider->getBatches(500) as $batch) {
                $response = $this->syncService->syncFullBatchElements($transactionId, false, ...$batch);
                if (!$this->isSuccessful($response, $output)) {
                    return Command::FAILURE;
                }
            }
            $output->writeln(get_class($provider) . ' export done, committing...');
        }
        $response = $this->syncService->syncFullBatchElements($transactionId, true, ...$batch);
        if (!$this->isSuccessful($response, $output)) {
            return Command::FAILURE;
        }
        $output->writeln('<info>Export done.</info>');

        return Command::SUCCESS;
    }

    private function isSuccessful(Response $response, OutputInterface $output): bool
    {
        $output->writeln('API response: ' . $response->getStatusCode() . ' ' . $response->getRawResponse(), OutputInterface::VERBOSITY_VERBOSE);
        if ($response->getStatusCode() < 400) {
            return true;
        }

        $output->writeln('<error>Export failed with status ' . $response->getStatusCode() . ': ' . $response->getRawResponse() . '</error>');

        return false;
    }
}
