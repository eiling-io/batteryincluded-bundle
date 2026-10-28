<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Demo;

use Batteryincluded\BatteryincludedBundle\Document\Document;
use Batteryincluded\BatteryincludedBundle\Http\RequestFilterParser;
use BatteryIncludedSdk\I18n\I18nResolver;
use BatteryIncludedSdk\Shop\FacetCategoryDto;
use BatteryIncludedSdk\Shop\FacetDto;
use BatteryIncludedSdk\Shop\FacetRangeDto;
use BatteryIncludedSdk\Shop\FacetRatingDto;
use BatteryIncludedSdk\Shop\FacetSelectDto;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * State and view helpers of the demo shop, a port of the demo in the `examples` directory of the sdk.
 *
 * The controller initializes it per request, the templates access it as `demo`.
 */
class DemoShop implements ResetInterface
{
    /**
     * Languages of the demo shop.
     *
     * - locale: sent to the api as `v[locale]`, the gateway resolves `_PRODUCT_i18n.<locale>` and the sdk merges it into the hits
     */
    public const LANGUAGES = [
        'de' => ['label' => 'Deutsch', 'flag' => '🇩🇪', 'locale' => 'de', 'intl' => 'de_DE'],
        'en' => ['label' => 'English', 'flag' => '🇬🇧', 'locale' => 'en', 'intl' => 'en_GB'],
    ];
    public const DEFAULT_LANGUAGE = 'de';
    public const LANGUAGE_COOKIE = 'bi_demo_lang';

    /**
     * Countries of the demo shop, independent of the language.
     *
     * - availability: sent to the api as `v[availability]`, the sdk merges `_PRODUCT_availability.<scope>` (active, price,
     *   instock) into the hits
     */
    public const COUNTRIES = [
        'de' => ['flag' => '🇩🇪', 'availability' => 'de', 'currency' => 'EUR'],
        'at' => ['flag' => '🇦🇹', 'availability' => 'at', 'currency' => 'EUR'],
        'ch' => ['flag' => '🇨🇭', 'availability' => 'ch', 'currency' => 'CHF'],
    ];
    public const DEFAULT_COUNTRY = 'de';
    public const COUNTRY_COOKIE = 'bi_demo_country';

    public const SORTINGS = [
        '' => 'sort_relevance',
        '_PRODUCT.price:asc' => 'sort_price_asc',
        '_PRODUCT.price:desc' => 'sort_price_desc',
        '_PRODUCT.rating:desc' => 'sort_rating_desc',
    ];
    public const PER_PAGE = [12, 24, 48];

    public const ROUTE_INDEX = 'batteryincluded_demo_index';
    public const ROUTE_DETAIL = 'batteryincluded_demo_detail';
    public const ROUTE_SUGGEST = 'batteryincluded_demo_suggest';

    private string $lang = self::DEFAULT_LANGUAGE;

    private string $country = self::DEFAULT_COUNTRY;

    /**
     * State of the page, used by url() to build links.
     */
    private array $state = [];

    /**
     * @var Cookie[]
     */
    private array $cookies = [];

