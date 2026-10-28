---
title: Events & extension points
nav_order: 7
---

# Events & extension points
{: .no_toc }

1. TOC
{:toc}

## Events

The bundle dispatches four events with the Symfony event dispatcher. The class name is the event name, listen to
them with `#[AsEventListener]`:

| Event | Dispatched by | Use it to |
|---|---|---|
| [`PreExportEvent`](#preexportevent) | `bi:export`, before the first document is loaded | Cancel the export |
| [`DocumentsEvent`](#documentsevent) | `bi:export`, `bi:sync`, `DocumentSynchronizer`, Doctrine integration, message handlers – for every batch | Enrich, replace or remove documents |
| [`PostExportEvent`](#postexportevent) | `bi:export`, after it finished, failed or was cancelled | Clear caches, notify, monitor |
| [`SuggestEvent`](#suggestevent) | `Suggester`: suggest endpoint and demo shop | Translate group labels, add or change fields |

All four live in the namespace `Batteryincluded\BatteryincludedBundle\Event`.

### PreExportEvent

| Member | Type | Content |
|---|---|---|
| `collection` | `string` | Name of the collection, e.g. `default` |
| `providers` | `list<DataProviderInterface>` | Providers which will be exported |
| `fullImport` | `bool` | `false` for `--provider`, nothing is deleted then |
| `dryRun` | `bool` | `--dry-run` |
| `cancel(string $reason)` | | Stops the export, nothing is sent, the command exits with `1` |
| `isCancelled()`, `getCancelReason()` | | |

```php
use Batteryincluded\BatteryincludedBundle\Event\PreExportEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class SkipExportDuringImport
{
    public function __construct(private ErpImportState $erpImport)
    {
    }

    public function __invoke(PreExportEvent $event): void
    {
        if ($event->fullImport && !$event->dryRun && $this->erpImport->isRunning()) {
            $event->cancel('the ERP import is running, the catalog is incomplete');
        }
    }
}
```

### DocumentsEvent

Dispatched for every batch before it is sent, the documents can be changed.

| Member | Type | Content |
|---|---|---|
| `getDocuments()` | `list<AbstractDto>` | Documents of the batch |
| `setDocuments(array $documents)` | | Replaces the documents |
| `collection` | `string` | Name of the collection |
| `context` | `string` | `DocumentsEvent::CONTEXT_EXPORT` (`bi:export`) or `DocumentsEvent::CONTEXT_SYNC` (everything else) |
| `provider` | `?DataProviderInterface` | Provider of the batch during an export, `null` for a sync |

Removed documents are not sent. In a full import they are therefore **deleted** from the collection, a sync keeps the
version in the collection. A batch without documents is skipped.

```php
use Batteryincluded\BatteryincludedBundle\Event\DocumentsEvent;
use BatteryIncludedSdk\Dto\ProductBaseDto;

#[AsEventListener]
final class AddLivePrices
{
    public function __construct(private PriceApi $prices)
    {
    }

    public function __invoke(DocumentsEvent $event): void
    {
        $products = array_filter($event->getDocuments(), static fn ($document) => $document instanceof ProductBaseDto);
        // one request for the whole batch instead of one per product
        $prices = $this->prices->forProducts(array_map(static fn (ProductBaseDto $product) => $product->getOrdernumber(), $products));

        foreach ($products as $product) {
            $product->setPrice($prices[$product->getOrdernumber()] ?? $product->getPrice());
        }
    }
}
```

### PostExportEvent

Dispatched when `bi:export` ends after it started exporting: on success, on errors and when a `PreExportEvent`
listener cancelled it. Invalid options, a declined confirmation and a running export end the command before, without
event.

| Member | Type | Content |
|---|---|---|
| `collection` | `string` | Name of the collection |
| `fullImport` | `bool` | |
| `dryRun` | `bool` | |
| `successful` | `bool` | Everything was sent (and committed) without rejected documents |
| `documents` | `int` | Exported (or serialized) documents |
| `duration` | `float` | Seconds |
| `result` | `SyncResult` | Sent, synced and rejected documents |
| `error` | `?string` | Reason of the failure |
| `report` | `?ExportReport` | Documents per provider and type, the [report](export#report) of `--report`. `toArray()`, `getTypes()`, `getDocuments()` |

```php
use Batteryincluded\BatteryincludedBundle\Event\PostExportEvent;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

#[AsEventListener]
final class ReportExport
{
    public function __construct(private NotifierInterface $notifier)
    {
    }

    public function __invoke(PostExportEvent $event): void
    {
        if (!$event->dryRun && !$event->successful) {
            $this->notifier->send(new Notification('BatteryIncluded export failed: ' . $event->error, ['chat/slack']));
        }
    }
}
```

`bi:diff` dispatches the `DocumentsEvent` with the context `export` as well, it compares what the export would send.

### SuggestEvent

Dispatched by the `Suggester` before the grouped suggestions are returned, see
[Suggestions & Twig](frontend#suggestevent).

| Member | Type | Content |
|---|---|---|
| `getResult()`, `setResult()` | `array` | `query`, `completions`, `groups` with `type`, `label` and `items`, `found` |
| `collection` | `string` | Name of the collection |
| `locale` | `string` | Requested locale |
| `availability` | `?string` | Availability scope |

## Symfony events the bundle listens to

| Event | Listener | What it does |
|---|---|---|
| `kernel.request` (priority 15) | `AvailabilityListener` | Stores the [availability scope](search#availability) in `_batteryincluded_availability` |
| `kernel.terminate`, `console.terminate` | `DocumentChangeQueue::flush()` | Sends the queued document changes |
| `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` | `DocumentChangeQueue::flush()` | Same for Messenger workers |
| Doctrine `preFlush`, `postPersist`, `postUpdate`, `preRemove`, `postRemove`, `postFlush`, `onClear` | `DocumentChangeListener` | [Doctrine integration](doctrine) |
| `kernel.reset` | `TraceableHttpClient`, `DocumentChangeQueue` | Clears the profiler log, flushes the queue |

## Interfaces

| Interface | Implement it to |
|---|---|
| `Provider\DataProviderInterface` | Deliver documents to `bi:export`, tagged automatically |
| `Provider\PartialDataProviderInterface` | Deliver single documents to `bi:sync`, Doctrine and Messenger |
| `Provider\CollectionAwareDataProviderInterface` | Deliver only to some collections |
| `Document\DocumentUrlResolverInterface` | Link documents in the suggestions and `bi_document_url()`, see [Suggestions & Twig](frontend#2-link-the-documents) |

See [Export](export#data-providers).

## Attributes

| Attribute | Target | See |
|---|---|---|
| `#[Doctrine\Attribute\BatteryIncludedDocument]` | Entity class, repeatable | [Doctrine](doctrine) |
| `#[Http\Attribute\MapSearchStruct]` | Controller argument | [Search in controllers](search) |

## Messages

| Message | Handler |
|---|---|
| `Messenger\SyncDocumentsMessage` | Creates or replaces documents |
| `Messenger\DeleteDocumentsMessage` | Deletes documents |
| `Scheduler\ExportMessage` | Runs `bi:export --force`, used by the [scheduler](export#scheduler) |

See [Messenger](single-documents#messenger).

## Services

| Service | Use it to |
|---|---|
| SDK services (`BrowseService`, `SyncService`, …) | Talk to the default collection, `$<name><Service>` for further collections |
| `Collection\CollectionRegistry` | Get the SDK services, configuration and providers of a collection by name |
| `Sync\DocumentSynchronizer` | Sync and delete documents now |
| `Sync\DocumentChangeQueue` | Sync and delete documents at the end of the request |
| `Http\RequestAvailabilityResolver` | Resolve the availability scope of a request |
| `Http\RequestFilterParser` | Read `f[...]` filters of a request |
| `Suggest\Suggester` | Suggestions grouped by type for an own controller |
| `Sync\CollectionComparator` | Compare providers and collection like `bi:diff`, returns a `DiffResult` |
| `Document\DocumentUrlGenerator` | Url of a document by the `DocumentUrlResolverInterface` services |
| `Test\InMemoryApi` | API in memory for your tests, see [Testing](testing) |

All class names are relative to `Batteryincluded\BatteryincludedBundle`.

## Request attribute

| Attribute | Content |
|---|---|
| `_batteryincluded_availability` | Availability scope of the request, set by the `AvailabilityListener` or by your own listener (priority above 15) |

## Exceptions

| Exception | Thrown when |
|---|---|
| `Exception\ApiException` | The API answers with an error status, `getResponse()` returns the response |
| `InvalidArgumentException` | Unknown collection name |
| `LogicException` | Single documents are requested but no provider implements `PartialDataProviderInterface` |
