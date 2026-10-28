<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Scheduler;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;

/**
 * Runs `bi:export --force`: a full import, with providers an export of these providers only.
 */
final class ExportMessage
{
    /**
     * @param list<string> $providers class or short class names, empty for a full import
     */
    public function __construct(
        public readonly string $collection = BatteryincludedBundle::DEFAULT_COLLECTION,
        public readonly array $providers = [],
    ) {
    }
}