    private ?array $translations = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestFilterParser $filterParser,
    ) {
    }

    /**
     * Resolves language and country of the request, both are remembered in a cookie as soon as they are selected.
     */
    public function initialize(Request $request): void
    {
        $this->reset();
        $this->lang = $this->resolveLanguage($request);
        $this->country = $this->resolveCountry($request);
        $this->state = ['lang' => $this->lang, 'country' => $this->country];
    }

    public function setState(array $state): void
    {
        $this->state = array_merge(['lang' => $this->lang, 'country' => $this->country], $state);
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getLocale(): string
    {
        return self::LANGUAGES[$this->lang]['locale'];
    }

    public function getAvailability(): string
    {
        return self::COUNTRIES[$this->country]['availability'];
    }

    public function getLanguages(): array
    {
        return self::LANGUAGES;
    }

    public function getCountries(): array
    {
        return self::COUNTRIES;
    }

    public function getSortings(): array
    {
        return self::SORTINGS;
    }

    public function getPerPageOptions(): array
    {
        return self::PER_PAGE;
    }

    /**
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public function applyCookies(Response $response): Response
    {
        foreach ($this->cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function reset(): void
    {
        $this->lang = self::DEFAULT_LANGUAGE;
        $this->country = self::DEFAULT_COUNTRY;
        $this->state = [];
        $this->cookies = [];
    }

    /**
     * Language from the query, the cookie or the browser, in this order.
     */
    private function resolveLanguage(Request $request): string
    {
        $requested = mb_strtolower((string) $request->query->get('lang', ''));
        if (isset(self::LANGUAGES[$requested])) {
            if ($request->cookies->get(self::LANGUAGE_COOKIE) !== $requested) {
                $this->cookies[] = Cookie::create(self::LANGUAGE_COOKIE, $requested, time() + 31536000, sameSite: Cookie::SAMESITE_LAX);
            }

            return $requested;
        }

        $cookie = (string) $request->cookies->get(self::LANGUAGE_COOKIE, '');
        if (isset(self::LANGUAGES[$cookie])) {
            return $cookie;
        }

        foreach ($request->getLanguages() as $accepted) {
            $language = mb_strtolower(mb_substr($accepted, 0, 2));
            if (isset(self::LANGUAGES[$language])) {
                return $language;
            }
        }

        return self::DEFAULT_LANGUAGE;
    }

    /**
     * Country from the query or the cookie, in this order.
     */
    private function resolveCountry(Request $request): string
    {
        $requested = mb_strtolower((string) $request->query->get('country', ''));
        if (isset(self::COUNTRIES[$requested])) {
            if ($request->cookies->get(self::COUNTRY_COOKIE) !== $requested) {
                $this->cookies[] = Cookie::create(self::COUNTRY_COOKIE, $requested, time() + 31536000, sameSite: Cookie::SAMESITE_LAX);
            }

            return $requested;
        }

        $cookie = (string) $request->cookies->get(self::COUNTRY_COOKIE, '');

        return isset(self::COUNTRIES[$cookie]) ? $cookie : self::DEFAULT_COUNTRY;
    }

    /**
     * Filters of the request, empty values and incomplete ranges are removed.
     */
    public function requestFilters(Request $request): array
    {
        return $this->filterParser->parse($request);
    }

    public function translation(string $key): mixed
    {
        $this->translations ??= require __DIR__ . '/../../config/demo/translations.php';

        return $this->translations[$this->lang][$key] ?? $this->translations[self::DEFAULT_LANGUAGE][$key] ?? $key;
    }

    public function t(string $key, mixed ...$args): string
    {
        $text = (string) $this->translation($key);

        return $args === [] ? $text : vsprintf($text, $args);
    }

    public function countryLabel(string $country): string
    {
        return $this->translation('countries')[$country] ?? mb_strtoupper($country);
    }

    /**
     * Stock status of a product, a product which is inactive in the country is not sold there.
     *
     * @return array{class: string, icon: string, label: string}
     */
    public function stockStatus(array $data): array
    {
        if (($data['active'] ?? true) === false) {
            return ['class' => 'text-body-secondary', 'icon' => 'bi-slash-circle', 'label' => $this->t('not_sold_in', $this->countryLabel($this->country))];
        }

        $instock = (int) ($data['instock'] ?? 0);

        return $instock > 0
            ? ['class' => 'text-success', 'icon' => 'bi-check-circle', 'label' => $this->t('in_stock', $instock)]
            : ['class' => 'text-danger', 'icon' => 'bi-x-circle', 'label' => $this->t('out_of_stock')];
    }

    /**
     * Url of the search page with the given parameters replaced, null removes a parameter.
     */
    public function url(array $overrides = []): string
    {
        $params = array_merge($this->state, $overrides);
        $params = array_filter($params, static fn ($value) => $value !== null && $value !== '' && $value !== []);
        if (($params['page'] ?? 1) === 1) {
            unset($params['page']);
        }

        return $this->urlGenerator->generate(self::ROUTE_INDEX, $params);
    }

    public function urlWithoutFilter(string $field, ?string $value = null): string
    {
        $filters = $this->state['f'] ?? [];
        if ($value === null) {
            unset($filters[$field]);
        } else {
            $filters[$field] = array_values(array_diff($filters[$field] ?? [], [$value]));
        }

        return $this->url(['f' => $filters, 'page' => null]);
    }

    /**
     * Url of the detail page of a document, e.g. `PRODUCT-5`.
     */
    public function detailUrl(string $id, ?string $language = null, ?string $country = null): string
    {
        return $this->urlGenerator->generate(self::ROUTE_DETAIL, [
            'id' => $id,
            'lang' => $language ?? $this->lang,
            'country' => $country ?? $this->country,
        ]);
    }

    /**
     * Url of the search page without any parameter, e.g. the target of the search form.
     */
    public function indexUrl(): string
    {
        return $this->urlGenerator->generate(self::ROUTE_INDEX);
    }

    public function suggestUrl(): string
    {
        return $this->urlGenerator->generate(self::ROUTE_SUGGEST);
    }

    /**
     * Escapes an api highlight but keeps the <mark> tags.
     */
    public function highlight(string $value): string
    {
        return Document::highlight($value);
    }

    /**
     * Nested values as flat list with dotted keys, e.g. `properties.0.Color` => `Blue`.
     *
     * @return array<string, mixed>
     */
    public function flattenData(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            // lists of scalars (e.g. categories) are kept as one value
            $isNested = is_array($value) && $value !== [] && (!array_is_list($value) || array_filter($value, 'is_array') !== []);
            if ($isNested) {
                $flat += $this->flattenData($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    public function formatPrice(float $price): string
    {
        // the prices of `_PRODUCT_availability` are in the currency of the country
        $currency = self::COUNTRIES[$this->country]['currency'];
        if (class_exists(\NumberFormatter::class)) {
            return (string) (new \NumberFormatter(self::LANGUAGES[$this->lang]['intl'], \NumberFormatter::CURRENCY))->formatCurrency($price, $currency);
        }

        return number_format($price, 2, ',', '.') . ' ' . $currency;
    }

    public function formatNumber(float $number): string
    {
        if (class_exists(\NumberFormatter::class)) {
            return (string) (new \NumberFormatter(self::LANGUAGES[$this->lang]['intl'], \NumberFormatter::DECIMAL))->format($number);
        }

        return (string) $number;
    }

    public function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        if (class_exists(\IntlDateFormatter::class)) {
            return (string) (new \IntlDateFormatter(self::LANGUAGES[$this->lang]['intl'], \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE))->format($timestamp);
        }

        return date('d.m.Y', $timestamp);
    }

    public function stars(float $rating): string
    {
        $html = '<span class="stars" role="img" aria-label="' . $this->escape($this->t('stars', $this->formatNumber($rating))) . '">';
        for ($i = 1; $i <= 5; ++$i) {
            $icon = $rating >= $i ? 'bi-star-fill' : ($rating >= $i - 0.5 ? 'bi-star-half' : 'bi-star');
            $html .= '<i class="bi ' . $icon . '" aria-hidden="true"></i>';
        }

        return $html . '</span>';
    }

    /**
     * Label of a facet value, e.g. `BLOG` => `Blogbeiträge` for the type facet.
     */
    public function facetValueLabel(string $field, string $value): string
    {
        return $this->translation('facet_values')[$field][mb_strtoupper($value)] ?? $value;
    }

    /**
     * Translated categories of the language, the untranslated categories as long as the api delivers none.
     */
    public function categoryField(array $facetCounts): string
    {
        $translatedField = I18nResolver::getKey('PRODUCT') . '.categories';

        return in_array($translatedField, array_column($facetCounts, 'field_name'), true) ? $translatedField : '_PRODUCT.categories';
    }

    /**
     * Facets which are shown in the sidebar.
     *
     * The gateway resolves the translated facets for the requested locale (`_PRODUCT_i18n.*`),
     * facets which are still scoped by another locale (`_PRODUCT_i18n.en.*`) are hidden.
     *
     * @param FacetDto[] $facets
     *
     * @return array<string, array{id: string, facet: FacetDto, label: string, kind: string, values: list<mixed>}>
     */
    public function visibleFacets(array $facets, string $categoryField): array
    {
        $visible = [];
        foreach ($facets as $facet) {
            $field = $facet->getFieldName();
            // the type facet stays visible, e.g. to hide blog posts
            $isTechnicalFacet = str_starts_with($field, '_availability.');
            $isOtherCategoryFacet = str_ends_with($field, 'categories') && $field !== $categoryField;
            $isFacetOfOtherLanguage = preg_match('/^_[A-Z]+' . I18nResolver::SUFFIX . '\.([a-z]{2}(-[a-z0-9]+)*)\./', $field, $matches) === 1
                && $matches[1] !== $this->getLocale();
            if ($isTechnicalFacet || $isOtherCategoryFacet || $isFacetOfOtherLanguage) {
                continue;
            }

            // labels are translated in the backend, the field name is used as long as a label is missing
            $label = $this->translation('facet_labels')[$field] ?? ($facet->getFieldLabel() !== $field ? $facet->getFieldLabel() : null);
            $label ??= $field === $categoryField ? $this->t('categories') : mb_substr($field, (int) mb_strrpos($field, '.') + 1);

            // kind and values of the facet for the template, e.g. ratings with the best rating first
            $kind = match (true) {
                $facet instanceof FacetCategoryDto => 'category',
                $facet instanceof FacetRangeDto => 'range',
                $facet instanceof FacetRatingDto => 'rating',
                $facet instanceof FacetSelectDto => 'select',
                default => 'unknown',
            };
            $values = match (true) {
                $facet instanceof FacetRatingDto => $facet->getRatings(),
                $facet instanceof FacetSelectDto => $facet->getValues(),
                default => [],
            };
            if ($facet instanceof FacetRatingDto) {
                usort($values, static fn ($a, $b) => (float) $b->getName() <=> (float) $a->getName());
            }

            $visible[$field] = ['id' => 'facet-' . md5($field), 'facet' => $facet, 'label' => $label, 'kind' => $kind, 'values' => array_values($values)];
        }

        // categories first, everything else in api order
        uksort($visible, static fn ($a, $b) => ($b === $categoryField) <=> ($a === $categoryField));

        return $visible;
    }

    /**
     * Category tree as navigation, every node with subcategories can be expanded, the selected path is expanded initially.
     */
    public function categoryTree(array $nodes, string $field, string $parentPath = '', bool $expanded = true): string
    {
        // natural order, the api delivers e.g. "Laptop 9 Pro" before "Laptop 10 Pro" only by chance
        uksort($nodes, static fn ($a, $b) => strnatcasecmp((string) $a, (string) $b));

        $id = 'category-' . md5($field . "\0" . $parentPath);
        $html = '<ul class="category-tree list-unstyled mb-0' . ($parentPath === '' ? '' : ' collapse' . ($expanded ? ' show' : '')) . '" id="' . $this->escape($id) . '">';
        foreach ($nodes as $name => $node) {
            $path = $parentPath === '' ? (string) $name : $parentPath . ' > ' . $name;
            $checked = $node['checked'] ?? false;
            $hasChildren = !empty($node['childs']);
            // a single root (e.g. the shop name) is always expanded, otherwise the tree would show only one entry
            $isExpanded = $checked || ($parentPath === '' && count($nodes) === 1);
            $childId = 'category-' . md5($field . "\0" . $path);
            $href = $this->url(['f' => array_merge($this->state['f'] ?? [], [$field => [$path]]), 'page' => null]);

            $html .= '<li><div class="category-row">';
            if ($hasChildren) {
                $html .= '<button class="category-toggle' . ($isExpanded ? '' : ' collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . $this->escape($childId) . '"'
                    . ' aria-expanded="' . ($isExpanded ? 'true' : 'false') . '" aria-controls="' . $this->escape($childId) . '" aria-label="' . $this->escape($this->t('toggle_category', (string) $name)) . '">'
                    . '<i class="bi bi-chevron-right" aria-hidden="true"></i></button>';
            } else {
                $html .= '<span class="category-toggle-spacer" aria-hidden="true"></span>';
            }
            $html .= '<a class="category-link' . ($checked ? ' active' : '') . '" href="' . $this->escape($href) . '"' . ($checked ? ' aria-current="true"' : '') . '>';
            $html .= '<span>' . $this->escape($name) . '</span>';
            if (isset($node['count'])) {
                $html .= '<span class="facet-count">' . $this->escape($node['count']) . '</span>';
            }
            $html .= '</a></div>';
            if ($hasChildren) {
                $html .= $this->categoryTree($node['childs'], $field, $path, $isExpanded);
            }
            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * @return array{pages: list<int|null>, current: int, last: int}
     */
    public function pagination(int $current, int $last, int $window = 1): array
    {
        $pages = [];
        for ($page = 1; $page <= $last; ++$page) {
            if ($page === 1 || $page === $last || abs($page - $current) <= $window) {
                $pages[] = $page;
            } elseif (end($pages) !== null) {
                $pages[] = null;
            }
        }

        return ['pages' => $pages, 'current' => $current, 'last' => $last];
    }

    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
