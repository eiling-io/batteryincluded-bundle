<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Messenger;

use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsHandler;
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsHandler;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class HandlersTest extends TestCase
{
    private FakeHttpClient $http;

    private DocumentSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->synchronizer = new DocumentSynchronizer(Collections::registry($this->http, [new ArrayProvider(3)]));
    }

    public function testSyncsAndDeletesMissingDocuments(): void
    {
        $this->http->queue(
            static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data),
            new Response('{"num_deleted":1}', 200),
        );

        (new SyncDocumentsHandler($this->synchronizer))(new SyncDocumentsMessage(['PRODUCT-1', 'PRODUCT-9'], 'ch', deleteMissing: true));

        self::assertSame(['/documents/import', '/documents/delete'], $this->http->endpoints());
        self::assertStringContainsString('/customer.test-ch/', $this->http->requests[0]['url']);
        self::assertSame('["PRODUCT-9"]', $this->http->requests[1]['data']);
    }

    public function testRejectedDocumentsAreNotRetried(): void
    {
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data, ['PRODUCT-1' => 'invalid']));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('PRODUCT-1 (invalid)');

        (new SyncDocumentsHandler($this->synchronizer))(new SyncDocumentsMessage(['PRODUCT-1']));
    }

    public function testDocumentsThatCannotBeEncodedAreNotRetried(): void
    {
        $provider = new ArrayProvider(1);
        $provider->documents[0]->setPrice(NAN);
        $synchronizer = new DocumentSynchronizer(Collections::registry($this->http, [$provider]));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('PRODUCT-1 cannot be encoded');

        (new SyncDocumentsHandler($synchronizer))(new SyncDocumentsMessage(['PRODUCT-1']));
    }

    public function testApiErrorsAreRetried(): void
    {
        $this->http->queue(new Response('{"message":"bad gateway"}', 502));

        // a recoverable exception, the transport retries the message
        $this->expectException(ApiException::class);

        (new SyncDocumentsHandler($this->synchronizer))(new SyncDocumentsMessage(['PRODUCT-1']));
    }

    public function testDeletesDocuments(): void
    {
        $this->http->queue(new Response('{"num_deleted":2}', 200));

        (new DeleteDocumentsHandler($this->synchronizer))(new DeleteDocumentsMessage(['PRODUCT-5', 'BLOG-3'], 'ch'));

        self::assertSame(['/documents/delete'], $this->http->endpoints());
        self::assertStringContainsString('/customer.test-ch/', $this->http->requests[0]['url']);
        self::assertSame('["PRODUCT-5","BLOG-3"]', $this->http->requests[0]['data']);
    }
}
