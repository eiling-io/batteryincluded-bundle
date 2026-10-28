<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Test;

use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Dto\AbstractDto;
use BatteryIncludedSdk\Service\Response;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Api in memory for the tests of your application, enable it with `batteryincluded.in_memory_api: true` in the test
 * environment. It stores the documents per collection and answers the endpoints of the sdk:
 *
 * - import (incl. full import and transactions), update and delete change the documents
 * - browse and suggest find the documents by query (substring), `f[type]`, `f[id]` and other exact filters
 * - recommendations, highlights and presets answer empty
 *
 * It is no replacement of the api: no relevance, no facets, no translations by the gateway, the documents are returned
 * as they were exported. Use the BatteryIncludedAssertionsTrait in your tests.
 */
class InMemoryApi implements HttpClientInterface, ResetInterface
{
    /**
     * @var array<string, array<string, array<string, mixed>>> collection => id => document
     */
    private array $documents = [];

    /**
     * @var array<string, array<string, array<string, mixed>>> transaction id => id => document
     */
    private array $transactions = [];

    /**
     * @var list<array{method: string, collection: string, endpoint: string, query: array<string, mixed>, body: string}>
     */
    private array $requests = [];

    /**
     * @var list<Response>
     */
    private array $queued = [];

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $position = mb_strpos($path, '/documents/');
        $collection = $position !== false ? basename(mb_substr($path, 0, $position)) : '';
        $endpoint = $position !== false ? mb_substr($path, $position) : $path;
        $this->requests[] = ['method' => $method, 'collection' => $collection, 'endpoint' => $endpoint, 'query' => $query, 'body' => $data];

        $queued = array_shift($this->queued);
        if ($queued !== null) {
            return $queued;
        }

