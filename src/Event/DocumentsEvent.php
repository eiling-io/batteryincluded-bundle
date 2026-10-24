<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Event;

use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use BatteryIncludedSdk\Dto\AbstractDto;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for every batch before it is sent, by `bi:export` as well as by `bi:sync`, the DocumentSynchronizer and
 * the messenger handlers. Listeners can enrich, replace or remove documents with setDocuments().
 *
 * Removed documents are not sent: a full import then deletes them from the collection, a sync keeps the old version.
 */
class DocumentsEvent extends Event
{
    public const CONTEXT_EXPORT = 'export';

    public const CONTEXT_SYNC = 'sync';

    /**
     * @param list<AbstractDto> $documents
     */
    public function __construct(
        private array $documents,
        public readonly string $collection,
        public readonly string $context,
        public readonly ?DataProviderInterface $provider = null,
    ) {
    }

    /**
     * @return list<AbstractDto>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    /**
     * @param array<AbstractDto> $documents
     */
    public function setDocuments(array $documents): void
    {
        $this->documents = array_values($documents);
    }
}
