<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Provider;

/**
 * Restricts a provider to some of the configured collections, providers without it deliver to every collection.
 */
interface CollectionAwareDataProviderInterface extends DataProviderInterface
{
    /**
     * @param string $collection name of the collection in the bundle configuration, e.g. `default`
     */
    public function supportsCollection(string $collection): bool;
}
