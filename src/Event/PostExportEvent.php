<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Event;

use Batteryincluded\BatteryincludedBundle\Sync\SyncResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by `bi:export` after the export finished or failed, e.g. to clear caches or to send a notification.
 */
class PostExportEvent extends Event
{
    public function __construct(
        public readonly string $collection,
        public readonly bool $fullImport,
        public readonly bool $dryRun,
        public readonly bool $successful,
        public readonly int $documents,
        public readonly float $duration,
        public readonly SyncResult $result,
        public readonly ?string $error = null,
    ) {
    }
}
