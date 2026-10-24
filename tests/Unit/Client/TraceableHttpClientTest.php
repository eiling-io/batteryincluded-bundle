<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Client;

use Batteryincluded\BatteryincludedBundle\Client\CachedResponse;
use Batteryincluded\BatteryincludedBundle\Client\TraceableHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;

class TraceableHttpClientTest extends TestCase
{
    public function testLogsCallsAndMarksCachedResponses(): void
    {
        $client = new TraceableHttpClient((new FakeHttpClient())->queue(new Response('a', 200), new CachedResponse('b', 200), new \RuntimeException('timeout')));

        $client->send('https://api.test/a', 'GET', [], '');
        $client->send('https://api.test/b', 'GET', [], '');
        try {
            $client->send('https://api.test/c', 'GET', [], '');
        } catch (\RuntimeException) {
        }

        $log = $client->getLoggedRequests();
        self::assertCount(3, $log);
        self::assertFalse($log[0]['cached']);
        self::assertTrue($log[1]['cached']);
        self::assertSame('timeout', $log[2]['error']);
        self::assertNull($log[2]['statusCode']);

        $client->reset();
        self::assertSame([], $client->getLoggedRequests());
    }
}
