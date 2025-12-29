<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use BatteryIncludedSdk\Service\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

#[AsCommand(name: 'bi:export')]
class ExportCommand extends Command
{
    public function __construct(
        private SyncService $syncService,
        #[TaggedIterator(tag: 'batteryincluded.data_provider')]
        private readonly iterable $provider
    )
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('Exports all configured Data to Batteryincluded.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $transactionId = 'Transaction_' . time();

        foreach($this->provider as $provider) {
            foreach($provider->getBatches(500) as $batch) {
               $this->syncService->syncFullBatchElements($transactionId, false, ...$batch);
            }
            $this->syncService->syncFullBatchElements($transactionId, true, ...$batch);
        }
        $output->writeln('<info>Export done.</info>');

        return Command::SUCCESS;
    }
}
