<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Commands;

use Batteryincluded\BatteryincludedBundle\Commands\DiffCommand;
use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use Batteryincluded\BatteryincludedBundle\Sync\CollectionComparator;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\ArrayProvider;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\Collections;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\FakeHttpClient;
use BatteryIncludedSdk\Service\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DiffCommandTest extends TestCase
{
    private FakeHttpClient $http;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->dispatcher = new EventDispatcher();
    }

    public function testConsistentCollection(): void
    {
        $this->http->queue(self::page(['PRODUCT-1', 'PRODUCT-2', 'PRODUCT-3'], 3));
        $tester = $this->tester(3);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('exactly the documents of the providers', $tester->getDisplay());
        // nothing is written
        self::assertSame(['/documents/browse?q=&page=1&per_page=250'], $this->http->endpoints());
    }

    public function testListsMissingAndOrphanedDocuments(): void
    {
        $this->http->queue(self::page(['PRODUCT-1', 'PRODUCT-3', 'PRODUCT-9', 'BLOG-1'], 4));
        $tester = $this->tester(3);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/BLOG\s+0\s+1\s+0\s+1/', $display);
        self::assertMatchesRegularExpression('/PRODUCT\s+3\s+3\s+1\s+1/', $display);
        self::assertStringContainsString("Missing in the collection\n", str_replace(['-', '='], '', $display));
        self::assertMatchesRegularExpression('/^ ?PRODUCT-2$/m', $display);
        self::assertMatchesRegularExpression('/^ ?BLOG-1 PRODUCT-9$/m', $display);
    }

    public function testReadsAllPages(): void
    {
        $ids = array_map(static fn (int $i) => 'PRODUCT-' . $i, range(1, 300));
        $this->http->queue(self::page(array_slice($ids, 0, 250), 300), self::page(array_slice($ids, 250), 300));

        self::assertSame(Command::SUCCESS, $this->tester(300)->execute([]));
        self::assertSame(['/documents/browse?q=&page=1&per_page=250', '/documents/browse?q=&page=2&per_page=250'], $this->http->endpoints());
    }

    public function testWarnsIfTheApiDeliversFewerIdsThanFound(): void
    {
        $this->http->queue(self::page(['PRODUCT-1'], 2), self::page([], 2));
        $tester = $this->tester(1);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('found 2 documents but delivered 1 ids', $tester->getDisplay());
    }

    public function testFiltersTheTypes(): void
    {
        $this->http->queue(self::page(['PRODUCT-1'], 1));

        self::assertSame(Command::FAILURE, $this->tester(2)->execute(['--type' => ['product']]));
        self::assertSame(['/documents/browse?q=&f%5Btype%5D%5B0%5D=PRODUCT&page=1&per_page=250'], $this->http->endpoints());
    }

    public function testJsonAndLimit(): void
    {
        $this->http->queue(self::page([], 0));
        $tester = $this->tester(3);

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json', '--collection' => 'ch']));

        $json = json_decode($tester->getDisplay(), true);
        self::assertSame('customer.test-ch', $json['collection']);
        self::assertFalse($json['consistent']);
        self::assertSame(['PRODUCT-1', 'PRODUCT-2', 'PRODUCT-3'], $json['missing']);
        self::assertSame(['providers' => 3, 'index' => 0, 'missing' => 3, 'orphaned' => 0], $json['types']['PRODUCT']);
    }

    public function testLimitsTheListedIds(): void
    {
        $this->http->queue(self::page([], 0));
        $tester = $this->tester(5);

        $tester->execute(['--limit' => '2']);

        self::assertStringContainsString('(first 2 of 5)', $tester->getDisplay());
        self::assertStringNotContainsString('PRODUCT-3', $tester->getDisplay());
    }

    public function testAppliesTheDocumentsEventLikeTheExport(): void
    {
        $this->dispatcher->addListener(DocumentsEvent::class, static function (DocumentsEvent $event): void {
            $event->setDocuments(array_filter($event->getDocuments(), static fn ($document) => $document->getIdentifier() !== 'PRODUCT-2'));
        });
        $this->http->queue(self::page(['PRODUCT-1'], 1));

        self::assertSame(Command::SUCCESS, $this->tester(2)->execute([]));
    }

    public function testFailsOnErrorStatus(): void
    {
        $this->http->queue(new Response('{"message":"invalid api key"}', 401));
        $tester = $this->tester(1);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('401', $tester->getDisplay());
    }

    public function testUnknownCollection(): void
    {
        self::assertSame(Command::INVALID, $this->tester(1)->execute(['--collection' => 'at']));
    }

    /**
     * @param list<string> $ids
     */
    private static function page(array $ids, int $found): Response
    {
        return new Response((string) json_encode(['found' => $found, 'hits' => array_map(static fn (string $id) => ['document' => ['id' => $id]], $ids)]), 200);
    }

    private function tester(int $products): CommandTester
    {
        $registry = Collections::registry($this->http, [new ArrayProvider($products)]);

        return new CommandTester(new DiffCommand(new CollectionComparator($registry, 500, $this->dispatcher), $registry));
    }
}
