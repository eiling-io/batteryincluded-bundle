<?php
declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Profiler;

use Batteryincluded\BatteryincludedBundle\Client\CurlHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

class ApiDataCollector extends DataCollector
{
    public function __construct(private CurlHttpClient $httpClient)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'requests' => $this->httpClient->getLoggedRequests(),
            'responses' => $this->httpClient->getLoggedResponses(),

        ];
    }

    public function getMethod(): string
    {
        return $this->data['method'];
    }

    public function getRequests()
    {
        return $this->data['requests'];
    }

    public function getResponses()
    {
        return $this->data['responses'];
    }

    public function getData(): Data
    {
        return  $this->cloneVar($this->data);
    }

    public function getName(): string
    {
        return self::class;
    }
}
