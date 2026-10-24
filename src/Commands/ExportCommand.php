<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Batteryincluded\BatteryincludedBundle\Sync\SyncResult;
use BatteryIncludedSdk\Dto\AbstractDto;
use BatteryIncludedSdk\Service\Response;
use BatteryIncludedSdk\Service\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand(name: 'bi:export', description: 'Exports the documents of all data providers to BatteryIncluded (full import).')]
class ExportCommand extends Command
{
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly int $batchSize = 500,
        private readonly ?LockFactory $lockFactory = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Name of the collection in the bundle configuration', BatteryincludedBundle::DEFAULT_COLLECTION)
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Export only these providers (class or short class name), their documents are created or replaced, nothing is deleted')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Documents per request', (string) $this->batchSize)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Loads and serializes all documents without sending them')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skips the confirmation of the full import, required without interaction')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command sends the documents of all services implementing
                <comment>DataProviderInterface</comment> within one transaction. When the last batch is committed,
                <error>all other documents of the collection are deleted</error>.

                  <info>php %command.full_name% --dry-run</info>      checks the providers without sending anything
                  <info>php %command.full_name% --force</info>        full import without confirmation (cron, CI)
                  <info>php %command.full_name% -p ProductProvider</info>  creates or replaces the products, keeps all other documents
                  <info>php %command.full_name% -v</info>             prints every api response
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = (string) $input->getOption('collection');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = (bool) $input->getOption('dry-run');
        $selected = (array) $input->getOption('provider');
        $fullImport = $selected === [];

        if ($batchSize < 1) {
            $io->error('The batch size has to be at least 1.');

            return Command::INVALID;
        }

        try {
            $config = $this->collections->getConfig($collection);
            $providers = $this->filterProviders($this->collections->getProviders($collection), $selected);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($providers === []) {
            $io->error(sprintf('No data provider for the collection "%s", implement %s.', $collection, DataProviderInterface::class));

            return Command::FAILURE;
        }

        $io->title(sprintf('%s %s into %s', $dryRun ? 'Dry run of the export' : 'Export', $fullImport ? '(full import)' : '(create or replace)', $config['collection']));
        $io->listing(array_map(static fn (DataProviderInterface $provider) => $provider::class, $providers));

        if (!$dryRun && $fullImport && !$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('A full import deletes all documents which are not exported, confirm it with --force.');

                return Command::FAILURE;
            }
            if (!$io->confirm(sprintf('All documents of "%s" which are not exported are deleted. Continue?', $config['collection']), false)) {
                return Command::FAILURE;
            }
        }

        $lock = $dryRun ? null : $this->createLock($collection);
        if ($lock !== null && !$lock->acquire()) {
            $io->error(sprintf('Another export into the collection "%s" is running.', $collection));

            return Command::FAILURE;
        }

        try {
            return $this->export($io, $collection, $providers, $batchSize, $dryRun, $fullImport);
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param list<DataProviderInterface> $providers
     */
    private function export(SymfonyStyle $io, string $collection, array $providers, int $batchSize, bool $dryRun, bool $fullImport): int
    {
        $syncService = $this->collections->get($collection, SyncService::class);
        $transactionId = 'bi-export-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $result = new SyncResult();
        $statistics = [];
        $documentIds = [];
        $duplicates = [];
        // the last batch is held back and sent with the commit, so the commit never contains an extra document
        $pending = null;

        $progress = $io->isVerbose() ? null : $io->createProgressBar();
        $progress?->setFormat(' %message%: %current% documents, %elapsed:6s%, %memory:6s%');
        $start = microtime(true);

        try {
            foreach ($providers as $provider) {
                $providerStart = microtime(true);
                $statistics[$provider::class] = ['documents' => 0, 'batches' => 0, 'time' => 0.0];
                $progress?->setMessage($this->shortName($provider::class));
                $progress?->display();

                foreach ($provider->getBatches($batchSize) as $batch) {
                    /** @var list<AbstractDto> $batch */
                    $batch = array_values($batch);
                    if ($batch === []) {
                        continue;
                    }
                    $statistics[$provider::class]['documents'] += count($batch);
                    ++$statistics[$provider::class]['batches'];

                    if ($dryRun) {
                        foreach ($batch as $document) {
                            json_encode($document, JSON_THROW_ON_ERROR);
                            $id = $document->getIdentifier();
                            if (isset($documentIds[$id])) {
                                $duplicates[$id] = true;
                            }
                            $documentIds[$id] = true;
                        }
                    } elseif ($fullImport) {
                        if ($pending !== null) {
                            $this->send($io, $result, $syncService->syncFullBatchElements($transactionId, false, ...$pending), count($pending));
                        }
                        $pending = $batch;
                    } else {
                        $this->send($io, $result, $syncService->syncOneOrManyElements(...$batch), count($batch));
                    }
                    $progress?->advance(count($batch));
                }
                $statistics[$provider::class]['time'] = microtime(true) - $providerStart;
                $io->writeln(sprintf('%s: %d documents', $provider::class, $statistics[$provider::class]['documents']), OutputInterface::VERBOSITY_VERBOSE);
            }

            if (!$dryRun && $fullImport) {
                if ($pending === null) {
                    $progress?->clear();
                    $io->error('The providers delivered no documents. The transaction is not committed, a full import without documents would empty the collection.');

                    return Command::FAILURE;
                }
                $progress?->setMessage('Commit');
                $this->send($io, $result, $syncService->syncFullBatchElements($transactionId, true, ...$pending), count($pending));
                $progress?->advance(count($pending));
            }
        } catch (ApiException|\JsonException $exception) {
            $progress?->clear();
            $io->error([
                $exception->getMessage(),
                $fullImport && !$dryRun ? 'The transaction is not committed, the collection is unchanged.' : 'Documents sent so far are kept.',
            ]);

            return Command::FAILURE;
        }

        $progress?->finish();
        $io->newLine(2);
        $io->table(
            ['Provider', 'Documents', 'Batches', 'Time'],
            array_map(fn (string $class, array $row) => [$this->shortName($class), $row['documents'], $row['batches'], Helper::formatTime($row['time'])], array_keys($statistics), $statistics),
        );

        $total = array_sum(array_column($statistics, 'documents'));
        if ($dryRun) {
            if ($duplicates !== []) {
                $io->warning(sprintf('%d document ids are delivered more than once, the last one wins: %s', count($duplicates), implode(', ', array_slice(array_keys($duplicates), 0, 10))));
            }
            $io->success(sprintf('Dry run: %d documents serialized, nothing was sent.', $total));

            return Command::SUCCESS;
        }

        if (!$result->isSuccessful()) {
            $io->warning(sprintf('%d of %d documents were rejected:', count($result->getFailed()), $result->getSent()));
            $io->listing(array_map(static fn (array $failure) => $failure['id'] . ': ' . $failure['error'], array_slice($result->getFailed(), 0, 20)));

            return Command::FAILURE;
        }

        $io->success(sprintf('Exported %d documents in %s.', $total, Helper::formatTime(microtime(true) - $start)));

        return Command::SUCCESS;
    }

    /**
     * Stops before the commit as soon as the api answers with an error status, the transaction is then discarded.
     */
    private function send(SymfonyStyle $io, SyncResult $result, Response $response, int $count): void
    {
        $io->writeln('API response: ' . $response->getStatusCode() . ' ' . $response->getRawResponse(), OutputInterface::VERBOSITY_VERBOSE);
        $result->addResponse(ApiException::assertSuccessful($response, 'Export'), $count);
    }

    /**
     * @param list<DataProviderInterface> $providers
     * @param list<string>                $selected  class or short class names
     *
     * @return list<DataProviderInterface>
     */
    private function filterProviders(array $providers, array $selected): array
    {
        if ($selected === []) {
            return $providers;
        }

        $filtered = [];
        foreach ($selected as $name) {
            $matches = array_filter($providers, fn (DataProviderInterface $provider) => in_array(mb_strtolower($name), [mb_strtolower($provider::class), mb_strtolower($this->shortName($provider::class))], true));
            if ($matches === []) {
                throw new \InvalidArgumentException(sprintf('Unknown provider "%s", available are: %s.', $name, implode(', ', array_map(static fn (DataProviderInterface $provider) => $provider::class, $providers))));
            }
            foreach ($matches as $provider) {
                $filtered[spl_object_id($provider)] = $provider;
            }
        }

        return array_values($filtered);
    }

    private function createLock(string $collection): ?LockInterface
    {
        if (!class_exists(LockFactory::class)) {
            return null;
        }

        return ($this->lockFactory ?? new LockFactory(new FlockStore()))->createLock('batteryincluded-export-' . $collection, 3600);
    }

    private function shortName(string $class): string
    {
        return mb_substr($class, (int) mb_strrpos($class, '\\') + 1);
    }
}
