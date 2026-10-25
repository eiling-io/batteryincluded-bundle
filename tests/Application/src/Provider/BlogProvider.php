<?php

namespace App\Provider;

use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;
use BatteryIncludedSdkTests\Helper;
use Generator;

/**
 * Blog posts of the sdk test data (tests/Helper.php) with svg cover images.
 */
class BlogProvider implements PartialDataProviderInterface
{
    // the test data is generated, a shop would load only the requested ids from its database
    public function getByIds(array $ids): iterable
    {
        return array_filter(Helper::generateBlogs(10), static fn ($document) => in_array($document->getIdentifier(), $ids, true));
    }

    public function getBatches(int $batchSize): Generator
    {
        yield from array_chunk(Helper::generateBlogs(10), $batchSize);
    }
}
