<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Sync;

use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DocumentSynchronizerTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testFindsDocumentsOfPartialProviders(): void
    {
        $synchronizer = $this->synchronizer([new ArrayProvider(3), new ArrayProvider(2, 10)]);

        $found = $synchronizer->findDocuments(['PRODUCT-2', 'PRODUCT-11', 'PRODUCT-99', 'PRODUCT-2']);

        self::assertSame(['PRODUCT-2', 'PRODUCT-11'], array_map(static fn ($document) => $document->getIdentifier(), $found['documents']));
        self::assertSame(['PRODUCT-99'], $found['missing']);
    }

    public function testSkipsProvidersOfOtherCollections(): void
    {
        $synchronizer = $this->synchronizer([new ArrayProvider(3, 1, ['ch'])]);

        $this->expectException(\LogicException::class);

        $synchronizer->findDocuments(['PRODUCT-1']);
    }

    public function testNeedsPartialProvider(): void
    {
        $provider = $this->createStub(DataProviderInterface::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PartialDataProviderInterface');

        $this->synchronizer([$provider])->findDocuments(['PRODUCT-1']);
    }

    public function testSyncsInBatches(): void
    {
        $result = $this->synchronizer([], 2)->sync((new ArrayProvider(5))->documents, 'ch');

        self::assertSame(['/documents/import', '/documents/import', '/documents/import'], $this->http->endpoints());
        self::assertStringContainsString('customer.test-ch', $this->http->requests[0]['url']);
        self::assertSame([2, 2, 1], array_map(static fn (array $request) => count(FakeHttpClient::documents($request['data'])), $this->http->requests));
        self::assertSame(5, $result->getSynced());
        self::assertTrue($result->isSuccessful());
    }

    public function testReportsRejectedDocuments(): void
    {
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data, ['PRODUCT-2' => 'invalid price']));

        $result = $this->synchronizer()->sync((new ArrayProvider(3))->documents);

        self::assertFalse($result->isSuccessful());
        self::assertSame([['id' => 'PRODUCT-2', 'error' => 'invalid price']], $result->getFailed());
        self::assertSame(2, $result->getSynced());
    }

    public function testThrowsOnErrorStatus(): void
    {
        $this->http->queue(new Response('{"message":"unauthorized"}', 401));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('status 401');

        $this->synchronizer()->sync((new ArrayProvider(1))->documents);
    }

    public function testSyncByIdsDeletesMissingDocuments(): void
    {
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data), new Response('{"num_deleted":1}', 200));

        $result = $this->synchronizer([new ArrayProvider(3)])->syncByIds(['PRODUCT-1', 'PRODUCT-99'], deleteMissing: true);

        self::assertSame(['/documents/import', '/documents/delete'], $this->http->endpoints());
        self::assertSame('["PRODUCT-99"]', $this->http->requests[1]['data']);
        self::assertSame(1, $result->getSynced());
        self::assertSame(['PRODUCT-99'], $result->getMissing());
        self::assertSame(1, $result->getDeleted());
    }

    public function testSyncByIdsKeepsMissingDocumentsByDefault(): void
    {
        $result = $this->synchronizer([new ArrayProvider(3)])->syncByIds(['PRODUCT-99']);

        self::assertSame([], $this->http->requests);
        self::assertSame(['PRODUCT-99'], $result->getMissing());
    }

    public function testDocumentsEventCanChangeTheBatch(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DocumentsEvent::class, static function (DocumentsEvent $event): void {
            self::assertSame(DocumentsEvent::CONTEXT_SYNC, $event->context);
            $event->setDocuments(array_filter($event->getDocuments(), static fn ($document) => $document->getIdentifier() !== 'PRODUCT-2'));
        });

        $result = (new DocumentSynchronizer(Collections::registry($this->http), 500, $dispatcher))->sync((new ArrayProvider(3))->documents);

        self::assertSame(['PRODUCT-1', 'PRODUCT-3'], array_column(FakeHttpClient::documents($this->http->requests[0]['data']), 'id'));
        self::assertSame(2, $result->getSynced());
    }

    public function testDelete(): void
    {
        $this->http->queue(new Response('{"num_deleted":2}', 200));

        self::assertSame(2, $this->synchronizer()->delete(['PRODUCT-1', 'PRODUCT-2', 'PRODUCT-1']));
        self::assertSame('DELETE', $this->http->requests[0]['method']);
        self::assertSame('["PRODUCT-1","PRODUCT-2"]', $this->http->requests[0]['data']);
        self::assertSame(0, $this->synchronizer()->delete([]));
        self::assertCount(1, $this->http->requests);
    }

    /**
     * @param list<DataProviderInterface> $providers
     */
    private function synchronizer(array $providers = [], int $batchSize = 500): DocumentSynchronizer
    {
        return new DocumentSynchronizer(Collections::registry($this->http, $providers), $batchSize);
    }
}
