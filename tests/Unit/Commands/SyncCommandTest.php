<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Commands;

use Batteryincluded\BatteryincludedBundle\Commands\SyncCommand;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SyncCommandTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testSyncsTheDocuments(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-1', 'PRODUCT-3', 'PRODUCT-1']]));

        self::assertSame(['/documents/import'], $this->http->endpoints());
        self::assertSame(['PRODUCT-1', 'PRODUCT-3'], array_column(FakeHttpClient::documents($this->http->requests[0]['data']), 'id'));
        self::assertStringContainsString('2 documents synced', $tester->getDisplay());
    }

    public function testDryRunPrintsTheDocuments(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-2', 'PRODUCT-9'], '--dry-run' => true]));

        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('"id": "PRODUCT-2"', $tester->getDisplay());
        self::assertStringContainsString('No document for: PRODUCT-9', $tester->getDisplay());
    }

    public function testReportsMissingDocuments(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-1', 'PRODUCT-9']]));

        self::assertSame(['/documents/import'], $this->http->endpoints());
        self::assertStringContainsString('use --delete-missing', $tester->getDisplay());
    }

    public function testDeletesMissingDocuments(): void
    {
        $this->http->queue(new Response('{"num_deleted":1}', 200));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-9'], '--delete-missing' => true]));

        self::assertSame(['/documents/delete'], $this->http->endpoints());
        self::assertSame('["PRODUCT-9"]', $this->http->requests[0]['data']);
        self::assertStringContainsString('(1 deleted)', $tester->getDisplay());
    }

    public function testFailsIfDocumentsAreRejected(): void
    {
        $this->http->queue(static fn (string $url, string $method, string $data) => FakeHttpClient::importResponse($data, ['PRODUCT-2' => 'invalid price']));
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-1', 'PRODUCT-2']]));
        self::assertStringContainsString('PRODUCT-2: invalid price', $tester->getDisplay());
    }

    public function testFailsOnErrorStatus(): void
    {
        $this->http->queue(new Response('{"message":"unauthorized"}', 401));
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-1']]));
        self::assertStringContainsString('401', $tester->getDisplay());
    }

    public function testSyncsIntoAnotherCollection(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-1'], '--collection' => 'ch']));
        self::assertStringContainsString('/customer.test-ch/documents/import', $this->http->requests[0]['url']);
    }

    public function testFailsForAnUnknownCollection(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-1'], '--collection' => 'at']));
        self::assertSame([], $this->http->requests);
    }

    private function tester(): CommandTester
    {
        $synchronizer = new DocumentSynchronizer(Collections::registry($this->http, [new ArrayProvider(3)]));

        return new CommandTester(new SyncCommand($synchronizer));
    }
}
