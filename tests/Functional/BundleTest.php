<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Demo\Controller\DemoController;
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsHandler;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsHandler;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use BatteryIncludedSdk\Presets\PresetsService;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
}
