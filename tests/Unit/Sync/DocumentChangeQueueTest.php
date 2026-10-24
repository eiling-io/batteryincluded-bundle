<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Sync;

use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestLogger;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class DocumentChangeQueueTest extends TestCase
{
    public function testDispatchesMessagesPerCollectionAndAction(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message, $stamps);
            }
        };
        $queue = new DocumentChangeQueue($this->synchronizer(new FakeHttpClient()), $bus);

        $queue->scheduleSync('PRODUCT-1');
        $queue->scheduleSync('PRODUCT-2', deleteMissing: true);
        $queue->scheduleSync('PRODUCT-3');
        $queue->scheduleDelete('PRODUCT-3');
        $queue->scheduleSync('PRODUCT-4', 'ch');
        $queue->flush();

        self::assertEquals([
            new SyncDocumentsMessage(['PRODUCT-1'], 'default', false),
            new SyncDocumentsMessage(['PRODUCT-2'], 'default', true),
            new DeleteDocumentsMessage(['PRODUCT-3'], 'default'),
            new SyncDocumentsMessage(['PRODUCT-4'], 'ch', false),
        ], $bus->messages);
        self::assertSame([], $queue->getChanges());

        $queue->flush();
        self::assertCount(4, $bus->messages);
    }

    public function testSyncsDirectlyWithoutMessenger(): void
    {
        $http = (new FakeHttpClient())->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data), new Response('{"num_deleted":1}', 200));
        $queue = new DocumentChangeQueue($this->synchronizer($http));

        $queue->scheduleSync('PRODUCT-1');
        $queue->scheduleSync('PRODUCT-2');
        $queue->scheduleDelete('PRODUCT-5');
        $queue->reset();

        self::assertSame(['/documents/import', '/documents/delete'], $http->endpoints());
        self::assertSame(['PRODUCT-1', 'PRODUCT-2'], array_column(FakeHttpClient::documents($http->requests[0]['data']), 'id'));
    }

    public function testLogsErrorsInsteadOfThrowing(): void
    {
        $logger = new TestLogger();
        $queue = new DocumentChangeQueue($this->synchronizer((new FakeHttpClient())->queue(new Response('down', 503))), null, $logger);

        $queue->scheduleSync('PRODUCT-1');
        $queue->flush();

        self::assertSame(['error'], $logger->levels());
        self::assertSame([], $queue->getChanges());
    }

    private function synchronizer(FakeHttpClient $http): DocumentSynchronizer
    {
        return new DocumentSynchronizer(Collections::registry($http, [new ArrayProvider(3)]));
    }
}
