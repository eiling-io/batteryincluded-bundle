<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;
use BatteryIncludedSdk\Dto\AbstractDto;
use BatteryIncludedSdk\Service\SyncService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates, replaces and deletes single documents without a full import, used by `bi:sync`, `bi:delete` and the
 * messenger handlers. Call it from your code (e.g. after saving a product) or dispatch a SyncDocumentsMessage.
 */
class DocumentSynchronizer
{
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly int $batchSize = 500,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * Collects the documents of the ids from all providers implementing PartialDataProviderInterface.
     *
     * @param list<string> $ids document ids, e.g. `PRODUCT-5`
     *
     * @return array{documents: list<AbstractDto>, missing: list<string>}
     */
    public function findDocuments(array $ids, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): array
    {
        $providers = array_filter($this->collections->getProviders($collection), static fn ($provider) => $provider instanceof PartialDataProviderInterface);
        if ($providers === []) {
            throw new \LogicException(sprintf('No data provider of the collection "%s" implements %s.', $collection, PartialDataProviderInterface::class));
        }

        $ids = array_values(array_unique($ids));
        $documents = [];
        foreach ($providers as $provider) {
            foreach ($provider->getByIds($ids) as $document) {
                // providers may ignore the filter, only the requested documents are synced
                if (in_array($document->getIdentifier(), $ids, true)) {
                    $documents[$document->getIdentifier()] = $document;
                }
            }
        }

        return [
            'documents' => array_values($documents),
            'missing' => array_values(array_diff($ids, array_keys($documents))),
        ];
    }

    /**
     * @param list<string> $ids           document ids, e.g. `PRODUCT-5`
     * @param bool         $deleteMissing ids without document (e.g. deactivated products) are deleted from the collection
     *
     * @throws ApiException
     */
    public function syncByIds(array $ids, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, bool $deleteMissing = false): SyncResult
    {
        ['documents' => $documents, 'missing' => $missing] = $this->findDocuments($ids, $collection);

        $result = $documents !== [] ? $this->sync($documents, $collection) : new SyncResult();
        $result->setMissing($missing);
        if ($deleteMissing && $missing !== []) {
            $result->setDeleted($this->delete($missing, $collection));
        }

        return $result;
    }

    /**
     * Creates or replaces the documents, all other documents of the collection are kept.
     *
     * @param iterable<AbstractDto> $documents
     *
     * @throws ApiException
     */
    public function sync(iterable $documents, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): SyncResult
    {
        $syncService = $this->collections->get($collection, SyncService::class);
        $result = new SyncResult();
        $batch = [];
        foreach ($documents as $document) {
            $batch[] = $document;
            if (count($batch) >= $this->batchSize) {
                $this->sendBatch($syncService, $result, $batch, $collection);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->sendBatch($syncService, $result, $batch, $collection);
        }

        return $result;
    }

    /**
     * @param list<string> $ids document ids, e.g. `PRODUCT-5`
     *
     * @throws ApiException
     * @return int number of deleted documents
     */
    public function delete(array $ids, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): int
    {
        if ($ids === []) {
            return 0;
        }

        $response = $this->collections->get($collection, SyncService::class)->deleteElementsByIds(...array_values(array_unique($ids)));

        return (int) (ApiException::assertSuccessful($response, 'Delete')->getBody()['num_deleted'] ?? 0);
    }

    /**
     * @param list<AbstractDto> $batch
     */
    private function sendBatch(SyncService $syncService, SyncResult $result, array $batch, string $collection): void
    {
        $event = new DocumentsEvent($batch, $collection, DocumentsEvent::CONTEXT_SYNC);
        $this->eventDispatcher?->dispatch($event);
        if ($event->getDocuments() === []) {
            return;
        }

        $result->addResponse(ApiException::assertSuccessful($syncService->syncOneOrManyElements(...$event->getDocuments()), 'Sync'), count($event->getDocuments()));
    }
}
