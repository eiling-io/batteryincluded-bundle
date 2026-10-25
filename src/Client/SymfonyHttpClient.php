<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClientInterface;

/**
 * Sends the api calls with a Symfony http client instead of curl, so timeouts, retries, proxies and the
 * MockHttpClient of tests are configured like every other http client of the application.
 */
class SymfonyHttpClient implements HttpClientInterface
{
    /**
     * @param float|null $writeTimeout idle timeout of import, update and delete calls, null keeps the one of the client
     */
    public function __construct(
        private readonly SymfonyHttpClientInterface $httpClient,
        private readonly ?float $writeTimeout = null,
    ) {
    }

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $options = ['headers' => $header];
        if ($data !== '') {
            $options['body'] = $data;
        }
        // the api answers the commit of a full import only after indexing, searches (GET) keep the short timeout
        if ($method !== 'GET' && $this->writeTimeout !== null) {
            $options['timeout'] = $this->writeTimeout;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);

            // like curl the sdk gets error responses as well, they are not thrown
            return new Response($response->getContent(false), $response->getStatusCode());
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }
}
