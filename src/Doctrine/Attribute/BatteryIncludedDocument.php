<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Doctrine\Attribute;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;

/**
 * Marks a Doctrine entity whose changes update a document, e.g. `#[BatteryIncludedDocument('PRODUCT')]`. The document
 * id is `<TYPE>-<identifier>`, the document itself is loaded from the PartialDataProviderInterface providers.
 *
 * Repeat it to update several documents, e.g. on a variant:
 * `#[BatteryIncludedDocument('PRODUCT', identifier: 'getProductId', deleteOnRemove: false)]`.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class BatteryIncludedDocument
{
    /**
     * @param string      $type           document type, e.g. PRODUCT
     * @param string|null $identifier     method or property delivering the identifier, defaults to the Doctrine id
     * @param bool        $deleteOnRemove removing the entity deletes the document, otherwise it is synced
     * @param bool        $deleteMissing  documents the providers no longer deliver (e.g. deactivated products) are
     *                                    deleted, only enable it if a PartialDataProviderInterface handles the type
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $identifier = null,
        public readonly string $collection = BatteryincludedBundle::DEFAULT_COLLECTION,
        public readonly bool $deleteOnRemove = true,
        public readonly bool $deleteMissing = false,
    ) {
    }
}
