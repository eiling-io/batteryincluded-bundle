<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures\Entity;

use Batteryincluded\BatteryincludedBundle\Doctrine\Attribute\BatteryIncludedDocument;
use Doctrine\ORM\Mapping as ORM;

/**
 * Updates its own document and the one of its product, removing it only updates the product.
 */
#[ORM\Entity]
#[BatteryIncludedDocument('VARIANT', identifier: 'sku', collection: 'ch')]
#[BatteryIncludedDocument('PRODUCT', identifier: 'getProductId', deleteOnRemove: false)]
class Variant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private string $sku,
        #[ORM\ManyToOne]
        public ?Product $product = null,
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getProductId(): ?int
    {
        return $this->product?->id;
    }
}
