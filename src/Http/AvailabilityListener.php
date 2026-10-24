<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stores the availability scope in the request attribute `_batteryincluded_availability`, after the LocaleListener
 * (priority 16) has set the locale. Controllers and the search struct arguments read it from there.
 */
class AvailabilityListener
{
    public function __construct(private readonly RequestAvailabilityResolver $resolver)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE)) {
            return;
        }

        $availability = $this->resolver->resolve($request);
        if ($availability !== null) {
            $request->attributes->set(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE, $availability);
        }
    }
}
