<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use BatteryIncludedSdk\Shop\FacetRangeDto;
use BatteryIncludedSdk\Shop\FacetSelectDto;
use BatteryIncludedSdk\Shop\FacetValueDto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bi:search', description: 'Searches the collection like the shop does.')]
class SearchCommand extends Command
{
    public function __construct(private readonly CollectionRegistry $collections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::OPTIONAL, 'Search term, empty lists all documents', '')
            ->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Name of the collection in the bundle configuration', BatteryincludedBundle::DEFAULT_COLLECTION)
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter as field=value or field=from..till, e.g. type=PRODUCT or _PRODUCT.price=100..500')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sorting, e.g. _PRODUCT.price:asc', '')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Hits per page', '10')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale of the translations', 'de')
            ->addOption('availability', 'a', InputOption::VALUE_REQUIRED, 'Scope of prices and stock, e.g. the country')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Id of a preset', '')
            ->addOption('facets', null, InputOption::VALUE_NONE, 'Shows the facets')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Prints the raw response');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $searchStruct = new BrowseSearchStruct();
        $searchStruct->setQuery((string) $input->getArgument('query'));
        $searchStruct->setSort((string) $input->getOption('sort'));
        $searchStruct->setPage(max(1, (int) $input->getOption('page')));
        $searchStruct->setPerPage(max(1, (int) $input->getOption('per-page')));
        $searchStruct->setLocale((string) $input->getOption('locale'));
        $searchStruct->setPresetId((string) $input->getOption('preset'));
        $searchStruct->setAvailability($input->getOption('availability') !== null ? (string) $input->getOption('availability') : null);
        foreach ((array) $input->getOption('filter') as $filter) {
            [$field, $value] = array_pad(explode('=', (string) $filter, 2), 2, '');
            if ($field === '' || $value === '') {
                $io->error(sprintf('Invalid filter "%s", use field=value or field=from..till.', $filter));

                return Command::INVALID;
            }
            if (preg_match('/^(-?[\d.]*)\.\.(-?[\d.]*)$/', $value, $range) === 1) {
                $searchStruct->addRangeFilter($field, $range[1] !== '' ? $range[1] : '0', $range[2] !== '' ? $range[2] : (string) PHP_INT_MAX);
            } else {
                $searchStruct->addFilter($field, $value);
            }
        }

        try {
            $result = $this->collections->get((string) $input->getOption('collection'), BrowseService::class)->browse($searchStruct);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $body = $result->getBody();
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }
        if (isset($body['message']) && !isset($body['hits'])) {
            $io->error('The api answered: ' . (is_scalar($body['message']) ? $body['message'] : json_encode($body['message'])));

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($result->getHits() as $index => $hit) {
            $document = $hit['document'] ?? [];
            $type = (string) ($document['type'] ?? '');
            $data = $document['_' . mb_strtoupper($type)] ?? [];
            $rows[] = [
                ($searchStruct->getPage() - 1) * $searchStruct->getPerPage() + $index + 1,
                $document['id'] ?? '',
                mb_strimwidth(strip_tags((string) ($data['name'] ?? $data['title'] ?? '')), 0, 60, '…'),
                isset($data['price']) && is_scalar($data['price']) ? $data['price'] : '',
            ];
        }
        $io->table(['#', 'Id', 'Name', 'Price'], $rows);

        if ($input->getOption('facets')) {
            $facetRows = [];
            foreach ($result->getFacets() as $facet) {
                $values = match (true) {
                    $facet instanceof FacetRangeDto => $facet->getMin() . ' – ' . $facet->getMax(),
                    $facet instanceof FacetSelectDto => implode(', ', array_map(static fn (FacetValueDto $value) => $value->getName() . ' (' . $value->getCount() . ')', array_slice((array) $facet->getValues(), 0, 8))),
                    default => '',
                };
                $facetRows[] = [$facet->getFieldLabel(), $facet->getFieldName(), mb_strimwidth($values, 0, 100, '…')];
            }
            $io->table(['Facet', 'Field', 'Values'], $facetRows);
        }

        $io->writeln(sprintf('<info>%d</info> hits, page %d of %d, %d ms', $result->getFound(), $result->getPage(), $result->getPages(), (int) ($body['search_time_ms'] ?? 0)));

        return Command::SUCCESS;
    }
}
