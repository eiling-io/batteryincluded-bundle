<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\Response;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates the http client of the sdk and logs every api call for the web profiler.
 */
class TraceableHttpClient implements HttpClientInterface, ResetInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $log = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?Stopwatch $stopwatch = null,
    ) {
    }

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $entry = [
            'url' => $url,
            'method' => $method,
            'headers' => $header,
            'data' => $data,
            'statusCode' => null,
            'responseRaw' => null,
            'duration' => 0.0,
            'error' => null,
            'cached' => false,
        ];

        $event = $this->stopwatch?->start('BatteryIncluded ' . $method . ' ' . parse_url($url, PHP_URL_PATH), 'batteryincluded');
        $start = microtime(true);
        try {
            $response = $this->httpClient->send($url, $method, $header, $data);
            $entry['statusCode'] = $response->getStatusCode();
            $entry['responseRaw'] = $response->getRawResponse();
            $entry['cached'] = $response instanceof CachedResponse;

            return $response;
        } catch (\Throwable $exception) {
            $entry['error'] = $exception->getMessage();

            throw $exception;
        } finally {
            $entry['duration'] = (microtime(true) - $start) * 1000;
            $event?->stop();
            $this->log[] = $entry;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLoggedRequests(): array
    {
        return $this->log;
    }

    public function reset(): void
    {
        $this->log = [];
    }
}
