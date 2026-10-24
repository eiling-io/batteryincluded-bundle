---
title: Search in controllers
nav_order: 8
---

# Search in controllers
{: .no_toc }

1. TOC
{:toc}

## Search struct arguments

A controller argument of the type `BrowseSearchStruct` or `SuggestSearchStruct` is filled from the request. Pass it to
the service of the SDK:

```php
use BatteryIncludedSdk\Shop\BrowseSearchStruct;
use BatteryIncludedSdk\Shop\BrowseService;

#[Route('/search')]
public function search(BrowseSearchStruct $searchStruct, BrowseService $browseService): Response
{
    return $this->render('search.html.twig', [
        'searchStruct' => $searchStruct,
        'result' => $browseService->browse($searchStruct),
    ]);
}
```

| Query parameter | Struct | Example |
|---|---|---|
| `q` | Query, trimmed | `?q=laptop` |
| `f[<field>][]` | Filter values, empty values removed | `?f[type][]=PRODUCT&f[_PRODUCT.manufacturer][]=Acme` |
| `f[<field>][from]`, `f[<field>][till]` | Range filter, one bound is enough | `?f[_PRODUCT.price][from]=100` |
| `sort` | Sorting | `?sort=_PRODUCT.price:asc` |
| `page` | Page, at least 1 (browse only) | `?page=2` |
| `per_page` | Hits per page, limited to `max_per_page` (browse only) | `?per_page=48` |

The locale is the one of the request (`$request->getLocale()`), the availability comes from the
[request attribute](#availability). Invalid values like `per_page=abc` are ignored instead of answering with 400.

## MapSearchStruct

Fine-tune an argument with the optional attribute:

```php
use Batteryincluded\BatteryincludedBundle\Http\Attribute\MapSearchStruct;

public function sale(
    #[MapSearchStruct(perPage: 12, maxPerPage: 48, sortings: ['', '_PRODUCT.price:asc', '_PRODUCT.price:desc'], presetId: 'sale')]
    BrowseSearchStruct $searchStruct,
): Response
```

| Option | Default | Meaning |
|---|---|---|
| `perPage` | `search.per_page` (24) | Hits per page without `per_page` |
| `maxPerPage` | `search.max_per_page` (100) | Upper limit of `per_page` |
| `sortings` | all | Allowed values of `sort`, others are ignored |
| `locale` | locale of the request | Fixed locale |
| `availability` | request attribute | Fixed availability scope |
| `presetId` | – | Preset of the search |

Build a `SuggestSearchStruct` the same way for search-as-you-type endpoints, it takes `q`, `f`, locale and
availability.

## Availability

Prices and stock of a country or sales channel are selected with the availability scope (see
[Availability in the SDK](https://eiling-io.github.io/batteryincluded-php-sdk/availability)). Configure where it comes
from:

```yaml
batteryincluded:
    availability:
        hosts:
            'shop.ch': ch
            '*.shop.at': at        # all subdomains
        locales:
            de_CH: ch              # first the full locale (de_CH, de-CH) …
            de: de                 # … then the language
        default: de
```

The `AvailabilityListener` runs on `kernel.request` after the locale was set (priority 15) and stores the first match
in the request attribute `_batteryincluded_availability`: host, locale, language, default. An attribute that is set
already wins, so set it in your own listener with a higher priority to use another source, e.g. the sales channel
of the session:

```php
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
public function onRequest(RequestEvent $event): void
{
    $event->getRequest()->attributes->set(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE, $this->channel->getCountry());
}
```

Outside of controllers `RequestAvailabilityResolver::resolve($request)` returns the scope.

## Several collections

Inject the service of another [collection](configuration#several-collections) by its argument name, the struct stays
the same:

```php
public function search(BrowseSearchStruct $searchStruct, BrowseService $chBrowseService): Response
```

## Filters in your own code

`RequestFilterParser::parse($request)` returns the filters of a request in the format of `addFilters()`, the demo shop
uses it as well.
