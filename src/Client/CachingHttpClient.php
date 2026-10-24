<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\Response;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Caches successful GET responses of the configured endpoints (`batteryincluded.cache`), e.g. presets and highlights.
 */
class CachingHttpClient implements HttpClientInterface
{
    /**
     * @param list<string> $endpoints paths after the collection, e.g. `/documents/presets`
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttl,
        private readonly array $endpoints,
    ) {
    }

    public function send(string $url, string $method, array $header, string $data): Response
    {
        if ($method !== 'GET' || !$this->isCacheable($url)) {
            return $this->httpClient->send($url, $method, $header, $data);
        }

        // the headers contain the api key, collections with different keys never share an entry
        $item = $this->cache->getItem('batteryincluded.' . hash('xxh128', $url . "\n" . implode("\n", $header)));
        if ($item->isHit()) {
            [$raw, $statusCode] = $item->get();

            return new CachedResponse($raw, $statusCode);
        }

        $response = $this->httpClient->send($url, $method, $header, $data);
        if ($response->getStatusCode() < 400) {
            $this->cache->save($item->set([$response->getRawResponse(), $response->getStatusCode()])->expiresAfter($this->ttl));
        }

        return $response;
    }

    private function isCacheable(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->endpoints as $endpoint) {
            if (str_ends_with($path, '/' . mb_ltrim($endpoint, '/'))) {
                return true;
            }
        }

        return false;
    }
}
