<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\Response;
use Psr\Log\LoggerInterface;

/**
 * Logs failed and slow api calls, every call on debug level. Headers are not logged, they contain the api key.
 */
class LoggingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $slowThreshold = 1000,
    ) {
    }

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $start = microtime(true);
        try {
            $response = $this->httpClient->send($url, $method, $header, $data);
        } catch (\Throwable $exception) {
            $this->logger?->error('BatteryIncluded api call {method} {url} failed: {error}', [
                'method' => $method,
                'url' => $url,
                'error' => $exception->getMessage(),
                'duration' => $this->duration($start),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $context = [
            'method' => $method,
            'url' => $url,
            'status' => $response->getStatusCode(),
            'duration' => $this->duration($start),
        ];
        if ($response->getStatusCode() >= 400) {
            $this->logger?->error('BatteryIncluded api call {method} {url} answered with status {status}', $context + ['response' => mb_substr($response->getRawResponse(), 0, 1000)]);
        } elseif ($this->slowThreshold > 0 && $context['duration'] >= $this->slowThreshold) {
            $this->logger?->warning('BatteryIncluded api call {method} {url} took {duration} ms', $context);
        } else {
            $this->logger?->debug('BatteryIncluded api call {method} {url}: {status} in {duration} ms', $context);
        }

        return $response;
    }

    private function duration(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
