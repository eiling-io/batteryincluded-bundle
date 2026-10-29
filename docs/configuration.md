---
title: Configuration
nav_order: 3
---

# Configuration
{: .no_toc }

1. TOC
{:toc}

## Reference

All settings are optional. Print them with `bin/console config:dump-reference batteryincluded`.

```yaml
# config/packages/batteryincluded.yaml
batteryincluded:
    # base url of the api, the collection is appended (absolute, ending with "/")
    base_url: '%env(BATTERYINCLUDED_BASE_URL)%'   # falls back to https://api.batteryincluded.io/api/v1/collections/
    collection: '%env(BATTERYINCLUDED_COLLECTION)%'
    api_key: '%env(BATTERYINCLUDED_API_KEY)%'

    # further collections, see below
    collections:
        ch:
            collection: '%env(BATTERYINCLUDED_COLLECTION_CH)%'
            api_key: '%env(BATTERYINCLUDED_API_KEY_CH)%'
            base_url: ~                # defaults to base_url

    http_client: ~                     # service id of a Symfony http client, null uses curl
    write_timeout: 300                 # seconds, import, update and delete calls with the Symfony http client
    curl:
        timeout: 10                    # seconds, searches, suggestions and recommendations with curl, 0 for no limit
        connect_timeout: 5             # seconds, 0 for no limit
    in_memory_api: false               # replaces the api in tests, see Testing your application

    cache:
        enabled: false
        pool: cache.app
        ttl: 300                       # seconds
        endpoints: ['/documents/presets', '/documents/highlights', '/documents/recommendations']

    logging:
        enabled: true
        slow_threshold: 1000           # ms, 0 disables the warning

    export:
        batch_size: 500                # documents per request of bi:export, bi:sync and the DocumentSynchronizer
        schedule: []                   # recurring exports, e.g. - { cron: '0 3 * * *', collection: default, providers: [] }

    suggest:                           # suggest endpoint, Stimulus controller and demo shop
        limits: { PRODUCT: 4, default: 3 }   # documents per type, 0 hides a type
        order: [PRODUCT, CONTENT, BLOG]      # these types first, all others in the order of the api
        completions: 5
        min_length: 2

    search:
        per_page: 24                   # defaults of the BrowseSearchStruct controller argument
        max_per_page: 100

    availability:
        hosts: {}                      # e.g. 'shop.ch': ch, '*.shop.at': at
        locales: {}                    # e.g. de_CH: ch, de: de
        default: ~

    doctrine:
        enabled: true                  # only active with the DoctrineBundle
```

Invalid values stop the container build: base urls have to be absolute and end with `/`, collection names have to
match `[a-z][a-z0-9_]*`, a [scheduled export](export#scheduler) has to use a configured collection.

## Several collections

Shops with several sales channels or countries often use one collection each. The default collection keeps the class
names as service ids, every further collection gets its own `ApiClient` and SDK services. Inject them by argument name,
`$<name><Service>`:

```php
use BatteryIncludedSdk\Shop\BrowseService;

public function __construct(
    private BrowseService $browseService,     // default collection
    private BrowseService $chBrowseService,   // collection "ch"
) {
}
```

`bin/console debug:autowiring BrowseService --all` lists them. `CollectionRegistry` returns them by name, e.g. in a
service which serves all channels:

```php
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;

$browseService = $registry->get($channel, BrowseService::class);
$registry->getNames();          // ['default', 'ch']
$registry->getConfig('ch');     // ['baseUrl' => ..., 'collection' => ..., 'apiKey' => ...]
```

All commands take `--collection=<name>`, the [Doctrine attribute](doctrine) and the [messages](single-documents#messenger)
take the name as well.

## Curl

By default the SDK sends the requests with curl. A search, suggestion or recommendation is aborted after
`curl.timeout` seconds, so a hanging API does not block every page until the PHP timeout. Imports, updates and deletes
have no limit: the API answers the commit of a full import only after indexing. A failed connection throws the
`NetworkException` of the SDK.

## Symfony http client

With `http_client` a Symfony http client sends the requests instead of curl: timeouts, retries and proxies are
configured like for every other client, tests can replace it with the `MockHttpClient`, and
the calls appear in the HttpClient panel of the profiler as well.

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

Like curl, error responses are returned to the SDK and not thrown, connection errors are thrown.

The `timeout` of the client applies to searches. Import, update and delete calls use `write_timeout` (300 seconds by
default): the API answers the commit of a full import only after indexing, a short timeout would abort the export
although the API commits it. `retry_failed` does not repeat these POST calls after a timeout.

## Cache

With `cache.enabled` successful GET responses of the listed endpoints are stored in the pool for `ttl` seconds. The
key contains the url and the headers, collections with different API keys never share an entry. Searches and
suggestions are never cached unless you add their endpoint. Cached calls are marked in the [profiler](profiler).

## Logging

| Level | When |
|---|---|
| `error` | Error status or connection error |
| `warning` | Slower than `slow_threshold` |
| `debug` | Every call |

The messages go to the `batteryincluded` channel with method, url, status and duration. Headers are not logged, they
contain the API key. With MonologBundle route the channel as usual:

```yaml
monolog:
    handlers:
        batteryincluded:
            type: stream
            path: '%kernel.logs_dir%/batteryincluded.log'
            channels: [batteryincluded]
            level: warning
```

## Service chain

Every request passes these clients, each one is optional except the transport:

```
SDK service → ApiClient → TraceableHttpClient (profiler, stopwatch)
                        → CachingHttpClient   (cache.enabled)
                        → LoggingHttpClient   (logging.enabled)
                        → curl or SymfonyHttpClient (http_client)
```
