<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Functional;

use Batteryincluded\BatteryincludedBundleTests\Fixtures\MockApi;
use Batteryincluded\BatteryincludedBundleTests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots the TestKernel, the api is answered by MockApi.
 */
abstract class FunctionalTestCase extends TestCase
{
    protected ?TestKernel $kernel = null;

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

    /**
     * @param array<string, mixed> $config merged into the bundle configuration of the TestKernel
     */
    protected function bootKernel(array $config = [], bool $demo = false): TestKernel
    {
        $this->kernel = new TestKernel($config, $demo);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function container(array $config = []): ContainerInterface
    {
        return $this->bootKernel($config)->getContainer()->get('test.service_container');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function application(array $config = []): ApplicationTester
    {
        $application = new Application($this->bootKernel($config));
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
