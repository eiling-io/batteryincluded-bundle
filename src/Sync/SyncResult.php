<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

use BatteryIncludedSdk\Service\Response;

/**
 * Outcome of one or more import requests, the api answers with one result per document.
 */
class SyncResult
{
    private int $sent = 0;

    /**
     * @var list<array{id: string, error: string}>
     */
    private array $failed = [];

    /**
     * @var list<string>
     */
    private array $missing = [];

    private int $deleted = 0;

    public function addResponse(Response $response, int $sent): void
    {
        $this->sent += $sent;
        foreach ($response->getBody() as $result) {
            if (is_array($result) && ($result['success'] ?? true) === false) {
                $this->failed[] = [
                    'id' => (string) ($result['id'] ?? '?'),
                    'error' => is_scalar($result['error'] ?? null) ? (string) $result['error'] : (string) json_encode($result),
                ];
            }
        }
    }

    /**
     * @param list<string> $missing
     */
    public function setMissing(array $missing): void
    {
        $this->missing = $missing;
    }

    public function setDeleted(int $deleted): void
    {
        $this->deleted = $deleted;
    }

    public function getSent(): int
    {
        return $this->sent;
    }

    public function getSynced(): int
    {
        return $this->sent - count($this->failed);
    }

    /**
     * @return list<array{id: string, error: string}>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * Requested ids no provider delivered a document for.
     *
     * @return list<string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    public function getDeleted(): int
    {
        return $this->deleted;
    }

    public function isSuccessful(): bool
    {
        return $this->failed === [];
    }
}
