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
     * @var list<array{method: string, url: string, headers: list<string>}>
     */
    public static array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'headers' => array_values((array) ($options['headers'] ?? []))];

        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/documents/browse') => new MockResponse((string) json_encode([
                'found' => 1,
                'out_of' => 3,
                'page' => 1,
                'search_time_ms' => 2,
                'hits' => [['document' => ['id' => 'PRODUCT-1', 'type' => 'PRODUCT', '_PRODUCT' => ['name' => 'Laptop', 'price' => 999]]]],
                'facet_counts' => [],
            ])),
            str_ends_with($path, '/documents/presets') => new MockResponse('[{"id":"sale","name":"Sale"}]'),
            default => new MockResponse('{"message":"not found"}', ['http_code' => 404]),
        };
    }
}
