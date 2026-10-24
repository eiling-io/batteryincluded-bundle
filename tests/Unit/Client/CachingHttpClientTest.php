<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Client;

use Batteryincluded\BatteryincludedBundle\Client\CachedResponse;
use Batteryincluded\BatteryincludedBundle\Client\CachingHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CachingHttpClientTest extends TestCase
{
    private const PRESETS = 'https://api.test/collections/customer.test/documents/presets';

    private FakeHttpClient $inner;

    private CachingHttpClient $client;

    protected function setUp(): void
    {
        $this->inner = new FakeHttpClient();
        $this->client = new CachingHttpClient($this->inner, new ArrayAdapter(), 60, ['/documents/presets']);
    }

    public function testCachesSuccessfulGetOfConfiguredEndpoint(): void
    {
        $this->inner->queue(new Response('{"presets":[]}', 200));

        $first = $this->client->send(self::PRESETS, 'GET', ['X-BI-API-KEY: a'], '');
        $second = $this->client->send(self::PRESETS, 'GET', ['X-BI-API-KEY: a'], '');

        self::assertCount(1, $this->inner->requests);
        self::assertNotInstanceOf(CachedResponse::class, $first);
        self::assertInstanceOf(CachedResponse::class, $second);
        self::assertSame('{"presets":[]}', $second->getRawResponse());
        self::assertSame(200, $second->getStatusCode());
    }

    public function testDoesNotShareEntriesBetweenApiKeys(): void
    {
        $this->inner->queue(new Response('a', 200), new Response('b', 200));

        $this->client->send(self::PRESETS, 'GET', ['X-BI-API-KEY: a'], '');
        $response = $this->client->send(self::PRESETS, 'GET', ['X-BI-API-KEY: b'], '');

        self::assertCount(2, $this->inner->requests);
        self::assertSame('b', $response->getRawResponse());
    }

    public function testDoesNotCacheErrors(): void
    {
        $this->inner->queue(new Response('error', 500), new Response('ok', 200));

        $this->client->send(self::PRESETS, 'GET', [], '');
        $response = $this->client->send(self::PRESETS, 'GET', [], '');

        self::assertCount(2, $this->inner->requests);
        self::assertSame('ok', $response->getRawResponse());
    }

    public function testDoesNotCacheOtherEndpointsAndMethods(): void
    {
        $browse = 'https://api.test/collections/customer.test/documents/browse?q=a';
        $this->client->send($browse, 'GET', [], '');
        $this->client->send($browse, 'GET', [], '');
        $this->client->send(self::PRESETS, 'POST', [], 'x');
        $this->client->send(self::PRESETS, 'POST', [], 'x');

        self::assertCount(4, $this->inner->requests);
    }
}
