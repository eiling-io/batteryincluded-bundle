<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Commands;

use Batteryincluded\BatteryincludedBundle\Commands\DeleteCommand;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DeleteCommandTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testDeletesWithForce(): void
    {
        $this->http->queue(new Response('{"num_deleted":2}', 200));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-5', 'BLOG-3', 'PRODUCT-5'], '--force' => true], ['interactive' => false]));

        self::assertSame(['/documents/delete'], $this->http->endpoints());
        self::assertSame('DELETE', $this->http->requests[0]['method']);
        self::assertSame('["PRODUCT-5","BLOG-3"]', $this->http->requests[0]['data']);
        self::assertStringContainsString('2 documents deleted', $tester->getDisplay());
    }

    public function testWarnsAboutIdsThatDidNotExist(): void
    {
        $this->http->queue(new Response('{"num_deleted":1}', 200));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-5', 'PRODUCT-6'], '--force' => true]));
        self::assertStringContainsString('1 of 2 documents deleted', $tester->getDisplay());
    }

    public function testRequiresForceWithoutInteraction(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-5']], ['interactive' => false]));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testAsksForConfirmation(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-5']], ['interactive' => true]));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('Delete 1 documents from "customer.test"?', $tester->getDisplay());

        $this->http->queue(new Response('{"num_deleted":1}', 200));
        $tester->setInputs(['yes']);
        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['PRODUCT-5'], '--collection' => 'ch'], ['interactive' => true]));
        self::assertStringContainsString('/customer.test-ch/documents/delete', $this->http->requests[0]['url']);
    }

    public function testFailsOnErrorStatus(): void
    {
        $this->http->queue(new Response('{"message":"internal error"}', 500));
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-5'], '--force' => true]));
        self::assertStringContainsString('500', $tester->getDisplay());
    }

    public function testFailsForAnUnknownCollection(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['PRODUCT-5'], '--collection' => 'at', '--force' => true]));
        self::assertSame([], $this->http->requests);
    }

    private function tester(): CommandTester
    {
        $collections = Collections::registry($this->http);

        return new CommandTester(new DeleteCommand(new DocumentSynchronizer($collections), $collections));
    }
}
