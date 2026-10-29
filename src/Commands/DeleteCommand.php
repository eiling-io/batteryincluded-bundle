<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use BatteryIncludedSdk\Exception\BatteryIncludedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bi:delete', description: 'Deletes documents from the collection.')]
class DeleteCommand extends Command
{
    public function __construct(
        private readonly DocumentSynchronizer $synchronizer,
        private readonly CollectionRegistry $collections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Document ids, e.g. PRODUCT-5 BLOG-3')
            ->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Name of the collection in the bundle configuration', BatteryincludedBundle::DEFAULT_COLLECTION)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skips the confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $ids */
        $ids = array_values(array_unique((array) $input->getArgument('ids')));
        $collection = (string) $input->getOption('collection');

        try {
            $config = $this->collections->getConfig($collection);
            if (!$input->getOption('force')) {
                if (!$input->isInteractive()) {
                    $io->error('Confirm the deletion with --force.');

                    return Command::FAILURE;
                }
                if (!$io->confirm(sprintf('Delete %d documents from "%s"?', count($ids), $config['collection']), false)) {
                    return Command::FAILURE;
                }
            }

            $deleted = $this->synchronizer->delete($ids, $collection);
        } catch (BatteryIncludedException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($deleted < count($ids)) {
            $io->warning(sprintf('%d of %d documents deleted, the others did not exist.', $deleted, count($ids)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d documents deleted.', $deleted));

        return Command::SUCCESS;
    }
}
