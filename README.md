# BatteryIncluded Bundle

Symfony integration of the [BatteryIncluded PHP SDK](https://github.com/eiling-io/batteryincluded-php-sdk).

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
HttpClient panel of the profiler.

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

## Search in a controller

A `BrowseSearchStruct` or `SuggestSearchStruct` argument is filled from the query string: `q`, filters (`f[field][]=value`,
`f[field][from]=1&f[field][till]=9`), `sort`, `page` and `per_page` (limited to `max_per_page`). The locale is the one
of the request, the availability (prices and stock of a country or sales channel) is read from the request attribute
`_batteryincluded_availability`, set it e.g. in a request listener. Invalid values are ignored.

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
the source of every field. It needs the TwigBundle, import the routes to enable it:

```yaml
# config/routes/batteryincluded.yaml
batteryincluded_demo:
    resource: '@BatteryincludedBundle/config/routes/demo.yaml'
    prefix: /batteryincluded-demo
```

## Development

- [Test application](docs/test-application.md): setup, test data, demo shop and profiler
- [Contributing](CONTRIBUTING.md): workflow, checks and conventions
