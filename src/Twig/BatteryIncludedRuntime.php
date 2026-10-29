<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Twig;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlGenerator;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use BatteryIncludedSdk\Highlights\HighlightsService;
use BatteryIncludedSdk\Recommendations\RecommendationsService;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Calls the api for the Twig functions. Failed calls are logged and return an empty result, a broken search must not
 * break the page, with kernel.debug they are thrown.
 *
 * Locale and availability default to the current request (locale and `_batteryincluded_availability`).
 */
class BatteryIncludedRuntime implements RuntimeExtensionInterface
{
    private const SEARCH_OPTIONS = ['per_page', 'page', 'filters', 'type', 'sort', 'preset', 'locale', 'availability', 'collection'];

    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly DocumentUrlGenerator $urlGenerator,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * Documents of a search, e.g. `bi_search('', {type: 'BLOG', sort: '_BLOG.date:desc', per_page: 3})`.
     *
     * Options: per_page (4), page, filters (`{'_PRODUCT.brand': ['Acme']}`), type, sort, preset, locale, availability,
     * collection.
     *
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>> documents with translations and availability merged
     */
    public function search(string $query = '', array $options = []): array
    {
        $this->assertOptions($options, self::SEARCH_OPTIONS);

        $searchStruct = new BrowseSearchStruct();
        $searchStruct->setQuery($query);
        $searchStruct->setPerPage((int) ($options['per_page'] ?? 4));
        $searchStruct->setPage((int) ($options['page'] ?? 1));
        // a single value is allowed as well, e.g. `{'_PRODUCT.brand': 'Acme'}`
        $searchStruct->addFilters(array_map(static fn (mixed $values) => array_values((array) $values), (array) ($options['filters'] ?? [])));
        if (isset($options['type'])) {
            $searchStruct->addFilter('type', mb_strtoupper((string) $options['type']));
        }
        $searchStruct->setSort((string) ($options['sort'] ?? ''));
        $searchStruct->setPresetId((string) ($options['preset'] ?? ''));
        $searchStruct->setLocale($this->locale($options));
        $searchStruct->setAvailability($this->availability($options));

        return $this->call('search', function () use ($searchStruct, $options) {
            $response = $this->collections->get($this->collection($options), BrowseService::class)->browse($searchStruct);

            return array_values(array_map(static fn (array $hit) => (array) ($hit['document'] ?? []), $response->getHits()));
        }, []);
    }

    /**
     * Recommendations of a document, e.g. `bi_recommendations('PRODUCT-1', 'together')`.
     *
     * @param string      $id   document id (`PRODUCT-1`) or the identifier of a product (`1`)
     * @param string|null $kind related, also or together, null returns all three
     * @param array{collection?: string, limit?: int, locale?: string, availability?: string} $options
     *
     * @return array<mixed> documents with translations and availability merged
     */
    public function recommendations(string $id, ?string $kind = 'related', array $options = []): array
    {
        $this->assertOptions($options, ['collection', 'limit', 'locale', 'availability']);
        [$type, $identifier] = preg_match('/^([A-Z0-9_]+)-(.+)$/', $id, $matches) === 1 ? [$matches[1], $matches[2]] : ['PRODUCT', $id];

        return $this->call('recommendations', function () use ($type, $identifier, $kind, $options) {
            $recommendations = $this->collections->get($this->collection($options), RecommendationsService::class)
                ->recommendByIdentifier($identifier, $type, $this->locale($options), $this->availability($options))
                ->getRecommendations();
            $recommendations = array_merge(['related' => [], 'also' => [], 'together' => []], $recommendations);
            $limit = isset($options['limit']) ? (int) $options['limit'] : null;
            $recommendations = array_map(static fn (array $list) => array_slice($list, 0, $limit), $recommendations);

            return $kind === null ? $recommendations : ($recommendations[$kind] ?? []);
        }, []);
    }

    /**
     * Highlights maintained in the backend: `searches` and `querySuggestions`, translated into the locale.
     *
     * @param array{collection?: string, locale?: string} $options
     *
     * @return array{searches: array<mixed>, querySuggestions: array<mixed>}
     */
    public function highlights(array $options = []): array
    {
        $this->assertOptions($options, ['collection', 'locale']);

        return $this->call('highlights', fn () => $this->collections->get($this->collection($options), HighlightsService::class)->getHighlights($this->locale($options))->getAll(), ['searches' => [], 'querySuggestions' => []]);
    }

    /**
     * Url of a document of bi_search() or a browse hit, see DocumentUrlResolverInterface.
     *
     * @param array<string, mixed> $document
     */
    public function documentUrl(array $document): ?string
    {
        return $this->urlGenerator->generateForDocument($document);
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     * @param T             $fallback
     *
     * @return T
     */
    private function call(string $function, callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (\Throwable $exception) {
            if ($this->debug || $exception instanceof \InvalidArgumentException) {
                throw $exception;
            }
            $this->logger?->error('BatteryIncluded bi_{function}() failed: {message}', ['function' => $function, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return $fallback;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $allowed
     */
    private function assertOptions(array $options, array $allowed): void
    {
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown options "%s", allowed are: %s.', implode('", "', $unknown), implode(', ', $allowed)));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collection(array $options): string
    {
        return (string) ($options['collection'] ?? BatteryincludedBundle::DEFAULT_COLLECTION);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function locale(array $options): string
    {
        return (string) ($options['locale'] ?? $this->requestStack?->getCurrentRequest()?->getLocale() ?? 'de');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function availability(array $options): ?string
    {
        $availability = $options['availability'] ?? $this->requestStack?->getCurrentRequest()?->attributes->get(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE);

        return is_scalar($availability) ? (string) $availability : null;
    }
}
