<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Sync\CollectionComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bi:diff', description: 'Compares the documents of the data providers with the collection, nothing is changed.')]
class DiffCommand extends Command
{
    public function __construct(
        private readonly CollectionComparator $comparator,
        private readonly CollectionRegistry $collections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Name of the collection in the bundle configuration', BatteryincludedBundle::DEFAULT_COLLECTION)
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Compares only this document type, e.g. PRODUCT')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Listed ids per difference, 0 lists all', '20')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'txt or json', 'txt')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command loads the ids of all data providers and of the collection and lists

                  <comment>missing</comment>   ids a provider delivers but the collection does not contain, e.g. after a failed sync
                  <comment>orphaned</comment>  ids the collection contains but no provider delivers, e.g. deleted products

                It exits with 1 if there are differences, e.g. for monitoring. Fix them with
                <info>bi:sync <ids></info>, <info>bi:delete <ids></info> or a full import with <info>bi:export</info>.

                The collection needs a few seconds after an export until it contains all documents.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = (string) $input->getOption('collection');
        $format = (string) $input->getOption('format');
        $limit = max(0, (int) $input->getOption('limit'));

        if (!in_array($format, ['txt', 'json'], true)) {
            $io->error('The format has to be txt or json.');

            return Command::INVALID;
        }

        try {
            $config = $this->collections->getConfig($collection);
            $result = $this->comparator->compare($collection, (array) $input->getOption('type'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (ApiException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $exitCode = $result->isConsistent() ? Command::SUCCESS : Command::FAILURE;

        if ($format === 'json') {
            $output->writeln((string) json_encode(['collection' => $config['collection']] + $result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $io->title('Diff of ' . $config['collection']);
        $rows = [];
        foreach ($result->toArray()['types'] as $type => $row) {
            $rows[] = [$type, $row['providers'], $row['index'], $row['missing'] > 0 ? '<error>' . $row['missing'] . '</>' : 0, $row['orphaned'] > 0 ? '<comment>' . $row['orphaned'] . '</>' : 0];
        }
        $io->table(['Type', 'Providers', 'Collection', 'Missing', 'Orphaned'], $rows);

        $list = static fn (array $ids) => $limit === 0 ? $ids : array_slice($ids, 0, $limit);
        $more = static fn (array $ids) => $limit > 0 && count($ids) > $limit ? sprintf(' (first %d of %d)', $limit, count($ids)) : '';
        if ($result->missing !== []) {
            $io->section('Missing in the collection' . $more($result->missing));
            $io->writeln(implode(' ', $list($result->missing)));
        }
        if ($result->orphaned !== []) {
            $io->section('Orphaned in the collection' . $more($result->orphaned));
            $io->writeln(implode(' ', $list($result->orphaned)));
        }
        if (!$result->isComplete()) {
            $io->warning(sprintf('The api found %d documents but delivered %d ids, the orphaned ids are incomplete.', $result->indexFound, $result->indexRead));
        }

        if ($result->isConsistent()) {
            $io->success('The collection contains exactly the documents of the providers.');
        } else {
            $io->error(sprintf('%d missing and %d orphaned documents. Sync them with bi:sync, delete them with bi:delete or run a full import with bi:export.', count($result->missing), count($result->orphaned)));
        }

        return $exitCode;
    }
}
