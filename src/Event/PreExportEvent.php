<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Event;

use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by `bi:export` before the first document is loaded, call cancel() to stop the export.
 */
class PreExportEvent extends Event
{
    private ?string $cancelReason = null;

    /**
     * @param list<DataProviderInterface> $providers
     */
    public function __construct(
        public readonly string $collection,
        public readonly array $providers,
        public readonly bool $fullImport,
        public readonly bool $dryRun,
    ) {
    }

    public function cancel(string $reason): void
    {
        $this->cancelReason = $reason;
        $this->stopPropagation();
    }

    public function isCancelled(): bool
    {
        return $this->cancelReason !== null;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }
}
