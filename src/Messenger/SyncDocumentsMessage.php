<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Messenger;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;

/**
 * Creates or replaces documents, dispatch it e.g. after a product was saved. Route it to an async transport so the
 * request does not wait for the api.
 */
class SyncDocumentsMessage
{
    /**
     * @param list<string> $ids           document ids, e.g. `PRODUCT-5`
     * @param bool         $deleteMissing ids no provider delivers a document for are deleted
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $collection = BatteryincludedBundle::DEFAULT_COLLECTION,
        public readonly bool $deleteMissing = false,
    ) {
    }
}
