<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Messenger;

use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DeleteDocumentsHandler
{
    public function __construct(private readonly DocumentSynchronizer $synchronizer)
    {
    }

    public function __invoke(DeleteDocumentsMessage $message): void
    {
        $this->synchronizer->delete($message->ids, $message->collection);
    }
}