        return match ($endpoint) {
            '/documents/import' => $this->import($collection, $query, $data),
            '/documents/update' => $this->update($collection, $data),
            '/documents/delete' => $this->delete($collection, $data),
            '/documents/browse' => $this->browse($collection, $query),
            '/documents/suggest' => $this->suggest($collection, $query),
            '/documents/recommendations', '/documents/presets' => self::json([]),
            '/documents/highlights' => self::json(['searches' => [], 'querySuggestions' => []]),
            default => self::json(['message' => 'Unknown endpoint ' . $endpoint], 404),
        };
    }

    /**
     * Adds documents without a request, e.g. as fixtures for a search page.
     *
     * @param iterable<AbstractDto|array<string, mixed>> $documents
     */
    public function addDocuments(string $collection, iterable $documents): void
    {
        foreach ($documents as $document) {
            $document = $document instanceof AbstractDto ? self::normalize($document) : $document;
            $this->documents[$collection][(string) $document['id']] = $document;
        }
    }

    /**
     * @return array<string, array<string, mixed>> id => document
     */
    public function getDocuments(string $collection): array
    {
        return $this->documents[$collection] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDocument(string $collection, string $id): ?array
    {
        return $this->documents[$collection][$id] ?? null;
    }

    /**
     * @return list<array{method: string, collection: string, endpoint: string, query: array<string, mixed>, body: string}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * The next request is answered with this response, e.g. to test the error handling.
     */
    public function queueResponse(int $statusCode, mixed $body = ['message' => 'error']): void
    {
        $this->queued[] = new Response(is_string($body) ? $body : (string) json_encode($body), $statusCode);
    }

    public function reset(): void
    {
        $this->documents = [];
        $this->transactions = [];
        $this->requests = [];
        $this->queued = [];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function import(string $collection, array $query, string $data): Response
    {
        $documents = self::documents($data);
        $results = array_map(static fn (array $document) => isset($document['id']) ? ['id' => $document['id'], 'success' => true] : ['success' => false, 'error' => 'Missing id'], $documents);
        $valid = array_column(array_filter($documents, static fn (array $document) => isset($document['id'])), null, 'id');

        $transactionId = (string) ($query['transactionId'] ?? '');
        if ($transactionId !== '') {
            $this->transactions[$transactionId] = array_merge($this->transactions[$transactionId] ?? [], $valid);
            if (($query['transactionCompleted'] ?? null) !== null) {
                $this->documents[$collection] = $this->transactions[$transactionId];
                unset($this->transactions[$transactionId]);
            }
        } elseif (($query['full'] ?? null) !== null) {
            $this->documents[$collection] = $valid;
        } else {
            $this->documents[$collection] = array_merge($this->documents[$collection] ?? [], $valid);
        }

        return self::json($results);
    }

    private function update(string $collection, string $data): Response
    {
        $results = [];
        foreach (self::documents($data) as $document) {
            $id = (string) ($document['id'] ?? '');
            if (!isset($this->documents[$collection][$id])) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Not found'];
                continue;
            }
            $this->documents[$collection][$id] = array_replace_recursive($this->documents[$collection][$id], $document);
            $results[] = ['id' => $id, 'success' => true];
        }

        return self::json($results);
    }

    private function delete(string $collection, string $data): Response
    {
        $deleted = 0;
        foreach ((array) json_decode($data, true) as $id) {
            if (isset($this->documents[$collection][(string) $id])) {
                unset($this->documents[$collection][(string) $id]);
                ++$deleted;
            }
        }

        return self::json(['num_deleted' => $deleted]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function browse(string $collection, array $query): Response
    {
        $matches = $this->find($collection, (string) ($query['q'] ?? ''), (array) ($query['f'] ?? []));
        $perPage = max(1, (int) ($query['per_page'] ?? 10));
        $page = max(1, (int) ($query['page'] ?? 1));

        return self::json([
            'found' => count($matches),
            'out_of' => count($this->documents[$collection] ?? []),
            'page' => $page,
            'search_time_ms' => 0,
            'facet_counts' => [],
            'hits' => array_map(static fn (array $document) => ['document' => $document], array_slice($matches, ($page - 1) * $perPage, $perPage)),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function suggest(string $collection, array $query): Response
    {
        $matches = $this->find($collection, (string) ($query['q'] ?? ''), (array) ($query['f'] ?? []));

        return self::json([
            ['kind' => 'query-completion', 'hits' => []],
            ['kind' => 'document', 'found' => count($matches), 'hits' => array_map(static fn (array $document) => ['highlighted' => $document], array_slice($matches, 0, 10))],
        ]);
    }

    /**
     * @param array<string, mixed> $filters field => list of values, the fields are dotted paths like `_PRODUCT.brand`
     *
     * @return list<array<string, mixed>>
     */
    private function find(string $collection, string $query, array $filters): array
    {
        $query = mb_strtolower(mb_trim($query));
        $matches = [];
        foreach ($this->documents[$collection] ?? [] as $document) {
            if ($query !== '' && !str_contains(mb_strtolower((string) json_encode($document, JSON_UNESCAPED_UNICODE)), $query)) {
                continue;
            }
            foreach ($filters as $field => $values) {
                $value = self::value($document, (string) $field);
                $values = array_map('strval', (array) $values);
                $actual = array_map(static fn ($item) => is_scalar($item) ? (string) $item : '', is_array($value) ? $value : [$value]);
                if ($values !== [] && array_intersect($values, $actual) === []) {
                    continue 2;
                }
            }
            $matches[] = $document;
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function value(array $document, string $path): mixed
    {
        $value = $document;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(AbstractDto $document): array
    {
        return (array) json_decode((string) json_encode($document, JSON_THROW_ON_ERROR), true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function documents(string $ndjson): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $ndjson)), static fn (string $line) => $line !== '');

        return array_values(array_map(static fn (string $line) => (array) json_decode($line, true), $lines));
    }

    private static function json(mixed $body, int $statusCode = 200): Response
    {
        return new Response((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $statusCode);
    }
}
