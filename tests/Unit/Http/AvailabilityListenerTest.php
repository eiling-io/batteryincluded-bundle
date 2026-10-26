<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Unit\Http;

use Batteryincluded\BatteryincludedBundle\Http\AvailabilityListener;
use Batteryincluded\BatteryincludedBundle\Http\RequestAvailabilityResolver;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class AvailabilityListenerTest extends TestCase
{
    public function testStoresTheResolvedScope(): void
    {
        $request = Request::create('https://shop.ch/');

        $this->listen($request);

        self::assertSame('ch', $request->attributes->get(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE));
    }

    public function testKeepsAScopeSetByTheApplication(): void
    {
        $request = Request::create('https://shop.ch/');
        $request->attributes->set(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE, 'at');

        $this->listen($request);

        self::assertSame('at', $request->attributes->get(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE));
    }

    public function testSetsNothingWithoutScope(): void
    {
        $request = Request::create('https://shop.de/');

        $this->listen($request);

        self::assertFalse($request->attributes->has(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE));
    }

    private function listen(Request $request): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener = new AvailabilityListener(new RequestAvailabilityResolver(['shop.ch' => 'ch']));

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
}
