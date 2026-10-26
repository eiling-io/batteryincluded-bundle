<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Profiler;

use Batteryincluded\BatteryincludedBundle\Client\TraceableHttpClient;
use Batteryincluded\BatteryincludedBundle\Profiler\ApiDataCollector;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ApiDataCollectorTest extends TestCase
{
    private const COLLECTIONS = [
        'default' => ['baseUrl' => 'https://api.test/collections/', 'collection' => 'customer.test', 'apiKey' => 'secret-default'],
        'ch' => ['baseUrl' => 'https://api.test/collections/', 'collection' => 'customer.test-ch', 'apiKey' => 'secret-ch'],
    ];

    private TraceableHttpClient $client;

    private ApiDataCollector $collector;

    protected function setUp(): void
    {
        $http = (new FakeHttpClient())->queue(
            new Response('{"found":12,"out_of":30,"page":1,"search_time_ms":3,"hits":[{},{}],"facet_counts":[{}]}', 200),
            new Response('[{"id":"PRODUCT-1","success":true}]', 200),
            new Response('{"message":"forbidden"}', 403),
        );
        $this->client = new TraceableHttpClient($http);
        $this->collector = new ApiDataCollector($this->client, self::COLLECTIONS);
    }

    public function testCollectsTheCalls(): void
    {
        $headers = ['Content-Type: application/json', 'X-BI-API-KEY: secret-default'];
        $this->client->send('https://api.test/collections/customer.test/documents/browse?q=laptop', 'GET', $headers, '');
        $this->client->send('https://api.test/collections/customer.test-ch/documents/import', 'POST', $headers, "\n{\"id\":\"PRODUCT-1\"}\n");
        $this->client->send('https://api.test/collections/customer.test/documents/presets', 'GET', $headers, '');

        $this->collector->collect(new Request(), new HttpResponse());
        [$browse, $import, $presets] = $this->collector->getCalls();

        self::assertSame(3, $this->collector->getCallCount());
        self::assertSame(1, $this->collector->getErrorCount());

        self::assertSame('/documents/browse', $browse['endpoint']);
        self::assertSame('default', $browse['collection']);
        self::assertTrue($browse['hasQuery']);
        self::assertSame(['found' => 12, 'out of' => 30, 'page' => 1, 'search time (ms)' => 3, 'hits' => 2, 'facets' => 1], $browse['summary']);

        self::assertSame('/documents/import', $import['endpoint']);
        self::assertSame('ch', $import['collection']);
        self::assertTrue($import['hasBody']);

        self::assertTrue($presets['isError']);
        self::assertSame(403, $presets['statusCode']);
    }

    public function testNeverExposesTheApiKey(): void
    {
        $this->client->send('https://api.test/collections/customer.test/documents/import', 'POST', ['X-BI-API-KEY: secret-default'], '{"a":1}');

        $this->collector->collect(new Request(), new HttpResponse());
        $call = $this->collector->getCalls()[0];

        self::assertSame(['X-BI-API-KEY: secr••••••••'], $call['headers']);
        self::assertStringContainsString('"X-BI-API-KEY: $BATTERYINCLUDED_API_KEY"', $call['curlCommand']);
        self::assertStringContainsString("--data-binary '{\"a\":1}'", $call['curlCommand']);
        self::assertSame('secr••••••••', $this->collector->getConfig()['apiKey']);
        self::assertSame('secr••••••••', $this->collector->getCollections()['ch']['apiKey']);
        self::assertStringNotContainsString('secret-default', serialize($this->collector));
    }

    public function testMasksApiKeys(): void
    {
        self::assertSame('', ApiDataCollector::maskApiKey(''));
        self::assertSame('abcd••••••••', ApiDataCollector::maskApiKey('abcdefghijklmnop'));
    }

    public function testReset(): void
    {
        $this->client->send('https://api.test/collections/customer.test/documents/browse', 'GET', [], '');
        $this->collector->collect(new Request(), new HttpResponse());
        $this->collector->reset();

        self::assertSame(0, $this->collector->getCallCount());
        self::assertSame(0.0, $this->collector->getDuration());
        self::assertSame(['sdk' => null, 'bundle' => null], $this->collector->getVersions());
    }
}
