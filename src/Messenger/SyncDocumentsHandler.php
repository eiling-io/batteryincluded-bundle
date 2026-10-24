<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Messenger;

use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class SyncDocumentsHandler
{
    public function __construct(private readonly DocumentSynchronizer $synchronizer)
    {
    }

    /**
     * Api errors are thrown and retried by the transport, rejected documents are not retried.
     */
    public function __invoke(SyncDocumentsMessage $message): void
    {
        $result = $this->synchronizer->syncByIds($message->ids, $message->collection, $message->deleteMissing);

        if (!$result->isSuccessful()) {
            throw new UnrecoverableMessageHandlingException('BatteryIncluded rejected documents: ' . implode(', ', array_map(static fn (array $failure) => $failure['id'] . ' (' . $failure['error'] . ')', $result->getFailed())));
        }
    }
}
