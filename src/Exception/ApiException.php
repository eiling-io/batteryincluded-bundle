<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Exception;

use BatteryIncludedSdk\Service\Response;

/**
 * The api answered with an error status or rejected documents.
 */
class ApiException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?Response $response = null)
    {
        parent::__construct($message);
    }

    public static function assertSuccessful(Response $response, string $action): Response
    {
        if ($response->getStatusCode() >= 400) {
            throw new self(sprintf('%s failed with status %d: %s', $action, $response->getStatusCode(), mb_substr($response->getRawResponse(), 0, 1000)), $response);
        }

        return $response;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }
}
