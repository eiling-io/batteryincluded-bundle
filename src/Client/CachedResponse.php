<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Service\Response;

/**
 * Response served by the CachingHttpClient, the profiler marks these calls as cached.
 */
class CachedResponse extends Response
{
}
