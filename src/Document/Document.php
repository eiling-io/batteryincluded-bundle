<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Document;

use BatteryIncludedSdk\Availability\AvailabilityResolver;
use BatteryIncludedSdk\I18n\I18nResolver;

/**
 * Helpers for documents as delivered by the api: browse hits, suggestions and recommendations.
 */
final class Document
{
    /**
     * Type of a document: the `type` field, the prefix of the id (`CONTENT-123`) or its root node (`_CONTENT_i18n`).
     * Suggested documents contain only the fields selected under Suggest in the portal, the root node is always there.
     */
    public static function type(array $document): string
    {
        $type = mb_strtoupper(strip_tags((string) ($document['type'] ?? '')));
        if ($type !== '') {
            return $type;
        }
        if (preg_match('/^([A-Z0-9_]+)-/', strip_tags((string) ($document['id'] ?? '')), $matches) === 1) {
            return $matches[1];
        }
        foreach (array_keys($document) as $key) {
            if (preg_match('/^_([A-Z0-9]+?)(' . I18nResolver::SUFFIX . '|' . AvailabilityResolver::SUFFIX . ')?$/', (string) $key, $matches) === 1) {
                return $matches[1];
            }
        }

        return 'PRODUCT';
    }

    /**
     * Document id, e.g. `PRODUCT-1`: the id in the document root. Collections exported with an sdk before the root id
     * deliver the shop id `_<TYPE>.id` only, the type is prefixed then. Empty if neither is selected under Suggest.
     */
    public static function id(array $document, ?string $type = null): string
    {
        $id = strip_tags((string) ($document['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        $type ??= self::type($document);
        $shopId = strip_tags((string) ($document['_' . $type]['id'] ?? ''));

        return $shopId !== '' ? $type . '-' . $shopId : '';
    }

    /**
     * Payload of the document (`_<TYPE>`), with the translations and the availability merged if they are still
     * separate nodes (suggestions, raw hits).
     *
     * @return array<string, mixed>
     */
    public static function data(array $document, ?string $availability = null, ?string $type = null): array
    {
        $type ??= self::type($document);
        if ($availability !== null && isset($document[AvailabilityResolver::getKey($type)])) {
            $document = AvailabilityResolver::resolveDocument($document, $availability);
        }

        $data = (array) ($document['_' . $type] ?? []);
        // suggestions deliver the translations of the requested locale directly in `_<TYPE>_i18n`, raw hits per locale
        $translations = $document[I18nResolver::getKey($type)] ?? null;
        $isPerLocale = static fn (array $values) => array_filter(
            array_keys($values),
            static fn ($key) => preg_match('/^[a-z]{2}(-[a-z0-9]+)*$/', (string) $key) !== 1 || !is_array($values[$key]),
        ) === [];
        if (is_array($translations) && $translations !== [] && !$isPerLocale($translations)) {
            $data = array_merge($data, $translations);
        }

        return $data;
    }

    /**
     * Escapes a highlighted api value but keeps the <mark> tags.
     */
    public static function highlight(string $value): string
    {
        return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
