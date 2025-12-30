<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Provider;

use BatteryIncludedSdk\Dto\AbstractDto;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface DataProviderInterface
{
    /**
     * @return \Generator<array-key, array<AbstractDto>>
     */
    public function getBatches(int $batchSize): \Generator;
}
