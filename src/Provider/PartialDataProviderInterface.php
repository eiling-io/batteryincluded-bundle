<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Provider;

use BatteryIncludedSdk\Dto\AbstractDto;

/**
 * Providers which deliver single documents, used by `bi:sync` and the SyncDocumentsMessage.
 */
interface PartialDataProviderInterface extends DataProviderInterface
{
    /**
     * @param list<string> $ids document ids, e.g. `PRODUCT-5`; ids of other providers have to be ignored
     *
     * @return iterable<AbstractDto> the existing documents, unknown ids are skipped
     */
    public function getByIds(array $ids): iterable;
}
