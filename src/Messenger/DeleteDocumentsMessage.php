<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Messenger;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;

/**
 * Deletes documents, dispatch it e.g. after a product was removed.
 */
class DeleteDocumentsMessage
{
    /**
     * @param list<string> $ids document ids, e.g. `PRODUCT-5`
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $collection = BatteryincludedBundle::DEFAULT_COLLECTION,
    ) {
    }
}
