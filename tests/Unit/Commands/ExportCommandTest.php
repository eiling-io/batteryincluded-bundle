<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Commands;

use Batteryincluded\BatteryincludedBundle\Commands\ExportCommand;
use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use Batteryincluded\BatteryincludedBundle\Event\PostExportEvent;
use Batteryincluded\BatteryincludedBundle\Event\PreExportEvent;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Dto\BlogBaseDto;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class ExportCommandTest extends TestCase
{
    private FakeHttpClient $http;

    private EventDispatcher $dispatcher;

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->dispatcher = new EventDispatcher();
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    public function testFullImportCommitsWithTheLastBatch(): void
    {
        $tester = $this->tester([new ArrayProvider(3), new ArrayProvider(2, 10)], 2);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));

        $endpoints = $this->http->endpoints();
        self::assertCount(3, $endpoints);
        self::assertDoesNotMatchRegularExpression('/transactionCompleted/', $endpoints[0]);
        self::assertDoesNotMatchRegularExpression('/transactionCompleted/', $endpoints[1]);
        self::assertStringContainsString('transactionCompleted=1', $endpoints[2]);
        // one transaction, every document exactly once
        self::assertCount(1, array_unique(array_map(static fn (string $endpoint) => preg_replace('/&.*/', '', $endpoint), $endpoints)));
        $ids = array_merge(...array_map(static fn (array $request) => array_column(FakeHttpClient::documents($request['data']), 'id'), $this->http->requests));
        self::assertSame(['PRODUCT-1', 'PRODUCT-2', 'PRODUCT-3', 'PRODUCT-10', 'PRODUCT-11'], $ids);
        self::assertStringContainsString('Exported 5 documents', $tester->getDisplay());
    }

    public function testDoesNotCommitWithoutDocuments(): void
    {
        $tester = $this->tester([new ArrayProvider(0)]);

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('delivered no documents', $tester->getDisplay());
    }

    public function testStopsBeforeTheCommitIfDocumentsAreRejected(): void
    {
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data, ['PRODUCT-2' => 'invalid']));
        $tester = $this->tester([new ArrayProvider(4)], 2);

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertCount(1, $this->http->requests);
        self::assertStringContainsString('not committed', $tester->getDisplay());
        self::assertStringContainsString('PRODUCT-2: invalid', $tester->getDisplay());
    }

    public function testStopsBeforeTheCommitOnErrorStatus(): void
    {
        $this->http->queue(new Response('{"message":"internal error"}', 500));
        $tester = $this->tester([new ArrayProvider(4)], 2);

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertCount(1, $this->http->requests);
        self::assertStringContainsString('status 500', $tester->getDisplay());
    }

    public function testFullImportNeedsForceWithoutInteraction(): void
    {
        $tester = $this->tester([new ArrayProvider(2)]);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertSame([], $this->http->requests);
    }

    public function testAsksForConfirmation(): void
    {
        $tester = $this->tester([new ArrayProvider(2)]);
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => true]));
        self::assertSame([], $this->http->requests);

        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]));
        self::assertCount(1, $this->http->requests);
    }

    public function testSelectedProvidersAreImportedWithoutTransaction(): void
    {
        $tester = $this->tester([new ArrayProvider(2), new SecondProvider(2, 10)]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--provider' => ['secondprovider']], ['interactive' => false]));
        self::assertSame(['/documents/import'], $this->http->endpoints());
        self::assertSame(['PRODUCT-10', 'PRODUCT-11'], array_column(FakeHttpClient::documents($this->http->requests[0]['data']), 'id'));
    }

    public function testUnknownProvider(): void
    {
        self::assertSame(Command::INVALID, $this->tester([new ArrayProvider(2)])->execute(['--provider' => ['nope']]));
    }

    public function testDryRunSendsNothingAndReportsDuplicates(): void
    {
        $tester = $this->tester([new ArrayProvider(3), new ArrayProvider(2, 3)]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true], ['interactive' => false]));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('PRODUCT-3', $tester->getDisplay());
        self::assertStringContainsString('5 documents serialized', $tester->getDisplay());
    }

    public function testExportsIntoTheSelectedCollection(): void
    {
        $tester = $this->tester([new ArrayProvider(2, 1, ['default']), new ArrayProvider(1, 50, ['ch'])]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--collection' => 'ch']));
        self::assertStringContainsString('customer.test-ch', $this->http->requests[0]['url']);
        self::assertSame(['PRODUCT-50'], array_column(FakeHttpClient::documents($this->http->requests[0]['data']), 'id'));
        self::assertSame(Command::INVALID, $tester->execute(['--force' => true, '--collection' => 'nope']));
    }

    public function testLockPreventsParallelExports(): void
    {
        $lock = $this->lockFactory->createLock('batteryincluded-export-default');
        $lock->acquire();

        $tester = $this->tester([new ArrayProvider(2)]);
        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('Another export', $tester->getDisplay());
        self::assertSame([], $this->http->requests);

        $lock->release();
        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
    }

    public function testEvents(): void
    {
        $events = [];
        $this->dispatcher->addListener(PreExportEvent::class, static function (PreExportEvent $event) use (&$events): void {
            $events[] = 'pre:' . $event->collection . ':' . count($event->providers);
        });
        $this->dispatcher->addListener(DocumentsEvent::class, static function (DocumentsEvent $event) use (&$events): void {
            $events[] = 'documents:' . $event->context . ':' . count($event->getDocuments());
            // removed documents are not exported, the full import deletes them
            $event->setDocuments(array_filter($event->getDocuments(), static fn ($document) => $document->getIdentifier() !== 'PRODUCT-1'));
        });
        $this->dispatcher->addListener(PostExportEvent::class, static function (PostExportEvent $event) use (&$events): void {
            $events[] = 'post:' . ($event->successful ? 'ok' : 'failed') . ':' . $event->documents;
        });

        self::assertSame(Command::SUCCESS, $this->tester([new ArrayProvider(3)])->execute(['--force' => true]));
        self::assertSame(['pre:default:1', 'documents:export:3', 'post:ok:2'], $events);
        self::assertSame(['PRODUCT-2', 'PRODUCT-3'], array_column(FakeHttpClient::documents($this->http->requests[0]['data']), 'id'));
    }

    public function testPreExportEventCancels(): void
    {
        $post = null;
        $this->dispatcher->addListener(PreExportEvent::class, static fn (PreExportEvent $event) => $event->cancel('maintenance'));
        $this->dispatcher->addListener(PostExportEvent::class, static function (PostExportEvent $event) use (&$post): void {
            $post = $event;
        });

        $tester = $this->tester([new ArrayProvider(3)]);

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('maintenance', $tester->getDisplay());
        self::assertSame([], $this->http->requests);
        self::assertInstanceOf(PostExportEvent::class, $post);
        self::assertFalse($post->successful);
    }

    public function testReportPerProviderAndType(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bi-report');
        $post = null;
        $this->dispatcher->addListener(PostExportEvent::class, static function (PostExportEvent $event) use (&$post): void {
            $post = $event;
        });
        $blogs = new ArrayProvider();
        $blogs->documents = [new BlogBaseDto('1'), new BlogBaseDto('2')];
        $tester = $this->tester([new ArrayProvider(3), $blogs]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--report' => $file]));

        self::assertMatchesRegularExpression('/ArrayProvider\s+3\s+PRODUCT 3/', $tester->getDisplay());
        self::assertStringContainsString('Exported 5 documents (BLOG 2, PRODUCT 3)', $tester->getDisplay());

        $report = json_decode((string) file_get_contents($file), true);
        unlink($file);
        self::assertTrue($report['successful']);
        self::assertTrue($report['full_import']);
        self::assertSame(5, $report['documents']);
        self::assertSame(['BLOG' => 2, 'PRODUCT' => 3], $report['types']);
        self::assertSame(5, $report['synced']);
        self::assertSame([], $report['failed']);
        self::assertSame(['PRODUCT' => 3], $report['providers'][0]['types']);
        self::assertSame(['BLOG' => 2], $report['providers'][1]['types']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $report['started_at']));

        self::assertInstanceOf(PostExportEvent::class, $post);
        self::assertSame(['BLOG' => 2, 'PRODUCT' => 3], $post->report?->getTypes());
    }

    public function testReportContainsTheRejections(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bi-report');
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data, ['PRODUCT-2' => 'invalid price']));

        self::assertSame(Command::FAILURE, $this->tester([new ArrayProvider(3)])->execute(['--force' => true, '--report' => $file]));

        $report = json_decode((string) file_get_contents($file), true);
        unlink($file);
        self::assertFalse($report['successful']);
        self::assertSame('Documents were rejected', $report['error']);
        self::assertSame([['id' => 'PRODUCT-2', 'error' => 'invalid price']], $report['failed']);
    }

    public function testUnwritableReportIsAWarning(): void
    {
        $tester = $this->tester([new ArrayProvider(1)]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true, '--report' => '/does-not-exist/report.json']));
        self::assertStringContainsString('could not be written', $tester->getDisplay());
    }

    /**
     * @param list<DataProviderInterface> $providers
     */
    private function tester(array $providers, int $batchSize = 500): CommandTester
    {
        return new CommandTester(new ExportCommand(Collections::registry($this->http, $providers), $batchSize, $this->lockFactory, $this->dispatcher));
    }
}

class SecondProvider extends ArrayProvider
{
}
