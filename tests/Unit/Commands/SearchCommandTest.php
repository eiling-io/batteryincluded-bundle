<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Commands;

use Batteryincluded\BatteryincludedBundle\Commands\SearchCommand;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchCommandTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testListsTheHits(): void
    {
        $this->http->queue(new Response((string) json_encode(['found' => 1, 'hits' => [['document' => ['id' => 'PRODUCT-1', 'type' => 'PRODUCT', '_PRODUCT' => ['name' => 'Laptop']]]]]), 200));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['query' => 'laptop']));
        self::assertStringContainsString('PRODUCT-1', $tester->getDisplay());
    }

    public function testErrorResponsesFail(): void
    {
        $this->http->queue(new Response('{"message":"Unknown preset"}', 400), new Response('{"message":"Unknown preset"}', 400));
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['query' => 'laptop']));
        self::assertStringContainsString('status 400: Unknown preset', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['query' => 'laptop', '--json' => true]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SearchCommand(Collections::registry($this->http)));
    }
}
