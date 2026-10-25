<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Client;

use Batteryincluded\BatteryincludedBundle\Client\SymfonyHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SymfonyHttpClientTest extends TestCase
{
    public function testSendsMethodHeadersAndBody(): void
    {
        $mockResponse = new MockResponse('[{"id":"PRODUCT-1","success":true}]', ['http_code' => 200]);
        $client = new SymfonyHttpClient(new MockHttpClient($mockResponse));

        $response = $client->send('https://api.test/collections/customer.test/documents/import', 'POST', ['Content-Type: application/x-ndjson', 'X-BI-API-KEY: secret'], "\n{}\n");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['id' => 'PRODUCT-1', 'success' => true]], $response->getBody());
        self::assertSame('POST', $mockResponse->getRequestMethod());
        self::assertSame("\n{}\n", $mockResponse->getRequestOptions()['body']);
        self::assertContains('X-BI-API-KEY: secret', $mockResponse->getRequestOptions()['headers']);
    }

    public function testWriteTimeoutAppliesToWritesOnly(): void
    {
        $import = new MockResponse('[]');
        $search = new MockResponse('{}');
        $client = new SymfonyHttpClient(new MockHttpClient([$import, $search]), 300.0);

        $client->send('https://api.test/collections/customer.test/documents/import', 'POST', [], "\n{}\n");
        $client->send('https://api.test/collections/customer.test/documents/search', 'GET', [], '');

        self::assertSame(300.0, $import->getRequestOptions()['timeout']);
        self::assertNotSame(300.0, $search->getRequestOptions()['timeout']);
    }

    public function testReturnsErrorResponsesLikeCurl(): void
    {
        $client = new SymfonyHttpClient(new MockHttpClient(new MockResponse('{"message":"invalid"}', ['http_code' => 422])));

        $response = $client->send('https://api.test/x', 'GET', [], '');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"message":"invalid"}', $response->getRawResponse());
    }

    public function testConvertsTransportErrors(): void
    {
        $client = new SymfonyHttpClient(new MockHttpClient(static fn () => throw new TransportException('Connection refused')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $client->send('https://api.test/x', 'GET', [], '');
    }
}
