<?php

namespace App\Provider;

use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;
use BatteryIncludedSdkTests\Helper;
use Generator;

/**
 * Test data of the sdk (tests/Helper.php): products with svg images, german and english translations and prices per
 * country. The demo shop of the sdk expects exactly this data.
 */
class ProductProvider implements PartialDataProviderInterface
{
    // the test data is generated, a shop would load only the requested ids from its database
    public function getByIds(array $ids): iterable
    {
        return array_filter(Helper::generateProducts(20), static fn ($document) => in_array($document->getIdentifier(), $ids, true));
    }

    public function getBatches(int $batchSize): Generator
    {
        yield from array_chunk(Helper::generateProducts(20), $batchSize);
    }
}
