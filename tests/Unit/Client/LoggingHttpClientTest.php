<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Client;

use Batteryincluded\BatteryincludedBundle\Client\LoggingHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestLogger;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;

class LoggingHttpClientTest extends TestCase
{
    private const URL = 'https://api.test/collections/customer.test/documents/browse';

    public function testLogsSuccessfulCallsOnDebugLevel(): void
    {
        $logger = new TestLogger();
        (new LoggingHttpClient((new FakeHttpClient())->queue(new Response('{}', 200)), $logger))->send(self::URL, 'GET', ['X-BI-API-KEY: secret'], '');

        self::assertSame(['debug'], $logger->levels());
        self::assertSame(200, $logger->records[0]['context']['status']);
        self::assertStringNotContainsString('secret', (string) json_encode($logger->records));
    }

    public function testLogsErrorStatus(): void
    {
        $logger = new TestLogger();
        $response = (new LoggingHttpClient((new FakeHttpClient())->queue(new Response('{"message":"not found"}', 404)), $logger))->send(self::URL, 'GET', [], '');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error'], $logger->levels());
        self::assertSame('{"message":"not found"}', $logger->records[0]['context']['response']);
    }

    public function testLogsSlowCalls(): void
    {
        $logger = new TestLogger();
        $slow = static function (): Response {
            usleep(5000);

            return new Response('{}', 200);
        };
        (new LoggingHttpClient((new FakeHttpClient())->queue($slow), $logger, 1))->send(self::URL, 'GET', [], '');

        self::assertSame(['warning'], $logger->levels());
    }

    public function testLogsAndRethrowsTransportErrors(): void
    {
        $logger = new TestLogger();
        $client = new LoggingHttpClient((new FakeHttpClient())->queue(new \RuntimeException('Could not resolve host')), $logger);

        try {
            $client->send(self::URL, 'GET', [], '');
            self::fail('The exception has to be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Could not resolve host', $exception->getMessage());
        }
        self::assertSame(['error'], $logger->levels());
    }
}
