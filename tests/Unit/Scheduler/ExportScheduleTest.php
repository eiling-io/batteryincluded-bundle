<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Scheduler;

use Batteryincluded\BatteryincludedBundle\Commands\ExportCommand;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportMessage;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportMessageHandler;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportScheduleProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestLogger;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;

class ExportScheduleTest extends TestCase
{
    public function testScheduleContainsOneMessagePerExport(): void
    {
        $provider = new ExportScheduleProvider([
            ['collection' => 'default', 'cron' => '0 3 * * *', 'providers' => []],
            ['collection' => 'ch', 'cron' => '*/15 * * * *', 'providers' => ['ProductProvider']],
        ]);

        $messages = $provider->getSchedule()->getRecurringMessages();

        self::assertCount(2, $messages);
        self::assertSame($provider->getSchedule(), $provider->getSchedule());
        self::assertStringContainsString('0 3 * * *', (string) $messages[0]->getTrigger());

        $message = iterator_to_array($messages[1]->getProvider()->getMessages(new MessageContext('batteryincluded', 'id', $messages[1]->getTrigger(), new \DateTimeImmutable())))[0];
        self::assertEquals(new ExportMessage('ch', ['ProductProvider']), $message);
    }

    public function testHandlerRunsAFullImport(): void
    {
        $http = new FakeHttpClient();
        $logger = new TestLogger();

        (new ExportMessageHandler($this->command($http), $logger))(new ExportMessage());

        self::assertCount(1, $http->requests);
        self::assertStringContainsString('transactionCompleted=1', $http->requests[0]['url']);
        self::assertSame(['info'], $logger->levels());
        self::assertStringContainsString('Exported 3 documents', (string) $logger->records[0]['context']['output']);
    }

    public function testHandlerExportsTheGivenProvidersOfTheCollection(): void
    {
        $http = new FakeHttpClient();

        (new ExportMessageHandler($this->command($http)))(new ExportMessage('ch', ['ArrayProvider']));

        self::assertStringEndsWith('/customer.test-ch/documents/import', $http->requests[0]['url']);
    }

    public function testHandlerThrowsIfTheExportFails(): void
    {
        $http = (new FakeHttpClient())->queue(new Response('{"message":"invalid api key"}', 401));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The scheduled export into the collection "default" failed with exit code 1');

        (new ExportMessageHandler($this->command($http)))(new ExportMessage());
    }

    private function command(FakeHttpClient $http): ExportCommand
    {
        return new ExportCommand(Collections::registry($http, [new ArrayProvider(3)]), 500, new LockFactory(new InMemoryStore()));
    }
}
