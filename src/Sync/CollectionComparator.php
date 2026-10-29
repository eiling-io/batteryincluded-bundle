<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Document\Document;
use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use BatteryIncludedSdk\Client\ApiClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Compares the documents of the data providers with the documents of the collection without changing anything, used
 * by `bi:diff`. The providers are read like by `bi:export`, including the listeners of the DocumentsEvent.
 */
class CollectionComparator
{
    /**
     * Maximum page size of the browse endpoint.
     */
    public const PAGE_SIZE = 250;

    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly int $batchSize = 500,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param list<string> $types compares only these types, e.g. `['PRODUCT']`, empty for all
     *
     * @throws ApiException
     */
    public function compare(string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, array $types = []): DiffResult
    {
        $types = array_values(array_unique(array_map('mb_strtoupper', $types)));
        $providerIds = $this->providerIds($collection, $types);
        [$indexIds, $found] = $this->indexIds($collection, $types);

        $count = static function (array $ids): array {
            $counts = [];
            foreach (array_keys($ids) as $id) {
                $type = (string) mb_strstr((string) $id, '-', true) ?: '?';
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        };

        $missing = array_map('strval', array_keys(array_diff_key($providerIds, $indexIds)));
        $orphaned = array_map('strval', array_keys(array_diff_key($indexIds, $providerIds)));
        natsort($missing);
        natsort($orphaned);

        return new DiffResult($count($providerIds), $count($indexIds), array_values($missing), array_values($orphaned), $found, count($indexIds));
    }

    /**
     * @param list<string> $types
     *
     * @return array<string, true>
     */
    private function providerIds(string $collection, array $types): array
    {
        $ids = [];
        foreach ($this->collections->getProviders($collection) as $provider) {
            foreach ($provider->getBatches($this->batchSize) as $batch) {
                $event = new DocumentsEvent(array_values($batch), $collection, DocumentsEvent::CONTEXT_EXPORT, $provider);
                $this->eventDispatcher?->dispatch($event);
                foreach ($event->getDocuments() as $document) {
                    if ($types === [] || in_array($document->getType(), $types, true)) {
                        $ids[$document->getIdentifier()] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $types
     *
     * @return array{0: array<string, true>, 1: int} ids and the number of documents the api found
     */
    private function indexIds(string $collection, array $types): array
    {
        $apiClient = $this->collections->get($collection, ApiClient::class);
        $ids = [];
        $found = 0;
        $page = 1;
        do {
            $query = http_build_query(['q' => '', 'f' => $types !== [] ? ['type' => $types] : [], 'page' => $page, 'per_page' => self::PAGE_SIZE]);
            $body = ApiException::assertSuccessful($apiClient->getJson('/documents/browse?' . $query), 'Browse')->getBody();
            $found = (int) ($body['found'] ?? 0);
            $hits = (array) ($body['hits'] ?? []);
            foreach ($hits as $hit) {
                $id = Document::id((array) ($hit['document'] ?? []));
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
            ++$page;
        } while ($hits !== [] && ($page - 1) * self::PAGE_SIZE < $found);

        return [$ids, $found];
    }
}
