<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\Response;

/**
 * Records the requests and answers with the queued responses, otherwise with one success result per sent document.
 */
class FakeHttpClient implements HttpClientInterface
{
    /**
     * @var list<array{url: string, method: string, headers: array<string>, data: string}>
     */
    public array $requests = [];

    /**
     * @var list<Response|\Throwable|callable(string, string, string): Response>
     */
    private array $responses = [];

    public function queue(Response|\Throwable|callable ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $this->requests[] = ['url' => $url, 'method' => $method, 'headers' => $header, 'data' => $data];

        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (is_callable($response)) {
            return $response($url, $method, $data);
        }

        return $response ?? self::importResponse($data);
    }

    /**
     * Success result for every document of an ndjson body, like the import endpoint answers.
     *
     * @param array<string, string> $errors document id => error
     */
    public static function importResponse(string $data, array $errors = []): Response
    {
        $results = [];
        foreach (self::documents($data) as $document) {
            $id = (string) ($document['id'] ?? '');
            $results[] = isset($errors[$id]) ? ['id' => $id, 'success' => false, 'error' => $errors[$id]] : ['id' => $id, 'success' => true];
        }

        return new Response((string) json_encode($results), 200);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function documents(string $data): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $data)), static fn (string $line) => $line !== '');

        return array_values(array_map(static fn (string $line) => (array) json_decode($line, true), $lines));
    }

    /**
     * @return list<string> path and query of the requests, without base url and collection
     */
    public function endpoints(): array
    {
        return array_map(static function (array $request) {
            $path = (string) parse_url($request['url'], PHP_URL_PATH);
            $query = parse_url($request['url'], PHP_URL_QUERY);

            return mb_substr($path, (int) mb_strpos($path, '/documents')) . ($query ? '?' . $query : '');
        }, $this->requests);
    }
}
