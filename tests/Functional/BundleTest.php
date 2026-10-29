<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundle\Client\TraceableHttpClient;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Demo\Controller\DemoController;
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsHandler;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsHandler;
use Batteryincluded\BatteryincludedBundle\Profiler\ApiDataCollector;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportMessageHandler;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportScheduleProvider;
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;
use Batteryincluded\BatteryincludedBundle\Test\InMemoryApi;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use BatteryIncludedSdk\Client\CurlHttpClient;
use BatteryIncludedSdk\Presets\PresetsService;
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\HttpFoundation\Request;

class BundleTest extends FunctionalTestCase
{
    public function testRegistersTheCollections(): void
    {
        $container = $this->container();
        $registry = $container->get(CollectionRegistry::class);

        self::assertSame(['default', 'ch'], $registry->getNames());
        self::assertSame('customer.test-ch', $registry->getConfig('ch')['collection']);
        self::assertSame('https://api.test/collections/', $registry->getConfig('ch')['baseUrl'], 'inherits the base url');
        self::assertInstanceOf(BrowseService::class, $registry->get('ch', BrowseService::class));
        self::assertNotSame($registry->get('default', BrowseService::class), $registry->get('ch', BrowseService::class));
    }

    public function testHandlesASearchRequest(): void
    {
        $response = $this->bootKernel()->handle(Request::create('https://shop.ch/search?q=laptop&per_page=5'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['query' => 'laptop', 'perPage' => 5, 'availability' => 'ch', 'found' => 1], json_decode((string) $response->getContent(), true));

        self::assertCount(1, MockApi::$requests);
        $request = MockApi::$requests[0];
        self::assertStringStartsWith('https://api.test/collections/customer.test-ch/documents/browse?', $request['url']);
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        self::assertSame('ch', $query['v']['availability'] ?? null);
        self::assertContains('X-BI-API-KEY: secret-ch', $request['headers']);
    }

    public function testCachesConfiguredEndpoints(): void
    {
        $presets = $this->container()->get(PresetsService::class);
        $presets->getPresets();
        $presets->getPresets();

        self::assertCount(1, MockApi::$requests);
    }

    public function testStatusCommandReportsEmptyCredentials(): void
    {
        // like after the installation, the recipe adds the variables empty to .env
        $_SERVER['BATTERYINCLUDED_TEST_EMPTY'] = '';
        try {
            $tester = $this->application(['api_key' => '%env(BATTERYINCLUDED_TEST_EMPTY)%']);

            self::assertSame(1, $tester->run(['command' => 'bi:status', '-c' => 'default']));
            self::assertStringContainsString('api key not configured', $tester->getDisplay());
            self::assertSame([], MockApi::$requests);
        } finally {
            unset($_SERVER['BATTERYINCLUDED_TEST_EMPTY']);
        }
    }

    public function testStatusCommand(): void
    {
        $tester = $this->application();

        self::assertSame(0, $tester->run(['command' => 'bi:status']));
        self::assertStringContainsString('customer.test-ch', $tester->getDisplay());
        self::assertStringContainsString('secr••••••••', $tester->getDisplay());
        self::assertStringNotContainsString('secret-ch', $tester->getDisplay());
        self::assertCount(2, MockApi::$requests);
    }

    public function testSearchCommand(): void
    {
        $tester = $this->application();

        self::assertSame(0, $tester->run(['command' => 'bi:search', 'query' => 'laptop', '--filter' => ['_PRODUCT.price=100..']]));
        self::assertStringContainsString('PRODUCT-1', $tester->getDisplay());
        parse_str((string) parse_url(MockApi::$requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame(['from' => '100', 'till' => (string) PHP_INT_MAX], $query['f']['_PRODUCT.price']);
    }

    public function testRejectsAnInvalidBaseUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ending with "/"');

        $this->container(['base_url' => 'https://api.test/collections']);
    }

    public function testRejectsAnInvalidCollectionName(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['collections' => ['default' => ['collection' => 'x', 'api_key' => 'y']]]);
    }

    public function testWritesUseTheWriteTimeout(): void
    {
        $tester = $this->application(['write_timeout' => 123]);

        self::assertSame(0, $tester->run(['command' => 'bi:delete', 'ids' => ['PRODUCT-1'], '--force' => true]));
        self::assertSame(0, $tester->run(['command' => 'bi:status']));

        [$delete, $status] = MockApi::$requests;
        self::assertSame('DELETE', $delete['method']);
        self::assertSame(123.0, $delete['timeout']);
        self::assertSame('GET', $status['method']);
        self::assertNotSame(123.0, $status['timeout'], 'searches keep the timeout of the client');
    }

    public function testConfiguresTheTimeoutsOfCurl(): void
    {
        $curl = $this->container(['http_client' => null, 'curl' => ['timeout' => 2.5, 'connect_timeout' => 0]])->get(CurlHttpClient::class);

        $timeouts = array_map(static fn (string $property) => (new \ReflectionProperty(CurlHttpClient::class, $property))->getValue($curl), ['readTimeout', 'connectTimeout', 'writeTimeout']);
        self::assertSame([2.5, 0.0, 0.0], $timeouts);
    }

    public function testRegistersTheMessageHandlers(): void
    {
        $container = $this->container();

        self::assertTrue($container->has(SyncDocumentsHandler::class));
        self::assertTrue($container->has(DeleteDocumentsHandler::class));
    }

    public function testRegistersTheDemoShopOnlyWithTwig(): void
    {
        self::assertFalse($this->container()->has(DemoController::class));

        $this->kernel?->shutdown();
        self::assertTrue($this->bootKernel(demo: true)->getContainer()->get('test.service_container')->has(DemoController::class));
    }

    public function testDisablesTheCache(): void
    {
        $presets = $this->container(['cache' => ['enabled' => false]])->get(PresetsService::class);
        $presets->getPresets();
        $presets->getPresets();

        self::assertCount(2, MockApi::$requests);
    }

    public function testRejectsAnInvalidWriteTimeout(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['write_timeout' => 0]);
    }

