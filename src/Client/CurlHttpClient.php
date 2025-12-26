<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Client;

use BatteryIncludedSdk\Service\Response;
use BatteryIncludedSdk\Client\HttpClientInterface;

class CurlHttpClient implements HttpClientInterface
{
    private array $log;

    public function send(string $url, string $method, array $header, string $data): Response
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported method: ' . $method);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        $this->log[] = [
            'url' => $url,
            'method' => $method,
            'headers' => $header,
            'data' => $data,
            'response' => $response,
        ];

        return new Response($response);
    }

    public function getLoggedRequests(): array
    {
        return $this->log ?? [];
    }
}
