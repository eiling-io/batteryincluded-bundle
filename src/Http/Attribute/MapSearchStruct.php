<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Http\Attribute;

/**
 * Optional settings of a BrowseSearchStruct or SuggestSearchStruct controller argument.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class MapSearchStruct
{
    /**
     * @param int|null     $perPage      hits per page without `per_page` parameter, defaults to `batteryincluded.search.per_page`
     * @param int|null     $maxPerPage   upper limit of `per_page`, defaults to `batteryincluded.search.max_per_page`
     * @param list<string> $sortings     allowed values of `sort`, empty allows every value
     * @param string|null  $locale       fixed locale, defaults to the locale of the request
     * @param string|null  $availability scope of prices and stock, defaults to the request attribute `_batteryincluded_availability`
     * @param string|null  $presetId     preset of the search
     */
    public function __construct(
        public readonly ?int $perPage = null,
        public readonly ?int $maxPerPage = null,
        public readonly array $sortings = [],
        public readonly ?string $locale = null,
        public readonly ?string $availability = null,
        public readonly ?string $presetId = null,
    ) {
    }
}
