<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestKernel;
use BatteryIncludedSdk\Presets\PresetsService;
use BatteryIncludedSdk\Shop\BrowseService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

class BundleTest extends TestCase
{
    private ?TestKernel $kernel = null;

    /**
     * @var callable|null
     */
    private $exceptionHandler;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/batteryincluded-bundle-tests');
    }

    protected function setUp(): void
    {
        MockApi::$requests = [];
        $this->exceptionHandler = $this->currentExceptionHandler();
    }

    /**
     * Kernel and console register the exception handler of the ErrorHandler component, phpunit expects the old one.
     */
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        for ($i = 0; $i < 10 && $this->currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function application(): ApplicationTester
    {
        $this->kernel = new TestKernel();
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

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
        $this->kernel = new TestKernel();
        $response = $this->kernel->handle(Request::create('https://shop.ch/search?q=laptop&per_page=5'));

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

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = []): ContainerInterface
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();

        return $this->kernel->getContainer()->get('test.service_container');
    }
}