    public function testRegistersTheTwigFunctionsWithTwig(): void
    {
        $twig = $this->bootKernel(demo: true)->getContainer()->get('test.service_container')->get('twig');
        $html = $twig->createTemplate("{% for document in bi_search('laptop', {per_page: 1}) %}{{ document._PRODUCT.name }}{% endfor %}|{{ '<mark>a</mark><b>'|bi_highlight }}")->render();

        self::assertSame('Laptop|<mark>a</mark>&lt;b&gt;', $html);
        self::assertStringContainsString('/documents/browse?q=laptop', MockApi::$requests[0]['url']);
    }

    public function testRegistersTheExportSchedule(): void
    {
        $container = $this->container(['export' => ['schedule' => [
            ['cron' => '0 3 * * *'],
            ['cron' => '@hourly', 'collection' => 'ch', 'providers' => ['ProductProvider']],
        ]]]);

        $provider = $container->get(ExportScheduleProvider::class);
        self::assertInstanceOf(ExportScheduleProvider::class, $provider);
        self::assertCount(2, $provider->getSchedule()->getRecurringMessages());
        self::assertTrue($container->has(ExportMessageHandler::class));
    }

    public function testRegistersNoScheduleByDefault(): void
    {
        self::assertFalse($this->container()->has(ExportScheduleProvider::class));
    }

    public function testRejectsAScheduleOfAnUnknownCollection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown collection "at"');

        $this->container(['export' => ['schedule' => [['cron' => '@daily', 'collection' => 'at']]]]);
    }

    public function testDiffCommand(): void
    {
        $tester = $this->application();

        self::assertSame(1, $tester->run(['command' => 'bi:diff', '--format' => 'json']));
        // MockApi delivers PRODUCT-1, there are no providers
        self::assertSame(['PRODUCT-1'], json_decode($tester->getDisplay(), true)['orphaned']);
    }

    public function testReplacesTheApiWithTheInMemoryApi(): void
    {
        $container = $this->container(['in_memory_api' => true]);
        $container->get(InMemoryApi::class)->addDocuments('customer.test', [['id' => 'PRODUCT-5', 'type' => 'PRODUCT', '_PRODUCT' => ['name' => 'Tablet']]]);

        $response = $container->get(BrowseService::class)->browse(new BrowseSearchStruct());

        self::assertSame(1, $response->getFound());
        self::assertSame([], MockApi::$requests);
    }

    public function testMissingEnvironmentVariablesBreakOnlyTheApiCalls(): void
    {
        $kernel = $this->bootKernel(['collection' => '%env(BATTERYINCLUDED_TEST_MISSING)%']);
        $container = $kernel->getContainer()->get('test.service_container');

        // every request and command instantiates the DocumentChangeQueue as listener
        $response = $kernel->handle(Request::create('https://shop.test/unknown'));
        $kernel->terminate(Request::create('https://shop.test/unknown'), $response);
        self::assertInstanceOf(DocumentChangeQueue::class, $container->get(DocumentChangeQueue::class));

        // the profiler shows the missing variable instead of breaking the page
        // without WebProfilerBundle the collector is removed, it gets the registry like the bundle configures it
        $collector = new ApiDataCollector($container->get(TraceableHttpClient::class), static fn () => $container->get(CollectionRegistry::class));
        $collector->collect(Request::create('/'), $response);
        self::assertStringContainsString('BATTERYINCLUDED_TEST_MISSING', $collector->getCollections()['default']['collection']);

        $this->expectException(EnvNotFoundException::class);
        $container->get(CollectionRegistry::class)->getConfig('default');
    }
}
