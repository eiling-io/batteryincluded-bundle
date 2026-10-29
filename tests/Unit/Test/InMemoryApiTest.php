<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Test;

use Batteryincluded\BatteryincludedBundle\Test\InMemoryApi;
use BatteryIncludedSdk\Client\ApiClient;
use BatteryIncludedSdk\Dto\BlogBaseDto;
use BatteryIncludedSdk\Dto\ProductBaseDto;
use BatteryIncludedSdk\Service\SyncService;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestService;
use PHPUnit\Framework\TestCase;

class InMemoryApiTest extends TestCase
{
    private InMemoryApi $api;

    private ApiClient $apiClient;

    protected function setUp(): void
    {
        $this->api = new InMemoryApi();
        $this->apiClient = new ApiClient($this->api, 'https://api.test/collections/', 'customer.test', 'secret');
    }

    public function testImportsUpdatesAndDeletes(): void
    {
        $sync = new SyncService($this->apiClient);

        $sync->syncOneOrManyElements(self::product('1', 'Laptop'), self::product('2', 'Tablet'));
        $sync->partialUpdateOneOrManyElements(self::product('2', 'Tablet Pro'));
        $deleted = $sync->deleteElementsByIds('PRODUCT-1', 'PRODUCT-9')->getBody();

        self::assertSame(['num_deleted' => 1], $deleted);
        self::assertSame(['PRODUCT-2'], array_keys($this->api->getDocuments('customer.test')));
        self::assertSame('Tablet Pro', $this->api->getDocument('customer.test', 'PRODUCT-2')['_PRODUCT']['name'] ?? null);
        self::assertSame(['/documents/import', '/documents/update', '/documents/delete'], array_column($this->api->getRequests(), 'endpoint'));
    }

    public function testFullImportReplacesTheCollectionWhenTheTransactionIsCompleted(): void
    {
        $sync = new SyncService($this->apiClient);
        $sync->syncOneOrManyElements(self::product('1', 'Old'));

        $sync->syncFullBatchElements('tx', false, self::product('2', 'New'));
        self::assertSame(['PRODUCT-1'], array_keys($this->api->getDocuments('customer.test')), 'unchanged until the commit');

        $sync->syncFullBatchElements('tx', true, self::product('3', 'Newer'));
        self::assertSame(['PRODUCT-2', 'PRODUCT-3'], array_keys($this->api->getDocuments('customer.test')));

        $sync->syncFullElements(self::product('4', 'Only'));
        self::assertSame(['PRODUCT-4'], array_keys($this->api->getDocuments('customer.test')));
    }

    public function testBrowseFindsByQueryAndFilters(): void
    {
        $blog = new BlogBaseDto('1');
        $blog->setTitle('Laptop guide');
        $this->api->addDocuments('customer.test', [self::product('1', 'Laptop'), self::product('2', 'Tablet'), self::product('3', 'Laptop Pro'), $blog]);
        $browse = new BrowseService($this->apiClient);

        $search = new BrowseSearchStruct();
        $search->setQuery('LAPTOP');
        self::assertSame(3, $browse->browse($search)->getFound());

        $search->addFilter('type', 'PRODUCT');
        $search->setPerPage(1);
        $search->setPage(2);
        $response = $browse->browse($search);
        self::assertSame(2, $response->getFound());
        self::assertSame(['PRODUCT-3'], array_map(static fn (array $hit) => $hit['document']['id'], $response->getHits()));

        $byName = new BrowseSearchStruct();
        $byName->addFilter('_PRODUCT.name', 'Tablet');
        self::assertSame(1, $browse->browse($byName)->getFound());
    }

    public function testSuggestDeliversTheDocuments(): void
    {
        $this->api->addDocuments('customer.test', [self::product('1', 'Laptop')]);
        $search = new SuggestSearchStruct();
        $search->setQuery('lap');

        $response = (new SuggestService($this->apiClient))->suggestWithFilter($search);

        self::assertSame(1, $response->getFounds());
        self::assertSame('PRODUCT-1', $response->getDocuments()[0]['id']);
    }

    public function testCollectionsAreSeparated(): void
    {
        (new SyncService(new ApiClient($this->api, 'https://api.test/collections/', 'customer.other', 'secret')))->syncOneOrManyElements(self::product('1', 'Laptop'));

        self::assertSame([], $this->api->getDocuments('customer.test'));
        self::assertCount(1, $this->api->getDocuments('customer.other'));
    }

    public function testQueuedResponsesAndReset(): void
    {
        $this->api->queueResponse(503, ['message' => 'maintenance']);
        $response = (new SyncService($this->apiClient))->syncOneOrManyElements(self::product('1', 'Laptop'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([], $this->api->getDocuments('customer.test'));

        $this->api->reset();
        self::assertSame([], $this->api->getRequests());
    }

    public function testUnknownEndpoint(): void
    {
        self::assertSame(404, $this->apiClient->getJson('/documents/unknown')->getStatusCode());
        self::assertSame('[]', $this->apiClient->getJson('/documents/recommendations?id=PRODUCT-1')->getRawResponse());
    }

    private static function product(string $id, string $name): ProductBaseDto
    {
        $product = new ProductBaseDto($id);
        $product->setName($name);

        return $product;
    }
}
