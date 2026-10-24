<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Sync;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects document changes (e.g. of the Doctrine listener) and sends them once at the end of the request, the command
 * or the message: as SyncDocumentsMessage / DeleteDocumentsMessage if symfony/messenger is installed, otherwise
 * directly with the DocumentSynchronizer. Errors are logged, the response has been sent already.
 */
class DocumentChangeQueue implements ResetInterface
{
    private const SYNC = 'sync';

    private const DELETE = 'delete';

    /**
     * Last change per collection and document id, a later change replaces an earlier one.
     *
     * @var array<string, array<string, array{action: string, deleteMissing: bool}>>
     */
    private array $changes = [];

    public function __construct(
        private readonly DocumentSynchronizer $synchronizer,
        private readonly ?MessageBusInterface $messageBus = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param string $id document id, e.g. `PRODUCT-5`
     */
    public function scheduleSync(string $id, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, bool $deleteMissing = false): void
    {
        $this->changes[$collection][$id] = ['action' => self::SYNC, 'deleteMissing' => $deleteMissing];
    }

    /**
     * @param string $id document id, e.g. `PRODUCT-5`
     */
    public function scheduleDelete(string $id, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION): void
    {
        $this->changes[$collection][$id] = ['action' => self::DELETE, 'deleteMissing' => false];
    }

    /**
     * @return array<string, array<string, array{action: string, deleteMissing: bool}>>
     */
    public function getChanges(): array
    {
        return $this->changes;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function flush(): void
    {
        $changes = $this->changes;
        $this->changes = [];

        foreach ($changes as $collection => $documents) {
            $groups = [];
            foreach ($documents as $id => $change) {
                $groups[$change['action'] . '|' . ($change['deleteMissing'] ? '1' : '0')][] = (string) $id;
            }

            foreach ($groups as $group => $ids) {
                [$action, $deleteMissing] = explode('|', $group);
                try {
                    $this->send($action, $ids, (string) $collection, $deleteMissing === '1');
                } catch (\Throwable $exception) {
                    $this->logger?->error('BatteryIncluded: {action} of {ids} failed: {error}', [
                        'action' => $action,
                        'ids' => implode(', ', $ids),
                        'collection' => $collection,
                        'error' => $exception->getMessage(),
                        'exception' => $exception,
                    ]);
                }
            }
        }
    }

    /**
     * Long running processes (workers, FrankenPHP) reset the services between requests, nothing gets lost there.
     */
    public function reset(): void
    {
        $this->flush();
    }

    /**
     * @param list<string> $ids
     */
    private function send(string $action, array $ids, string $collection, bool $deleteMissing): void
    {
        if ($this->messageBus !== null) {
            $this->messageBus->dispatch($action === self::DELETE ? new DeleteDocumentsMessage($ids, $collection) : new SyncDocumentsMessage($ids, $collection, $deleteMissing));

            return;
        }

        if ($action === self::DELETE) {
            $this->synchronizer->delete($ids, $collection);

            return;
        }

        $result = $this->synchronizer->syncByIds($ids, $collection, $deleteMissing);
        if (!$result->isSuccessful()) {
            $this->logger?->error('BatteryIncluded rejected documents: {failed}', ['failed' => json_encode($result->getFailed()), 'collection' => $collection]);
        }
    }
}
