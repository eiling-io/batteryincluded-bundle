<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Profiler;

use Batteryincluded\BatteryincludedBundle\Client\TraceableHttpClient;
use Composer\InstalledVersions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

class ApiDataCollector extends DataCollector
{
    private const API_KEY_HEADER = 'x-bi-api-key';

    private const PACKAGES = [
        'sdk' => 'batteryincluded/batteryincluded-php-sdk',
        'bundle' => 'batteryincluded/batteryincluded-bundle',
    ];

    /**
     * @param array<string, array{baseUrl: string, collection: string, apiKey: string}> $collections
     */
    public function __construct(
        private readonly TraceableHttpClient $httpClient,
        private readonly array $collections = [],
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $calls = array_map(fn (array $call) => $this->prepareCall($call), $this->httpClient->getLoggedRequests());

        $this->data = [
            'calls' => $calls,
            'duration' => array_sum(array_column($calls, 'duration')),
            'errors' => count(array_filter($calls, static fn (array $call) => $call['isError'])),
            'collections' => array_map(fn (array $config) => ['apiKey' => self::maskApiKey($config['apiKey'])] + $config, $this->collections),
            'versions' => array_map(static function (string $package) {
                if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
                    return null;
                }

                return mb_trim(InstalledVersions::getPrettyVersion($package) . ' ' . mb_substr((string) InstalledVersions::getReference($package), 0, 7));
            }, self::PACKAGES),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    public function getCallCount(): int
    {
        return count($this->getCalls());
    }

    public function getDuration(): float
    {
        return $this->data['duration'] ?? 0.0;
    }

    public function getErrorCount(): int
    {
        return $this->data['errors'] ?? 0;
    }

    /**
     * Configuration of the default collection.
     *
     * @return array{baseUrl: string, collection: string, apiKey: string}
     */
    public function getConfig(): array
    {
        return $this->getCollections()['default'] ?? ['baseUrl' => '', 'collection' => '', 'apiKey' => ''];
    }

    /**
     * @return array<string, array{baseUrl: string, collection: string, apiKey: string}> api keys masked
     */
    public function getCollections(): array
    {
        return $this->data['collections'] ?? [];
    }

    /**
     * @return array{sdk: string|null, bundle: string|null}
     */
    public function getVersions(): array
    {
        return $this->data['versions'] ?? ['sdk' => null, 'bundle' => null];
    }

    public function getName(): string
    {
        return self::class;
    }

    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private function prepareCall(array $call): array
    {
        $url = (string) $call['url'];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        // the endpoint without base url and collection, e.g. `/documents/browse`
        $endpoint = $path;
        $collectionName = null;
        foreach ($this->collections as $name => $config) {
            $collectionPath = (string) parse_url($config['baseUrl'] . $config['collection'], PHP_URL_PATH);
            if ($collectionPath !== '' && str_starts_with($path, $collectionPath . '/')) {
                $endpoint = mb_substr($path, mb_strlen($collectionPath));
                $collectionName = $name;
                break;
            }
        }

        $responseRaw = (string) $call['responseRaw'];
        $response = json_decode($responseRaw, true);
        $statusCode = $call['statusCode'];

        return [
            'method' => $call['method'],
            'url' => $url,
            'endpoint' => $endpoint === '' ? '/' : $endpoint,
            'collection' => $collectionName,
            'cached' => (bool) ($call['cached'] ?? false),
            'statusCode' => $statusCode,
            'duration' => (float) $call['duration'],
            'error' => $call['error'],
            'isError' => $call['error'] !== null || $statusCode === null || $statusCode >= 400,
            'headers' => array_map(fn (string $header) => $this->maskHeader($header), (array) $call['headers']),
            'query' => $this->cloneVar($query),
            'hasQuery' => $query !== [],
            'body' => $this->cloneVar($this->decodeBody((string) $call['data'])),
            'hasBody' => $call['data'] !== '',
            'requestSize' => mb_strlen((string) $call['data'], '8bit'),
            'response' => $this->cloneVar(is_array($response) ? $response : $responseRaw),
            'responseSize' => mb_strlen($responseRaw, '8bit'),
            'summary' => is_array($response) ? $this->summarize($response) : [],
            'curlCommand' => $this->curlCommand($call),
        ];
    }

    /**
     * The api key is read from the environment, so the command can be shared without leaking it.
     *
     * @param array<string, mixed> $call
     */
    private function curlCommand(array $call): string
    {
        $command = ['curl', '-X', $call['method'], escapeshellarg((string) $call['url'])];
        foreach ((array) $call['headers'] as $header) {
            [$name] = explode(':', (string) $header, 2);
            $command[] = '-H';
            $command[] = mb_strtolower(mb_trim($name)) === self::API_KEY_HEADER
                ? '"' . mb_trim($name) . ': $BATTERYINCLUDED_API_KEY"'
                : escapeshellarg((string) $header);
        }
        if ($call['data'] !== '') {
            $command[] = '--data-binary';
            $command[] = escapeshellarg((string) $call['data']);
        }

        return implode(' ', $command);
    }

    /**
     * Json and ndjson (sync) bodies are decoded, everything else is shown as is.
     */
    private function decodeBody(string $data): mixed
    {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $data)), static fn (string $line) => $line !== ''));
        $documents = array_map(static fn (string $line) => json_decode($line, true), $lines);

        return $documents !== [] && !in_array(null, $documents, true) ? $documents : $data;
    }

    /**
     * Key figures of a response, e.g. hits and search time of a browse call.
     *
     * @param array<mixed> $response
     *
     * @return array<string, scalar>
     */
    private function summarize(array $response): array
    {
        $summary = [];
        foreach (['found' => 'found', 'out_of' => 'out of', 'page' => 'page', 'search_time_ms' => 'search time (ms)'] as $key => $label) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                $summary[$label] = $response[$key];
            }
        }
        if (isset($response['hits']) && is_array($response['hits'])) {
            $summary['hits'] = count($response['hits']);
        }
        if (isset($response['facet_counts']) && is_array($response['facet_counts'])) {
            $summary['facets'] = count($response['facet_counts']);
        }

        return $summary;
    }

    private function maskHeader(string $header): string
    {
        [$name, $value] = array_pad(explode(':', $header, 2), 2, '');

        return mb_strtolower(mb_trim($name)) === self::API_KEY_HEADER ? $name . ': ' . self::maskApiKey(mb_trim($value)) : $header;
    }

    public static function maskApiKey(string $apiKey): string
    {
        return $apiKey === '' ? '' : mb_substr($apiKey, 0, 4) . str_repeat('•', 8);
    }

    public function getData(): Data
    {
        return $this->cloneVar($this->data);
    }
}
