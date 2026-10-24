<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Http;

use Batteryincluded\BatteryincludedBundle\Http\Attribute\MapSearchStruct;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Fills BrowseSearchStruct and SuggestSearchStruct controller arguments from the query string:
 * `q`, `f` (filters), `sort`, `page` and `per_page`, the locale is the one of the request.
 */
class SearchStructValueResolver implements ValueResolverInterface
{
    /**
     * Scope of prices and stock (country or sales channel), set by the AvailabilityListener from the
     * `batteryincluded.availability` configuration or by your own listener.
     */
    public const AVAILABILITY_ATTRIBUTE = '_batteryincluded_availability';

    public function __construct(
        private readonly RequestFilterParser $filterParser,
        private readonly ?RequestAvailabilityResolver $availabilityResolver = null,
        private readonly int $defaultPerPage = 24,
        private readonly int $maxPerPage = 100,
    ) {
    }

    /**
     * @return iterable<BrowseSearchStruct|SuggestSearchStruct>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();
        if ($type !== BrowseSearchStruct::class && $type !== SuggestSearchStruct::class) {
            return [];
        }

        /** @var MapSearchStruct $options */
        $options = $argument->getAttributesOfType(MapSearchStruct::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? new MapSearchStruct();
        $locale = $options->locale ?? $request->getLocale();
        $availability = $options->availability ?? $this->availabilityResolver?->resolve($request);

        if ($type === SuggestSearchStruct::class) {
            $searchStruct = new SuggestSearchStruct();
            $searchStruct->setQuery(mb_trim((string) $request->query->get('q', '')));
            $searchStruct->addFilters($this->filterParser->parse($request));
            $searchStruct->setLocale($locale);
            $searchStruct->setAvailability($availability);

            return [$searchStruct];
        }

        $maxPerPage = max(1, $options->maxPerPage ?? $this->maxPerPage);
        $defaultPerPage = $options->perPage ?? $this->defaultPerPage;
        $perPage = $this->getInt($request, 'per_page') ?? $defaultPerPage;
        $sort = (string) $request->query->get('sort', '');

        $searchStruct = new BrowseSearchStruct();
        $searchStruct->setQuery(mb_trim((string) $request->query->get('q', '')));
        $searchStruct->addFilters($this->filterParser->parse($request));
        $searchStruct->setSort($options->sortings === [] || in_array($sort, $options->sortings, true) ? $sort : '');
        $searchStruct->setPage(max(1, $this->getInt($request, 'page') ?? 1));
        $searchStruct->setPerPage(min($maxPerPage, $perPage >= 1 ? $perPage : $defaultPerPage));
        $searchStruct->setLocale($locale);
        $searchStruct->setAvailability($availability);
        if ($options->presetId !== null) {
            $searchStruct->setPresetId($options->presetId);
        }

        return [$searchStruct];
    }

    /**
     * Invalid values are ignored instead of answering with 400 like InputBag::getInt().
     */
    private function getInt(Request $request, string $key): ?int
    {
        $value = $request->query->all()[$key] ?? null;

        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) : null;
    }
}
