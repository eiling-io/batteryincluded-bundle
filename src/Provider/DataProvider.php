<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Provider;

use BatteryIncludedSdk\Dto\AbstractDto;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(name: 'batteryincluded.data_provider')]
interface DataProvider
{
    /**
     * @return Generator<array-key, array<AbstractDto>>
     */
    public function getBatches(int $batchSize): Generator;
}