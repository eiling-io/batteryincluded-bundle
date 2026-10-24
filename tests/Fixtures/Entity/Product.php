<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures\Entity;

use Batteryincluded\BatteryincludedBundle\Doctrine\Attribute\BatteryIncludedDocument;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[BatteryIncludedDocument('product', deleteMissing: true)]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public string $name,
    ) {
    }
}
