---
title: Export
nav_order: 4
---

# Export
{: .no_toc }

1. TOC
{:toc}

## Data providers

A data provider delivers the documents of one kind, e.g. products or blog posts, as DTOs of the SDK. Every service
implementing `DataProviderInterface` is tagged automatically.

```php
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use BatteryIncludedSdk\Dto\ProductBaseDto;

final class ProductProvider implements DataProviderInterface
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function getBatches(int $batchSize): \Generator
    {
        // load and yield page by page, the export never needs the whole catalog in memory
        foreach ($this->products->iterateActive($batchSize) as $products) {
            yield array_map($this->toDto(...), $products);
        }
    }

    private function toDto(Product $product): ProductBaseDto
    {
        // exported as document id PRODUCT-<id>
        $dto = new ProductBaseDto((string) $product->getId());
        $dto->setName($product->getName());
        $dto->setPrice($product->getPrice());

        return $dto;
    }
}
```

The [SDK documentation](https://eiling-io.github.io/batteryincluded-php-sdk/syncing) describes the DTOs, translations
and availabilities.

### Own document types

Content pages, FAQ entries or stores are documents of their own type. Extend `AbstractTranslatableDto` (or
`AbstractDto` without translations), the type becomes the document id prefix and the root nodes `_<TYPE>` and
`_<TYPE>_i18n`:

```php
use BatteryIncludedSdk\Dto\AbstractTranslatableDto;

final class ContentPageDto extends AbstractTranslatableDto
{
    private ?string $title = null;

    public function __construct(string $identifier)
    {
        parent::__construct($identifier, 'CONTENT');   // document id CONTENT-<identifier>
    }

    // only these fields may be translated, null allows every field
    protected function getTranslatableFields(): ?array
    {
        return ['title'];
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function jsonSerialize(): array
    {
        $json = parent::jsonSerialize();
        $json['_' . $this->getType()] = $this->filterJsonValues(['title' => $this->title]);

        return $json;
    }
}

$page = (new ContentPageDto('shipping'))
    ->setTitle('Versand & Lieferung')
    ->addTranslation('en', 'title', 'Shipping & delivery');
// {"id": "CONTENT-shipping", "type": "CONTENT", "_CONTENT_i18n": {"en": {"title": "Shipping & delivery"}}, "_CONTENT": {"title": "Versand & Lieferung"}}
```

Configure the new fields in the schema of the collection in the BatteryIncluded backend (searchable, filterable,
facets) and select the fields the suggestions should show under Suggest. The [test application](test-application) contains a complete example with six content pages in German and
English (`App\Dto\ContentPageDto`, `App\Provider\ContentPageProvider`).

### Optional interfaces

| Interface | Method | Effect |
|---|---|---|
| `PartialDataProviderInterface` | `getByIds(array $ids): iterable` | Delivers single documents for `bi:sync`, the [Doctrine integration](doctrine) and the [messages](single-documents#messenger) |
| `CollectionAwareDataProviderInterface` | `supportsCollection(string $collection): bool` | Restricts the provider to some [collections](configuration#several-collections), all others deliver to every collection |

```php
use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;

final class ProductProvider implements PartialDataProviderInterface
{
    // ids are document ids like PRODUCT-5, ignore the ids of other types
    public function getByIds(array $ids): iterable
    {
        $productIds = array_map(
            static fn (string $id) => (int) mb_substr($id, 8),
            array_filter($ids, static fn (string $id) => str_starts_with($id, 'PRODUCT-')),
        );

        return array_map($this->toDto(...), $this->products->findActiveByIds($productIds));
    }
}
```

## Full import

```bash
bin/console bi:export --dry-run   # loads and serializes everything, nothing is sent
bin/console bi:export             # asks for confirmation
bin/console bi:export --force     # cron jobs, deployments, CI
```

{: .warning }
A full import sends all documents in one transaction. When it is committed, **all other documents of the collection
are deleted**. Test it against a dedicated test collection.

The command sends the batches of all providers with the same transaction id and commits the transaction with the last
batch. It protects the collection:

| Situation | Behaviour |
|---|---|
| The API answers with an error status | Stops, the transaction is not committed, the collection is unchanged |
| The API rejects documents | Stops before the commit and lists the rejected documents |
| The providers deliver no document | Stops, an empty commit would empty the collection |
| No interaction and no `--force` | Stops, nothing is sent |
| Another export into the same collection is running | Stops, see [lock](#lock) |
| A `PreExportEvent` listener cancels | Stops before the first document is loaded |
| The commit takes longer than [`write_timeout`](configuration#symfony-http-client) | Stops with `Idle timeout reached`, the API may have committed anyway, check with `bi:status` |

At the end a table shows documents, batches and time per provider. With `-v` every API response is printed instead
of the progress bar.

## Partial export

```bash
bin/console bi:export --provider=ProductProvider
```

With `--provider` (class or short class name, repeatable) the documents of these providers are created or replaced
without transaction, **nothing is deleted**. Documents the provider no longer delivers stay in the collection, use
`bi:delete` or a full import to remove them.

## Dry run

`--dry-run` loads every batch, dispatches the [`DocumentsEvent`](events#documentsevent) and serializes each document
like the export would. It reports serialization errors (e.g. invalid UTF-8) and document ids delivered more than
once. Nothing is sent and no lock is taken.

## Lock

The export takes a lock per collection (`batteryincluded-export-<name>`, one hour). It uses the `lock.factory` of the
application if symfony/lock is configured, otherwise a file lock, so cron jobs on one server never overlap. Configure
a shared store (e.g. Redis) if exports can start on several servers.

## Options

| Option | Default | Meaning |
|---|---|---|
| `--collection`, `-c` | `default` | Name of the collection in the bundle configuration |
| `--provider`, `-p` | all | Export only these providers, create or replace, nothing is deleted |
| `--batch-size`, `-b` | `export.batch_size` | Documents per request |
| `--dry-run` | | Load and serialize only |
| `--force`, `-f` | | Skip the confirmation, required without interaction |
| `-v` | | Print every API response |

Exit codes: `0` success, `1` failure, `2` invalid options.

## Events

`bi:export` dispatches the [`PreExportEvent`](events#preexportevent), a [`DocumentsEvent`](events#documentsevent) per
batch and the [`PostExportEvent`](events#postexportevent): cancel the export, enrich or filter documents, react on
the result.
