---
title: Getting started
nav_order: 2
---

# Getting started
{: .no_toc }

1. TOC
{:toc}

## Installation

```bash
composer require batteryincluded/batteryincluded-bundle
```

The Flex recipe enables the bundle and adds:

| File | Content |
|---|---|
| `.env` | `BATTERYINCLUDED_COLLECTION` and `BATTERYINCLUDED_API_KEY`, empty, the values belong into `.env.local` |
| `config/packages/batteryincluded.yaml` | Collection and API key from the environment, the [in-memory API](testing) in the test environment |
| `config/routes/batteryincluded.yaml` | Suggest endpoint and demo shop, commented |

Until the variables are set, every command and page works, only the API calls fail and `bi:status` reports the
missing configuration.

{: .note }
The recipe is a contrib recipe: Flex asks before it applies it, `composer config extra.symfony.allow-contrib true`
applies contrib recipes without asking. Without the recipe enable the bundle yourself:

```php
// config/bundles.php
return [
    // ...
    Batteryincluded\BatteryincludedBundle\BatteryincludedBundle::class => ['all' => true],
];
```

## Credentials

Without configuration file the bundle reads the collection and the API key from the environment:

```dotenv
# .env.local, never commit credentials
BATTERYINCLUDED_COLLECTION=customer.my-shop
BATTERYINCLUDED_API_KEY=your-api-key
# optional, this is the default
BATTERYINCLUDED_BASE_URL=https://api.batteryincluded.io/api/v1/collections/
```

{: .warning }
The API key grants write access to the collection. Keep it in `.env.local`, a secret or the environment of your
server, never in a committed file.

Check the connection:

```bash
bin/console bi:status
```

```
Collection
----------
 ------------- ----------------------------------------------------
  Base URL      https://api.batteryincluded.io/api/v1/collections/
  Collection    customer.my-shop
  API key       abcd••••••••
  Status        200 in 153 ms
  Documents     730
  Search time   7 ms
 ------------- ----------------------------------------------------
 [OK] All collections are reachable.
```

## Using the SDK services

All services of the SDK are registered and can be autowired: `BrowseService`, `SuggestService`, `SyncService`,
`PresetsService`, `HighlightsService`, `RecommendationsService`, `CartRecommendationsService`,
`SimilarSearchService`. The [SDK documentation](https://eiling-io.github.io/batteryincluded-php-sdk/) describes them.

```php
use BatteryIncludedSdk\Suggest\SuggestService;

final class SuggestController
{
    public function __construct(private SuggestService $suggestService)
    {
    }
}
```

## First search page

A `BrowseSearchStruct` controller argument is filled from the query string, see [Search in controllers](search):

```php
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;

#[Route('/search')]
public function search(BrowseSearchStruct $searchStruct, BrowseService $browseService): Response
{
    return $this->render('search.html.twig', ['result' => $browseService->browse($searchStruct)]);
}
```

{% raw %}
```twig
<p>{{ result.found }} hits</p>
{% for hit in result.hits %}
    <li>{{ hit.document._PRODUCT.name ?? hit.document._BLOG.title }}</li>
{% endfor %}
```
{% endraw %}

## Next steps

1. Deliver your products with a [data provider](export#data-providers) and run `bin/console bi:export`.
2. Keep the collection up to date with [Doctrine](doctrine) or [Messenger](single-documents#messenger).
3. Look at every API call in the [web profiler](profiler).
