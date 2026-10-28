<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal application with the bundle, the api is answered by MockApi.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig merged into the default configuration of the tests
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly bool $demo = false,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        // the demo shop is registered with the TwigBundle only
        if ($this->demo) {
            yield new TwigBundle();
        }
        yield new BatteryincludedBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/batteryincluded-bundle-tests/' . md5((string) json_encode([$this->bundleConfig, $this->demo]));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'cache' => ['app' => 'cache.adapter.array'],
            'http_client' => ['mock_response_factory' => MockApi::class],
        ]);

        $container->extension('batteryincluded', array_replace_recursive([
            'base_url' => 'https://api.test/collections/',
            'collection' => 'customer.test',
            'api_key' => 'secret-default',
            'collections' => ['ch' => ['collection' => 'customer.test-ch', 'api_key' => 'secret-ch']],
            'http_client' => 'http_client',
            'cache' => ['enabled' => true],
            'availability' => ['hosts' => ['shop.ch' => 'ch']],
        ], $this->bundleConfig));

        $services = $container->services();
        $services->set(MockApi::class);
        $services->set('logger', NullLogger::class);
        $services->set(TestController::class)->autowire()->tag('controller.service_arguments')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('search', '/search')->controller(TestController::class);
        $routes->import(dirname(__DIR__, 2) . '/config/routes/suggest.yaml');
        if ($this->demo) {
            $routes->import(dirname(__DIR__, 2) . '/config/routes/demo.yaml')->prefix('/demo');
        }
    }
}
