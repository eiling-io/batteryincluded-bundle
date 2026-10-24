---
title: Doctrine
nav_order: 6
---

# Doctrine
{: .no_toc }

1. TOC
{:toc}

Mark the entities whose changes update a document. The bundle listens to Doctrine and queues a sync or delete after
every successful flush, at the end of the request all changes are sent at once.

{: .note }
The integration needs `doctrine/orm` and the DoctrineBundle, it is enabled automatically. Disable it with
`batteryincluded.doctrine.enabled: false`.

## The attribute

```php
use Batteryincluded\BatteryincludedBundle\Doctrine\Attribute\BatteryIncludedDocument;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[BatteryIncludedDocument('PRODUCT', deleteMissing: true)]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
}
```

Saving product 5 syncs the document `PRODUCT-5`, removing it deletes the document. The document itself comes from
your [`PartialDataProviderInterface`](export#optional-interfaces), the attribute only tells which ids changed. So a
product the provider does not deliver anymore (e.g. deactivated) is deleted with `deleteMissing: true`.

| Option | Default | Meaning |
|---|---|---|
| `type` | – | Document type, e.g. `PRODUCT`, the document id is `<TYPE>-<identifier>` |
| `identifier` | Doctrine id | Method, getter (`sku` → `getSku()`) or property delivering the identifier. `null` skips the entity. Composite ids need it. |
| `collection` | `default` | Name of the collection in the [configuration](configuration#several-collections) |
| `deleteOnRemove` | `true` | Removing the entity deletes the document, otherwise the document is synced |
| `deleteMissing` | `false` | Deletes the document if the providers do not deliver it anymore |

{: .important }
Enable `deleteMissing` only if a `PartialDataProviderInterface` handles the type, otherwise every change deletes the
document.

## Related entities

The attribute is repeatable and inherited by child classes. A variant updates its own document and the one of its
product, removing the variant only updates the product:

```php
#[ORM\Entity]
#[BatteryIncludedDocument('VARIANT', identifier: 'sku', collection: 'ch')]
#[BatteryIncludedDocument('PRODUCT', identifier: 'getProductId', deleteOnRemove: false)]
class Variant
{
    public function getProductId(): ?int
    {
        return $this->product?->getId();
    }
}
```

For relations the listener cannot see (e.g. a price list changing many products) schedule the documents yourself
with the [`DocumentChangeQueue`](single-documents#documentchangequeue).

## When documents are sent

1. `persist()`, changes and `remove()` are collected during the flush. The identifier of a removed entity is read
   before Doctrine resets it.
2. After a **successful** flush the changes are handed to the `DocumentChangeQueue`. A failed or rolled back flush
   and `clear()` discard them.
3. At the end of the request (`kernel.terminate`, after the response was sent), the command (`console.terminate`) or
   the message (Messenger worker) the queue sends all changes: as `SyncDocumentsMessage` / `DeleteDocumentsMessage`
   with symfony/messenger, otherwise directly.

Several flushes of one request lead to one API call per collection and action. Errors are logged to the
`batteryincluded` channel and never break the response.

{: .note }
Route the [messages](single-documents#messenger) to an async transport in production. Without Messenger the API calls
run in `kernel.terminate`, which only happens after the response with PHP-FPM.
