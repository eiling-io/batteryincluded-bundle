# BatteryIncluded Bundle

Symfony integration of the [BatteryIncluded PHP SDK](https://github.com/eiling-io/batteryincluded-php-sdk).

**Documentation: https://eiling-io.github.io/batteryincluded-bundle/** – configuration, export, Doctrine, events and
extension points, search, console commands and the profiler.

## Configuration

The bundle works without configuration file, it reads the credentials from the environment:

```dotenv
BATTERYINCLUDED_BASE_URL=https://api.batteryincluded.io/api/v1/collections/   # optional, this is the default
BATTERYINCLUDED_COLLECTION=customer.my-shop
BATTERYINCLUDED_API_KEY=your-api-key
```

All settings with their defaults (`bin/console config:dump-reference batteryincluded`):

```yaml
# config/packages/batteryincluded.yaml
batteryincluded:
    base_url: '%env(BATTERYINCLUDED_BASE_URL)%'   # falls back to https://api.batteryincluded.io/api/v1/collections/
    collection: '%env(BATTERYINCLUDED_COLLECTION)%'
    api_key: '%env(BATTERYINCLUDED_API_KEY)%'

    # further collections, e.g. one per sales channel
    collections:
        ch:
            collection: '%env(BATTERYINCLUDED_COLLECTION_CH)%'
            api_key: '%env(BATTERYINCLUDED_API_KEY_CH)%'
            base_url: ~                              # defaults to base_url

    http_client: ~             # service id of a Symfony http client, null uses curl
    write_timeout: 300         # seconds, import, update and delete calls with the Symfony http client
    cache:
        enabled: false
        pool: cache.app
        ttl: 300               # seconds
        endpoints: ['/documents/presets', '/documents/highlights', '/documents/recommendations']
    logging:
        enabled: true
        slow_threshold: 1000   # ms, 0 disables the warning
    export:
        batch_size: 500
    search:
        per_page: 24           # defaults of the BrowseSearchStruct controller argument
        max_per_page: 100
    availability:              # prices and stock per request, see "Availability"
        hosts: {}              # e.g. 'shop.ch': ch, '*.shop.at': at
        locales: {}            # e.g. de_CH: ch, de: de
        default: ~
    doctrine:
        enabled: true          # only active with the DoctrineBundle
```

The services of the sdk (`BrowseService`, `SuggestService`, `SyncService`, …) can be autowired and use the default
collection. The services of further collections are injected by argument name, `<name><Service>`:

```php
public function __construct(
    private BrowseService $browseService,     // default collection
    private BrowseService $chBrowseService,   // collection "ch"
) {
}
```

`CollectionRegistry::get('ch', BrowseService::class)` returns them by name as well.

### Symfony http client

With `http_client` the api calls are sent by a Symfony http client instead of curl: timeouts, retries and proxies are
configured like for every other client, tests can replace it with the `MockHttpClient`, and the calls appear in the
HttpClient panel of the profiler. Import, update and delete calls use `write_timeout` instead of the `timeout` of the
client: the api answers the commit of a full import only after indexing, which takes longer than a search.

```yaml
batteryincluded:
    http_client: batteryincluded.http_client

framework:
    http_client:
        scoped_clients:
            batteryincluded.http_client:
                scope: 'https://api\.batteryincluded\.io/'
                timeout: 10
                retry_failed:
                    max_retries: 2
```

### Cache

`cache.enabled` caches successful GET responses of the listed endpoints in the pool for `ttl` seconds. Searches and
suggestions are never cached unless you add their endpoint.

### Logging

Failed calls (error status or connection error) are logged as `error`, calls slower than `slow_threshold` as
`warning` and every call as `debug`, all in the `batteryincluded` channel. Headers are not logged, they contain the api
key.

## Export

Services implementing `Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface` deliver the documents,
they are tagged automatically.

```bash
bin/console bi:export --dry-run          # loads and serializes all documents, nothing is sent
bin/console bi:export                    # full import, asks for confirmation
bin/console bi:export --force            # full import without confirmation (cron, CI)
bin/console bi:export -p ProductProvider # creates or replaces the documents of one provider, nothing is deleted
bin/console bi:export -c ch -b 1000 -v   # collection "ch", 1000 documents per request, prints every api response
```

> **Warning:** the full import sends all documents in one transaction. When it is committed, **all other documents of
> the collection are deleted**.

The command shows the progress and a summary per provider. It stops before the commit as soon as the api answers with
an error or rejects documents, the collection is then unchanged. It never commits an empty transaction, and a lock
(the `lock.factory` of the application, otherwise a file lock) prevents two exports into the same collection at once.

Providers implementing `CollectionAwareDataProviderInterface` deliver only to the collections they support, all other
providers deliver to every collection.

The document id is the type plus the identifier from the constructor of the DTO (`PRODUCT-5`), exported as `id` in
the document root. Content pages, FAQ entries or stores get a type of their own, see
[own document types](https://eiling-io.github.io/batteryincluded-bundle/export#own-document-types).

## Single documents

Providers implementing `PartialDataProviderInterface::getByIds()` deliver single documents. They are used by

```bash
bin/console bi:sync PRODUCT-5 BLOG-3              # creates or replaces the documents
bin/console bi:sync PRODUCT-5 --delete-missing    # deletes the id if the provider delivers no document for it
bin/console bi:sync PRODUCT-5 --dry-run           # prints the documents
bin/console bi:delete PRODUCT-5 --force           # deletes the documents
```

and by the `DocumentSynchronizer` service, which you can call from your code, e.g. after saving a product.

### Messenger

With `symfony/messenger` installed, dispatch the messages instead and route them to an async transport, so a request
does not wait for the api. Api errors are thrown and retried by the transport, rejected documents are not retried.
Use `deleteMissing` only if the providers of all affected document types implement `PartialDataProviderInterface`,
otherwise their documents are deleted.

```php
$bus->dispatch(new SyncDocumentsMessage(['PRODUCT-5'], deleteMissing: true));
$bus->dispatch(new DeleteDocumentsMessage(['PRODUCT-6']));
```

```yaml
framework:
    messenger:
        routing:
            'Batteryincluded\BatteryincludedBundle\Messenger\SyncDocumentsMessage': async
            'Batteryincluded\BatteryincludedBundle\Messenger\DeleteDocumentsMessage': async
```

### Doctrine

Mark entities whose changes update a document. After a successful flush the change is queued, at the end of the
request, command or message all changes are sent at once: as messages if symfony/messenger is installed, otherwise
directly. Failures are logged, the response is not affected.

```php
#[ORM\Entity]
#[BatteryIncludedDocument('PRODUCT', deleteMissing: true)]   // document id PRODUCT-<doctrine id>
class Product
{
}

#[ORM\Entity]
#[BatteryIncludedDocument('PRODUCT', identifier: 'getProductId', deleteOnRemove: false)]   // updates the product
class Variant
{
}
```

| Option | Default | Meaning |
|---|---|---|
| `type` | – | Document type, e.g. `PRODUCT` |
| `identifier` | Doctrine id | Method or property delivering the identifier, `null` skips the entity |
| `collection` | `default` | Name of the collection in the bundle configuration |
| `deleteOnRemove` | `true` | Removing the entity deletes the document, otherwise it is synced |
| `deleteMissing` | `false` | Deletes the document if the providers no longer deliver it (e.g. a deactivated product). Only enable it if a `PartialDataProviderInterface` handles the type. |

The attribute is repeatable and inherited. The documents themselves come from the providers (`getByIds()`), the
attribute only says which ids changed. Use `DocumentChangeQueue::scheduleSync()` / `scheduleDelete()` to queue changes
from your own code.

## Events

| Event | When | Use it to |
|---|---|---|
| `PreExportEvent` | before `bi:export` loads the first document | stop the export with `cancel($reason)`, e.g. during maintenance |
| `DocumentsEvent` | for every batch of `bi:export`, `bi:sync`, the `DocumentSynchronizer` and the messenger handlers | enrich, replace or remove documents with `setDocuments()`; `context` is `export` or `sync` |
| `PostExportEvent` | after `bi:export` finished, failed or was cancelled | clear caches, send a notification; contains documents, duration, `SyncResult` and error |

Documents removed in a `DocumentsEvent` are not sent: a full import then deletes them from the collection, a sync keeps
the version in the collection.

```php
#[AsEventListener]
public function onDocuments(DocumentsEvent $event): void
{
    $event->setDocuments(array_filter($event->getDocuments(), fn (AbstractDto $document) => !$this->isHidden($document)));
}
```

## Search in a controller

A `BrowseSearchStruct` or `SuggestSearchStruct` argument is filled from the query string: `q`, filters (`f[field][]=value`,
`f[field][from]=1&f[field][till]=9`), `sort`, `page` and `per_page` (limited to `max_per_page`). The locale is the one
of the request, the availability comes from the request attribute `_batteryincluded_availability` (see below).
Invalid values are ignored.

```php
#[Route('/search')]
public function search(
    #[MapSearchStruct(perPage: 12, sortings: ['', '_PRODUCT.price:asc', '_PRODUCT.price:desc'])] BrowseSearchStruct $searchStruct,
    BrowseService $browseService,
): Response {
    return $this->render('search.html.twig', ['result' => $browseService->browse($searchStruct)]);
}
```

`MapSearchStruct` is optional, it also takes `maxPerPage`, `locale`, `availability` and `presetId`.

### Availability

Prices and stock of a country or sales channel are selected by the availability scope. With `batteryincluded.availability`
a request listener stores it in the request attribute `_batteryincluded_availability`: the first matching host, then
the locale (`de_CH`, then the language `de`), then `default`. Set the attribute in your own listener (priority above
15) to use another source, e.g. the sales channel of the session.

```yaml
batteryincluded:
    availability:
        hosts:
            'shop.ch': ch
            '*.shop.at': at
        locales:
            de_CH: ch
            de: de
        default: de
```

`RequestAvailabilityResolver::resolve($request)` returns the scope outside of controllers.

## Console

```bash
bin/console bi:status                                   # configuration, connection, number of documents per collection
bin/console bi:search laptop                            # searches like the shop does
bin/console bi:search laptop -f type=PRODUCT -f _PRODUCT.price=500..900 -s _PRODUCT.price:asc -l en -a ch --facets
bin/console bi:search laptop --json                     # raw response
```

`bi:status` exits with 1 if a collection is not reachable, e.g. for a deployment check.

## Web profiler

With the WebProfilerBundle enabled the toolbar shows the number, duration and errors of the api calls of a request.
The "BatteryIncluded" panel lists every call with endpoint, parameters, headers (api key masked), request body,
status, duration, a result summary (hits, facets, search time) and the decoded response, plus a "Copy as cURL"
button which reads the api key from `$BATTERYINCLUDED_API_KEY`. Cached responses are marked. The configuration tab
shows base url, collection and api key (masked) of every collection and the installed versions of sdk and bundle. Api
calls appear in the "Performance" timeline as well.

## Demo shop

The demo shop of the sdk (`examples` directory) is part of the bundle: search with suggestions, facets, sorting,
pagination, extensions (teasers, promotions, redirects, codes), language and country switch and a detail page with
the source of every field. The suggestions are grouped by document type (products, content pages, blog posts) and link
to the detail page if `id` is selected under Suggest in the portal. It needs the TwigBundle, import the routes to enable it:

```yaml
# config/routes/batteryincluded.yaml
batteryincluded_demo:
    resource: '@BatteryincludedBundle/config/routes/demo.yaml'
    prefix: /batteryincluded-demo
```

## Development

```bash
make ddev-test      # phpunit, the api is mocked
make ddev-quality   # code style, phpstan, lint and tests
```

- [Test application](docs/test-application.md): setup, test data, demo shop and profiler
- [Contributing](CONTRIBUTING.md): workflow, checks and conventions
