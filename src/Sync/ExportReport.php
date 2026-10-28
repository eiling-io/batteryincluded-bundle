<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

/**
 * Summary of a `bi:export` run per provider and document type, printed by the command, written with `--report` and
 * passed to the PostExportEvent.
 *
 * @phpstan-type ProviderRow array{class: string, documents: int, batches: int, time: float, types: array<string, int>}
 */
class ExportReport
{
    /**
     * @param list<ProviderRow> $providers
     */
    public function __construct(
        public readonly string $collection,
        public readonly bool $fullImport,
        public readonly bool $dryRun,
        public readonly bool $successful,
        public readonly \DateTimeImmutable $startedAt,
        public readonly float $duration,
        public readonly array $providers,
        public readonly SyncResult $result,
        public readonly ?string $error = null,
    ) {
    }

    public function getDocuments(): int
    {
        return array_sum(array_column($this->providers, 'documents'));
    }

    /**
     * @return array<string, int> documents per type of all providers
     */
    public function getTypes(): array
    {
        $types = [];
        foreach ($this->providers as $provider) {
            foreach ($provider['types'] as $type => $count) {
                $types[$type] = ($types[$type] ?? 0) + $count;
            }
        }
        ksort($types);

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'collection' => $this->collection,
            'full_import' => $this->fullImport,
            'dry_run' => $this->dryRun,
            'successful' => $this->successful,
            'error' => $this->error,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'duration' => round($this->duration, 3),
            'documents' => $this->getDocuments(),
            'types' => $this->getTypes(),
            'sent' => $this->result->getSent(),
            'synced' => $this->result->getSynced(),
            'failed' => $this->result->getFailed(),
            'providers' => array_map(static fn (array $provider) => ['time' => round($provider['time'], 3)] + $provider, $this->providers),
        ];
    }
}
