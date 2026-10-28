<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Test;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use BatteryIncludedSdk\Dto\AbstractDto;
use PHPUnit\Framework\Assert;

/**
 * Assertions for a KernelTestCase or WebTestCase, requires `batteryincluded.in_memory_api: true` in the test
 * environment. Collections are given by their name in the bundle configuration, e.g. `default` or `ch`.
 *
 *     class ProductSearchTest extends WebTestCase
 *     {
 *         use BatteryIncludedAssertionsTrait;
 *
 *         public function testFindsTheProduct(): void
 *         {
 *             $client = static::createClient();
 *             self::addBatteryIncludedDocuments([$product]);
 *             $client->request('GET', '/search?q=laptop');
 *             ...
 *         }
 *     }
 */
trait BatteryIncludedAssertionsTrait
{
    public static function getBatteryIncludedApi(): InMemoryApi
    {
        $container = static::getContainer();
        if (!$container->has(InMemoryApi::class)) {
            Assert::fail('The InMemoryApi is not registered, set "batteryincluded.in_memory_api: true" in the test environment.');
        }

        /** @var InMemoryApi $api */
        $api = $container->get(InMemoryApi::class);

        return $api;
    }

    /**
     * @param iterable<AbstractDto|array<string, mixed>> $documents
     */
    public static function addBatteryIncludedDocuments(iterable $documents, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): void
    {
        self::getBatteryIncludedApi()->addDocuments(self::batteryIncludedCollection($collection), $documents);
    }

    /**
     * @return array<string, array<string, mixed>> id => document
     */
    public static function getBatteryIncludedDocuments(string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): array
    {
        return self::getBatteryIncludedApi()->getDocuments(self::batteryIncludedCollection($collection));
    }

    /**
     * @param string $id document id, e.g. `PRODUCT-1`
     */
    public static function assertBatteryIncludedDocumentExists(string $id, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, string $message = ''): void
    {
        Assert::assertArrayHasKey($id, self::getBatteryIncludedDocuments($collection), $message ?: sprintf('The collection "%s" does not contain the document "%s".', $collection, $id));
    }

    public static function assertBatteryIncludedDocumentNotExists(string $id, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, string $message = ''): void
    {
        Assert::assertArrayNotHasKey($id, self::getBatteryIncludedDocuments($collection), $message ?: sprintf('The collection "%s" contains the document "%s".', $collection, $id));
    }

    /**
     * @param string|null $type counts only this type, e.g. `PRODUCT`
     */
    public static function assertBatteryIncludedDocumentCount(int $expected, ?string $type = null, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, string $message = ''): void
    {
        $documents = self::getBatteryIncludedDocuments($collection);
        if ($type !== null) {
            $documents = array_filter($documents, static fn (array $document) => mb_strtoupper((string) ($document['type'] ?? '')) === mb_strtoupper($type));
        }

        Assert::assertCount($expected, $documents, $message ?: sprintf('The collection "%s" contains %d instead of %d %s documents.', $collection, count($documents), $expected, $type ?? ''));
    }

    /**
     * @param string $endpoint e.g. `/documents/import`
     * @param int|null $count exact number of requests, null for at least one
     */
    public static function assertBatteryIncludedRequest(string $method, string $endpoint, ?int $count = null, string $message = ''): void
    {
        $requests = array_filter(self::getBatteryIncludedApi()->getRequests(), static fn (array $request) => $request['method'] === mb_strtoupper($method) && $request['endpoint'] === $endpoint);

        if ($count === null) {
            Assert::assertNotEmpty($requests, $message ?: sprintf('No %s %s request was sent.', $method, $endpoint));
        } else {
            Assert::assertCount($count, $requests, $message ?: sprintf('%d instead of %d %s %s requests were sent.', count($requests), $count, $method, $endpoint));
        }
    }

    private static function batteryIncludedCollection(string $collection): string
    {
        /** @var CollectionRegistry $registry */
        $registry = static::getContainer()->get(CollectionRegistry::class);

        return $registry->getConfig($collection)['collection'];
    }
}
