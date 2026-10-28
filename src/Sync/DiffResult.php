<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

/**
 * Document ids of the data providers compared with the ids in the collection.
 */
class DiffResult
{
    /**
     * @param array<string, int> $providerCounts documents of the providers per type
     * @param array<string, int> $indexCounts    documents of the collection per type
     * @param list<string>       $missing        delivered by a provider but not in the collection
     * @param list<string>       $orphaned       in the collection but delivered by no provider
     * @param int                $indexFound     documents of the collection according to the api
     * @param int                $indexRead      documents read from the collection, less than found if paging stopped
     */
    public function __construct(
        public readonly array $providerCounts,
        public readonly array $indexCounts,
        public readonly array $missing,
        public readonly array $orphaned,
        public readonly int $indexFound,
        public readonly int $indexRead,
    ) {
    }

    public function isConsistent(): bool
    {
        return $this->missing === [] && $this->orphaned === [] && $this->isComplete();
    }

    /**
     * False if the api delivered fewer ids than it found, the orphaned ids are incomplete then.
     */
    public function isComplete(): bool
    {
        return $this->indexRead >= $this->indexFound;
    }

    /**
     * @return list<string>
     */
    public function getTypes(): array
    {
        $types = array_keys($this->providerCounts + $this->indexCounts);
        sort($types);

        return $types;
    }

    /**
     * @return array{consistent: bool, complete: bool, index_found: int, index_read: int, types: array<string, array{providers: int, index: int, missing: int, orphaned: int}>, missing: list<string>, orphaned: list<string>}
     */
    public function toArray(): array
    {
        $types = [];
        foreach ($this->getTypes() as $type) {
            $types[$type] = [
                'providers' => $this->providerCounts[$type] ?? 0,
                'index' => $this->indexCounts[$type] ?? 0,
                'missing' => count(array_filter($this->missing, static fn (string $id) => str_starts_with($id, $type . '-'))),
                'orphaned' => count(array_filter($this->orphaned, static fn (string $id) => str_starts_with($id, $type . '-'))),
            ];
        }

        return [
            'consistent' => $this->isConsistent(),
            'complete' => $this->isComplete(),
            'index_found' => $this->indexFound,
            'index_read' => $this->indexRead,
            'types' => $types,
            'missing' => $this->missing,
            'orphaned' => $this->orphaned,
        ];
    }
}
