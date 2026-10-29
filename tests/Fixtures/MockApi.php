<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Response factory of the MockHttpClient in the TestKernel, records every request.
 */
class MockApi
{
    /**
     * @var list<array{method: string, url: string, headers: list<string>, timeout: float|null}>
     */
    public static array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => array_values((array) ($options['headers'] ?? [])),
            'timeout' => isset($options['timeout']) ? (float) $options['timeout'] : null,
        ];

        $path = (string) parse_url($url, PHP_URL_PATH);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return match (true) {
            ($query['q'] ?? null) === 'error' => new MockResponse('{"message":"internal error"}', ['http_code' => 500]),
            str_ends_with($path, '/documents/browse') => new MockResponse((string) json_encode([
                'found' => 1,
                'out_of' => 3,
                'page' => 1,
                'search_time_ms' => 2,
                'hits' => [['document' => self::document($url)]],
                // the gateway delivers the facets of the requested locale without locale and the ones of all others with it
                'facet_counts' => [
                    ['field_name' => '_PRODUCT.categories', 'type' => 'select', 'counts' => [['value' => 'Rechner', 'count' => 1]]],
                    ['field_name' => '_PRODUCT_i18n.categories', 'type' => 'select', 'counts' => [['value' => 'Computers>Notebooks', 'count' => 1]]],
                    ['field_name' => '_PRODUCT_i18n.properties.Color', 'field_label' => 'Colour', 'type' => 'select', 'counts' => [['value' => 'Red', 'count' => 1]]],
                    ['field_name' => '_PRODUCT_i18n.de.properties.Color', 'field_label' => 'Farbe', 'type' => 'select', 'counts' => [['value' => 'Rot', 'count' => 1]]],
                ],
            ])),
            str_ends_with($path, '/documents/presets') => new MockResponse('[{"id":"sale","name":"Sale"}]'),
            str_ends_with($path, '/documents/suggest') => new MockResponse((string) json_encode(self::SUGGEST)),
            str_ends_with($path, '/documents/delete') => new MockResponse('{"num_deleted":1}'),
            default => new MockResponse('{"message":"not found"}', ['http_code' => 404]),
        };
    }

    // suggested documents contain only the fields selected under Suggest in the portal, ordered by relevance
    private const SUGGEST = [
        ['kind' => 'query-completion', 'hits' => [['value' => 'laptop', 'highlighted' => '<mark>lap</mark>top', 'score' => 1.0]]],
        ['kind' => 'document', 'found' => 4, 'hits' => [
            ['highlighted' => ['id' => 'CONTENT-4', '_CONTENT_i18n' => ['title' => 'Warranty for your <mark>lap</mark>top', 'teaser' => '2 years']]],
            ['highlighted' => ['id' => 'PRODUCT-1', '_PRODUCT' => ['name' => '<mark>Lap</mark>top', 'ordernumber' => 'LT-1', 'price' => 999]]],
            // without id: linked to a search for the title
            ['highlighted' => ['_BLOG_i18n' => ['title' => 'The best <mark>lap</mark>tops'], '_BLOG' => ['previewImage' => 'https://img.test/blog.png']]],
            ['highlighted' => ['id' => 'FAQ-7', '_FAQ' => ['title' => '<mark>Lap</mark>top battery']]],
        ]],
    ];

    /**
     * The document filtered by id (detail page), otherwise the first product.
     *
     * @return array<string, mixed>
     */
    private static function document(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = (string) ($query['f']['id'][0] ?? $query['f']['id'] ?? 'PRODUCT-1');

        return match (true) {
            str_starts_with($id, 'CONTENT-') => ['id' => $id, 'type' => 'CONTENT', '_CONTENT' => ['title' => 'Warranty', 'teaser' => '2 years', 'content' => "First paragraph.\n\nSecond paragraph."]],
            str_starts_with($id, 'BLOG-') => ['id' => $id, 'type' => 'BLOG', '_BLOG' => ['title' => 'The best laptops', 'author' => 'Jane']],
            default => ['id' => $id, 'type' => 'PRODUCT', '_PRODUCT' => ['name' => 'Laptop', 'price' => 999]],
        };
    }
}
