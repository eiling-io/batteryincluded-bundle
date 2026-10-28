<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Document;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Url of a document in the shop, used by the suggest endpoint and the `bi_document_url()` Twig function.
 *
 * Every service implementing it is asked in the order of its priority, the first url wins. Without a resolver the
 * `url` field of the document is used (e.g. content pages).
 */
#[AutoconfigureTag(self::TAG)]
interface DocumentUrlResolverInterface
{
    public const TAG = 'batteryincluded.document_url_resolver';

    /**
     * @param string               $id   document id, e.g. `PRODUCT-1`
     * @param string               $type e.g. `PRODUCT`
     * @param array<string, mixed> $data payload of the document (`_<TYPE>`) with translations and availability
     *
     * @return string|null null if the resolver does not know the document
     */
    public function resolve(string $id, string $type, array $data): ?string;
}
