---
title: Single documents
nav_order: 5
---

# Single documents
{: .no_toc }

1. TOC
{:toc}

A full import replaces the whole collection. When a single product changes, create, replace or delete only its
document. The documents are loaded from the providers implementing
[`PartialDataProviderInterface`](export#optional-interfaces).

## Console

```bash
bin/console bi:sync PRODUCT-5 BLOG-3              # creates or replaces the documents
bin/console bi:sync PRODUCT-5 --dry-run           # prints the documents as JSON
bin/console bi:sync PRODUCT-5 --delete-missing    # deletes the id if no provider delivers it
bin/console bi:delete PRODUCT-5 --force           # deletes the documents
```

Ids without document are reported. With `--delete-missing` they are deleted, e.g. a product that was deactivated.

## DocumentSynchronizer

The service behind the commands, inject it into your own code:

```php
use Batteryincluded\BatteryincludedBundle\Sync\DocumentSynchronizer;

$result = $synchronizer->syncByIds(['PRODUCT-5', 'PRODUCT-6'], deleteMissing: true);
$result->getSynced();     // documents accepted by the api
$result->getFailed();     // [['id' => 'PRODUCT-6', 'error' => '...']]
$result->getMissing();    // ids without document
$result->getDeleted();    // deleted missing documents

$synchronizer->sync($documents);                 // DTOs you built yourself
$synchronizer->delete(['PRODUCT-7'], 'ch');      // collection "ch"
$synchronizer->findDocuments(['PRODUCT-5']);     // ['documents' => [...], 'missing' => [...]]
```

| Method | Throws |
|---|---|
| `syncByIds()`, `sync()`, `delete()` | `ApiException` on an error status, `getResponse()` returns the response |
| `findDocuments()`, `syncByIds()` | `LogicException` if no provider of the collection implements `PartialDataProviderInterface` |
| all | `InvalidArgumentException` for an unknown collection |

Documents are sent in batches of `export.batch_size`, each batch passes the [`DocumentsEvent`](events#documentsevent)
with the context `sync`.

## DocumentChangeQueue

Collects changes and sends them once at the end of the request, the console command or the message, after the
response has been sent. The [Doctrine integration](doctrine) uses it, call it from your own code as well:

```php
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;

$queue->scheduleSync('PRODUCT-5');
$queue->scheduleSync('PRODUCT-6', 'ch', deleteMissing: true);
$queue->scheduleDelete('PRODUCT-7');
```

The last change of an id wins. On flush the changes are grouped per collection and action and sent as messages if
symfony/messenger is installed, otherwise directly with the `DocumentSynchronizer`. Errors are logged, they never
break the response. Long running processes (Messenger workers, FrankenPHP) flush when the services are reset.

## Messenger

With symfony/messenger installed the bundle registers handlers for two messages. Route them to an async transport,
so a request never waits for the API:

```php
use Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage;
use Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage;

$bus->dispatch(new SyncDocumentsMessage(['PRODUCT-5'], collection: 'default', deleteMissing: true));
$bus->dispatch(new DeleteDocumentsMessage(['PRODUCT-6']));
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage': async
            'Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage': async
```

| Message | Properties | Handler |
|---|---|---|
| `SyncDocumentsMessage` | `ids`, `collection = 'default'`, `deleteMissing = false` | `SyncDocumentsHandler` → `DocumentSynchronizer::syncByIds()` |
| `DeleteDocumentsMessage` | `ids`, `collection = 'default'` | `DeleteDocumentsHandler` → `DocumentSynchronizer::delete()` |

API errors (`ApiException`) and network errors (`NetworkException` of the SDK) are thrown and retried by the
transport. Rejected documents and documents that cannot be encoded (`InvalidDocumentException`, e.g. a price of `NAN`)
throw an `UnrecoverableMessageHandlingException`, retrying them would fail again.

{: .important }
Use `deleteMissing` only if the providers of all affected document types implement `PartialDataProviderInterface`.
Otherwise every document of a type without such a provider counts as missing and is deleted.
