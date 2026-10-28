---
title: Testing your application
nav_order: 11
---

# Testing your application
{: .no_toc }

Test your search pages, exports and syncs without the real API.

1. TOC
{:toc}

## In-memory API

Replace the API in the test environment:

```yaml
# config/packages/batteryincluded.yaml
when@test:
    batteryincluded:
        in_memory_api: true
```

All SDK services then talk to the `InMemoryApi`. It keeps the documents per collection while the kernel lives and
answers the endpoints of the SDK:

| Endpoint | Behaviour |
|---|---|
| Import | Creates or replaces documents. Full imports and transactions replace the collection when they are committed |
| Update | Merges the fields into existing documents |
| Delete | Removes the ids, answers `num_deleted` |
| Browse, suggest | Finds documents by query (case-insensitive substring of the document) and exact filters, e.g. `type`, `id`, `_PRODUCT.brand` |
| Recommendations, highlights, presets | Empty answers |

It is no replacement of the API: there is no relevance, no facets, no ranges and the gateway does not resolve
translations. Logging, cache and profiler work as usual, only the transport is replaced.

## Assertions

Use the `BatteryIncludedAssertionsTrait` in a `KernelTestCase` or `WebTestCase`. Collections are given by their name
in the bundle configuration (`default`, `ch`, …):

```php
use Batteryincluded\BatteryincludedBundle\Sync\DocumentChangeQueue;
use Batteryincluded\BatteryincludedBundle\Test\BatteryIncludedAssertionsTrait;

final class ProductSyncTest extends KernelTestCase
{
    use BatteryIncludedAssertionsTrait;

    public function testSavingAProductSyncsIt(): void
    {
        self::bootKernel();
        $product = (new Product())->setName('Laptop');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($product);
        $entityManager->flush();
        // the changes are sent at kernel.terminate, a KernelTestCase has no request
        self::getContainer()->get(DocumentChangeQueue::class)->flush();

        self::assertBatteryIncludedDocumentExists('PRODUCT-' . $product->getId());
        self::assertBatteryIncludedRequest('POST', '/documents/import', 1);
    }
}
```

With Messenger the queue dispatches `SyncDocumentsMessage`s: route them to the `sync://` transport in the test
environment, otherwise they wait in the async transport.

| Method | Content |
|---|---|
| `assertBatteryIncludedDocumentExists($id, $collection = 'default')` | The collection contains the document |
| `assertBatteryIncludedDocumentNotExists($id, $collection = 'default')` | |
| `assertBatteryIncludedDocumentCount($count, $type = null, $collection = 'default')` | Number of documents, optionally of one type |
| `assertBatteryIncludedRequest($method, $endpoint, $count = null)` | e.g. `('DELETE', '/documents/delete')`, `null` means at least one |
| `addBatteryIncludedDocuments($documents, $collection = 'default')` | Fixtures: DTOs or arrays like the API returns them |
| `getBatteryIncludedDocuments($collection = 'default')` | All documents by id |
| `getBatteryIncludedApi()` | The `InMemoryApi` itself |

## Search pages

Add the documents as fixtures, the controller finds them with the usual SDK calls:

```php
public function testSearchPageListsTheProduct(): void
{
    $client = static::createClient();
    $product = new ProductBaseDto('1');
    $product->setName('Laptop Pro');
    self::addBatteryIncludedDocuments([$product]);

    $client->request('GET', '/search?q=laptop');

    self::assertSelectorTextContains('.product', 'Laptop Pro');
}
```

{: .note }
The `KernelBrowser` reboots the kernel before every further request, the documents are lost then. Add the fixtures
before the request or call `$client->disableReboot()`.

## Errors

Let the next request fail to test your error handling:

```php
self::getBatteryIncludedApi()->queueResponse(503, ['message' => 'maintenance']);
```

`getRequests()` returns every request with method, collection, endpoint, query and body, `reset()` clears documents
and requests.
