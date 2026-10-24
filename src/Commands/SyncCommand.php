<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bi:sync', description: 'Creates or replaces single documents, all other documents are kept.')]
class SyncCommand extends Command
{
    public function __construct(private readonly DocumentSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Document ids, e.g. PRODUCT-5 BLOG-3')
            ->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Name of the collection in the bundle configuration', BatteryincludedBundle::DEFAULT_COLLECTION)
            ->addOption('delete-missing', null, InputOption::VALUE_NONE, 'Deletes the ids no provider delivers a document for, e.g. deactivated products')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Shows the documents without sending them')
            ->setHelp('The documents are loaded from the data providers implementing <comment>PartialDataProviderInterface</comment>.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $ids */
        $ids = array_values(array_unique((array) $input->getArgument('ids')));
        $collection = (string) $input->getOption('collection');

        try {
            if ($input->getOption('dry-run')) {
                ['documents' => $documents, 'missing' => $missing] = $this->synchronizer->findDocuments($ids, $collection);
                foreach ($documents as $document) {
                    $io->section($document->getIdentifier());
                    $io->writeln((string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                if ($missing !== []) {
                    $io->warning('No document for: ' . implode(', ', $missing));
                }
                $io->success(sprintf('Dry run: %d documents found, nothing was sent.', count($documents)));

                return Command::SUCCESS;
            }

            $result = $this->synchronizer->syncByIds($ids, $collection, (bool) $input->getOption('delete-missing'));
        } catch (ApiException|\InvalidArgumentException|\LogicException|\JsonException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($result->getMissing() !== []) {
            $io->warning(sprintf('No document for: %s%s', implode(', ', $result->getMissing()), $input->getOption('delete-missing') ? sprintf(' (%d deleted)', $result->getDeleted()) : ', use --delete-missing to delete them'));
        }
        if (!$result->isSuccessful()) {
            $io->error(array_map(static fn (array $failure) => $failure['id'] . ': ' . $failure['error'], $result->getFailed()));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d documents synced.', $result->getSynced()));

        return Command::SUCCESS;
    }
}
