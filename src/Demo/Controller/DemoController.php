<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Demo\Controller;

use Batteryincluded\BatteryincludedBundle\Demo\DemoShop;
use BatteryIncludedSdk\Availability\AvailabilityResolver;
use BatteryIncludedSdk\I18n\I18nResolver;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Demo shop of the sdk (`examples` directory) as symfony controller, import `@BatteryincludedBundle/config/routes/demo.yaml`.
 */
class DemoController
{
    public function __construct(
        private readonly DemoShop $demo,
        private readonly BrowseService $browseService,
        private readonly SuggestService $suggestService,
        private readonly Environment $twig,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->demo->initialize($request);
        $query = mb_trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', '');
        $sort = isset(DemoShop::SORTINGS[$sort]) ? $sort : '';
        $perPage = $request->query->getInt('per_page');
        $perPage = in_array($perPage, DemoShop::PER_PAGE, true) ? $perPage : DemoShop::PER_PAGE[0];
        $page = max(1, $request->query->getInt('page', 1));
        $filters = $this->demo->requestFilters($request);

        $this->demo->setState(['q' => $query, 'f' => $filters, 'sort' => $sort, 'per_page' => $perPage === DemoShop::PER_PAGE[0] ? null : $perPage, 'page' => $page]);

        $searchStruct = new BrowseSearchStruct();
        $searchStruct->setQuery($query);
        $searchStruct->addFilters($filters);
        $searchStruct->setSort($sort);
        $searchStruct->setPerPage($perPage);
        $searchStruct->setPage($page);
        $searchStruct->setLocale($this->demo->getLocale());
        // status, price and stock of the country, the sdk merges them into the hits after the translations
        $searchStruct->setAvailability($this->demo->getAvailability());

        $result = null;
        $error = null;
        try {
            $result = $this->browseService->browse($searchStruct);
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $hits = $result?->getHits() ?? [];
        // translated categories of the language, the untranslated categories as long as the api delivers none
        $categoryField = $this->demo->categoryField($result?->getBody()['facet_counts'] ?? []);
        $facets = $result !== null ? $this->demo->visibleFacets($result->getFacets($categoryField), $categoryField) : [];

        // teasers are placed between the products, all other extensions are shown above the result
        $items = array_map(static fn (array $hit) => match ($hit['document']['type'] ?? 'PRODUCT') {
            'BLOG' => ['type' => 'blog', 'id' => (string) ($hit['document']['id'] ?? ''), 'data' => $hit['document']['_BLOG'] ?? []],
            default => ['type' => 'product', 'id' => (string) ($hit['document']['id'] ?? ''), 'data' => $hit['document']['_PRODUCT'] ?? []],
        }, $hits);
        foreach ($result?->getTeaserExtensions() ?? [] as $teaser) {
            $position = max(0, min(count($items), (int) ($teaser->getData()['position'] ?? 1) - 1));
            array_splice($items, $position, 0, [['type' => 'teaser', 'data' => $teaser->getData()]]);
        }

        $html = $this->twig->render('@Batteryincluded/demo/index.html.twig', [
            'demo' => $this->demo,
            'query' => $query,
            'sort' => $sort,
            'perPage' => $perPage,
            'page' => $page,
            'filters' => $filters,
            'searchStruct' => $searchStruct,
            'result' => $result,
            'error' => $error,
            'hits' => $hits,
            'items' => $items,
            'facets' => $facets,
            'facetLabels' => array_map(static fn (array $facet) => $facet['label'], $facets),
            'found' => $result?->getFound() ?? 0,
            'searchTime' => (int) ($result?->getBody()['search_time_ms'] ?? 0),
            'pageTitle' => $query,
            'homeUrl' => $this->demo->url(['q' => null, 'f' => null, 'sort' => null, 'page' => null]),
            // filters are language specific, they are kept only for the current language
            'languageUrls' => array_map(fn (string $code) => $this->demo->url(['lang' => $code, 'f' => $code === $this->demo->getLang() ? $filters : null, 'page' => null]), array_combine(array_keys(DemoShop::LANGUAGES), array_keys(DemoShop::LANGUAGES))),
            'countryUrls' => array_map(fn (string $code) => $this->demo->url(['country' => $code, 'page' => null]), array_combine(array_keys(DemoShop::COUNTRIES), array_keys(DemoShop::COUNTRIES))),
        ]);

        return $this->demo->applyCookies(new Response($html));
    }

    public function detail(Request $request, string $id): Response
    {
        $this->demo->initialize($request);
        $lang = $this->demo->getLang();
        $country = $this->demo->getCountry();
        $id = mb_trim($id);

        // the browse endpoint delivers a single document with a filter on its id
        $searchStruct = new BrowseSearchStruct();
        $searchStruct->addFilter('id', $id);
        $searchStruct->setPerPage(1);
        $searchStruct->setLocale($this->demo->getLocale());
        $searchStruct->setAvailability($this->demo->getAvailability());

        $result = null;
        $error = null;
        try {
            $result = $this->browseService->browse($searchStruct);
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $rawHit = $result?->getRawHits()[0] ?? null;
        $document = $result?->getHits()[0]['document'] ?? null;
        $type = (string) ($document['type'] ?? '');
        $payloadKey = '_' . $type;
        $data = $document[$payloadKey] ?? [];

        // fields delivered by the gateway in `_<TYPE>_i18n` for the requested locale, all others are the default values
        $translations = $rawHit['document'][I18nResolver::getKey($type)] ?? [];
        $translatedFields = is_array($translations) ? array_keys(I18nResolver::resolveDocument([$payloadKey => [], I18nResolver::getKey($type) => $translations], $lang)[$payloadKey]) : [];

        // fields delivered in `_<TYPE>_availability` for the requested country, they overwrite translations and default values
        $availabilities = $rawHit['document'][AvailabilityResolver::getKey($type)] ?? [];
        $availabilityFields = is_array($availabilities) ? array_keys(AvailabilityResolver::resolveDocument([$payloadKey => [], AvailabilityResolver::getKey($type) => $availabilities], $country)[$payloadKey]) : [];

        // categories link to the search, filtered with the translated categories if the document has some
        $categoryField = in_array('categories', $translatedFields, true) ? I18nResolver::getKey('PRODUCT') . '.categories' : '_PRODUCT.categories';
        $properties = array_merge(...array_values(array_filter((array) ($data['properties'] ?? []), 'is_array')) ?: [[]]);

        // labels of the properties are translated in the backend and delivered with the facets
        $facetLabels = [];
        foreach ($result?->getFacets($categoryField) ?? [] as $facet) {
            $facetLabels[$facet->getFieldName()] = $facet->getFieldLabel();
        }
        $propertyLabels = [];
        foreach (array_keys($properties) as $name) {
            $propertyLabels[$name] = $facetLabels[I18nResolver::getKey('PRODUCT') . '.properties.' . $name] ?? $facetLabels['_PRODUCT.properties.' . $name] ?? $name;
        }

        // every field of the document with its source: availability of the country, translation or default value
        $fields = [];
        foreach ($this->demo->flattenData($data) as $field => $value) {
            $root = explode('.', $field)[0];
            $fields[$payloadKey . '.' . $field] = [
                'value' => is_array($value) ? implode(', ', $value) : (is_bool($value) ? var_export($value, true) : $value),
                'source' => in_array($root, $availabilityFields, true) ? 'availability' : (in_array($root, $translatedFields, true) ? 'translation' : 'default'),
            ];
        }

        // back to the search the visitor came from, otherwise to the start page
        $homeUrl = $this->demo->url();
        $referer = (string) $request->headers->get('referer', '');
        $backUrl = parse_url($referer, PHP_URL_HOST) === $request->getHost() && parse_url($referer, PHP_URL_PATH) === parse_url($homeUrl, PHP_URL_PATH)
            ? $referer
            : $homeUrl;

        $html = $this->twig->render('@Batteryincluded/demo/detail.html.twig', [
            'demo' => $this->demo,
            'id' => $id,
            'query' => '',
            'searchStruct' => $searchStruct,
            'result' => $result,
            'error' => $error,
            'rawHit' => $rawHit,
            'document' => $document,
            'type' => $type,
            'data' => $data,
            'fields' => $fields,
            'availabilityFields' => $availabilityFields,
            'categoryField' => $categoryField,
            'properties' => $properties,
            'propertyLabels' => $propertyLabels,
            'backUrl' => $backUrl,
            'pageTitle' => (string) ($data['name'] ?? $data['title'] ?? $id),
            'homeUrl' => $homeUrl,
            'languageUrls' => array_map(fn (string $code) => $this->demo->detailUrl($id, $code), array_combine(array_keys(DemoShop::LANGUAGES), array_keys(DemoShop::LANGUAGES))),
            'countryUrls' => array_map(fn (string $code) => $this->demo->detailUrl($id, country: $code), array_combine(array_keys(DemoShop::COUNTRIES), array_keys(DemoShop::COUNTRIES))),
        ]);

        return $this->demo->applyCookies(new Response($html));
    }

    public function suggest(Request $request): JsonResponse
    {
        $this->demo->initialize($request);
        $country = $this->demo->getCountry();

        $query = mb_trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse(['completions' => [], 'products' => [], 'found' => 0]);
        }

        $searchStruct = new SuggestSearchStruct();
        $searchStruct->setQuery($query);
        $searchStruct->setLocale($this->demo->getLocale());
        // selects the prices of the country and keeps it in the links to the detail page
        $searchStruct->setAvailability($this->demo->getAvailability());

        try {
            $response = $this->suggestService->suggestWithFilter($searchStruct);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $completions = [];
        foreach (array_slice($response->getQueryCompletions(), 0, 5) as $completion) {
            $completions[] = [
                'value' => $completion->getValue(),
                'html' => $this->demo->highlight($completion->getHighlighted() !== '' ? $completion->getHighlighted() : $completion->getValue()),
            ];
        }

        $products = [];
        foreach (array_slice($response->getDocuments(), 0, 4) as $document) {
            // price (and status) of the country, delivered in `_PRODUCT_availability` if selected under Suggest in the portal
            $product = AvailabilityResolver::resolveDocument($document, $country)['_PRODUCT'] ?? [];
            // the shop id (`_PRODUCT.id`, selected under Suggest in the portal) plus the type is the document id, e.g. PRODUCT-720
            $productId = strip_tags((string) ($product['id'] ?? ''));
            // the highlighted name is part of the _PRODUCT_i18n node, resolved by the gateway for the requested locale
            $name = (string) ($document['_PRODUCT_i18n']['name'] ?? $product['name'] ?? '');
            $products[] = [
                'html' => $this->demo->highlight($name),
                'image' => (string) ($product['imageUrl'] ?? ''),
                'url' => $productId !== '' ? $this->demo->detailUrl('PRODUCT-' . $productId) : '',
                'ordernumber' => strip_tags((string) ($product['ordernumber'] ?? '')),
                'price' => isset($product['price']) ? $this->demo->formatPrice((float) strip_tags((string) $product['price'])) : '',
                'stock' => isset($product['active']) || isset($product['instock']) ? $this->demo->stockStatus($product) : null,
            ];
        }

        $jsonResponse = new JsonResponse(['completions' => $completions, 'products' => $products, 'found' => $response->getFounds()]);
        $jsonResponse->setEncodingOptions($jsonResponse->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->demo->applyCookies($jsonResponse);
    }
}
