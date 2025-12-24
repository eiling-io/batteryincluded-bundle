<?php
declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class BatteryincludedDataCollector extends DataCollector
{
    public function collect(Request $request, Response $response, ?\Throwable $exception = null)
    {
        // TODO: Implement collect() method.
    }

    public function getName()
    {
        return 'batteryincluded.profiler.datacollector';
    }
}
