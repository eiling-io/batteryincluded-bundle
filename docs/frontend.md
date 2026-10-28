---
title: Suggestions & Twig
nav_order: 9
---

# Suggestions & Twig
{: .no_toc }

Search-as-you-type for your header and search results in your templates, without writing JavaScript or a controller.

1. TOC
{:toc}

## Suggestions grouped by type

The `Suggester` groups the suggestions of the API by document type: products, content pages, blog posts and your own
types. The bundle ships a JSON endpoint and a Stimulus controller which renders them as a dropdown.

### 1. Import the route

```yaml
# config/routes/batteryincluded.yaml
batteryincluded_suggest:
    resource: '@BatteryincludedBundle/config/routes/suggest.yaml'
```

`GET /batteryincluded/suggest?q=lap` answers with:

```json
{
    "query": "lap",
    "completions": [{"value": "laptop", "html": "<mark>lap</mark>top"}],
    "groups": [
        {"type": "PRODUCT", "label": "PRODUCT", "items": [
            {"id": "PRODUCT-1", "type": "PRODUCT", "title": "Laptop Pro", "html": "<mark>Lap</mark>top Pro",
             "text": "BI-LT-1", "image": "https://…/1.png", "url": "/product/1",
             "price": 999.0, "instock": 3, "active": true, "data": {…}}
        ]},
        {"type": "CONTENT", "label": "CONTENT", "items": […]}
    ],
    "found": 241
}
```

| Request | Source |
|---|---|
| `q` | Query string, shorter than `suggest.min_length` returns nothing |
| Locale | `?locale=` or the locale of the request |
| Availability (prices, stock) | Request attribute `_batteryincluded_availability`, see [availability](search#availability). The visitor cannot choose it |
| Collection | Route default `collection`, e.g. an own route with `defaults: { collection: ch }` |

`html` and `text` are escaped, only the `<mark>` tags of the highlighting are kept. Errors are logged and answered
with `502` and a generic message, the visitor never sees API details. The response is `Cache-Control: private`,
prices and stock depend on the visitor.

The documents contain only the fields selected under **Suggest** in the BatteryIncluded portal. Select at least `id`
and the title (`name`, `title`), plus `imageUrl`/`previewImage`, `price` and `teaser` if you want to show them.

### 2. Link the documents

Implement `DocumentUrlResolverInterface` to give the items a `url`. Every implementation is autoconfigured, the first
one returning a url wins:

```php
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlResolverInterface;

final class ShopDocumentUrlResolver implements DocumentUrlResolverInterface
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function resolve(string $id, string $type, array $data): ?string
    {
        // $id = 'PRODUCT-17', $data = payload with translations and availability
        return match ($type) {
            'PRODUCT' => $this->urlGenerator->generate('product', ['number' => $data['ordernumber'] ?? substr($id, 8)]),
            'BLOG' => $this->urlGenerator->generate('blog_post', ['id' => substr($id, 5)]),
            default => null,
        };
    }
}
```

Without a resolver the `url` field of the document is used (e.g. the content pages of the
[test application](test-application)). Items without url search for their title when they are selected.

### 3. Add the Stimulus controller

The controller lives in `assets/dist/suggest_controller.js` of the bundle. With Symfony UX (StimulusBundle and
AssetMapper or Webpack Encore) Flex registers it in `assets/controllers.json` as `@batteryincluded/batteryincluded-bundle/suggest`:

```twig
<form action="{{ path('search') }}" style="position: relative"
      {{ stimulus_controller('@batteryincluded/batteryincluded-bundle/suggest', {
          url: path('batteryincluded_suggest'),
          locale: app.request.locale,
          currency: 'EUR',
          labels: {PRODUCT: 'Produkte', CONTENT: 'Service', BLOG: 'Blog'},
          showAllLabel: 'Alle %count% Treffer anzeigen',
      }) }}>
    <input type="search" name="q" {{ stimulus_target('@batteryincluded/batteryincluded-bundle/suggest', 'input') }}>
</form>
```

Without Symfony UX register it yourself, the identifier is `batteryincluded--batteryincluded-bundle--suggest`:

```js
import { Application } from '@hotwired/stimulus';
import SuggestController from '@batteryincluded/batteryincluded-bundle/suggest_controller.js';

Application.start().register('batteryincluded--batteryincluded-bundle--suggest', SuggestController);
```

| Value | Default | Content |
|---|---|---|
| `url` | | Url of the suggest endpoint |
| `locale` | | Sent as `?locale=`, formats the prices |
| `minLength` | `2` | Shorter queries are not sent |
| `delay` | `150` | Milliseconds of debouncing |
| `labels` | `{}` | Heading per type, the type is shown otherwise |
| `currency` | | Currency of the prices, without it no prices are shown |
| `showAllLabel` | `Show all %count% results` | Last entry, submits the form |

The dropdown is a `listbox`: arrow keys move, Enter opens, Escape closes. Its target `results` is created after the
input unless you place it yourself. Style it with `suggest.css` (imported automatically by Symfony UX) or your own
CSS: all classes start with `bi-suggest`, colors are custom properties like `--bi-suggest-bg`.

| Event | Detail | `preventDefault()` |
|---|---|---|
| `batteryincluded--batteryincluded-bundle--suggest:results` | `result`: the JSON | Renders nothing, render it yourself |
| `batteryincluded--batteryincluded-bundle--suggest:select` | `type` (`item`, `completion`, `search`), `item`, `value` | Keeps the dropdown, e.g. for tracking or an own action |

### Configuration

```yaml
batteryincluded:
    suggest:
        limits: { PRODUCT: 4, default: 3 }   # documents per type, 0 hides a type
        order: [PRODUCT, CONTENT, BLOG]      # these types first, all others in the order of the API
        completions: 5
        min_length: 2
```

The [demo shop](demo-shop) uses the same configuration.

### SuggestEvent

Change the result before it is sent, e.g. translate the group labels or add fields of your shop:

```php
use Batteryincluded\BatteryincludedBundle\Event\SuggestEvent;

#[AsEventListener]
final class TranslateSuggestLabels
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(SuggestEvent $event): void
    {
        $result = $event->getResult();
        foreach ($result['groups'] as &$group) {
            $group['label'] = $this->translator->trans('search.type.' . $group['type'], locale: $event->locale);
        }
        $event->setResult($result);
    }
}
```

### Own controller

Inject the `Suggester` to render the suggestions yourself:

```php
$result = $suggester->suggest($query, $request->getLocale(), $availability, 'default', ['type' => ['PRODUCT']]);
```

## Twig functions

Available as soon as the TwigBundle is enabled. Locale and availability default to the current request.

```twig
{# "similar" blog posts in the sidebar #}
{% for document in bi_search('', {type: 'BLOG', sort: '_BLOG.date:desc', per_page: 3}) %}
    <a href="{{ bi_document_url(document) }}">{{ document._BLOG.title }}</a>
{% endfor %}

{# recommendations on the product page #}
{% for recommendation in bi_recommendations('PRODUCT-' ~ product.id, 'together', {limit: 4}) %}
    …
{% endfor %}

{# searches maintained in the backend #}
{% for search in bi_highlights().searches %}…{% endfor %}

{# highlighted api values, escaped except <mark> #}
{{ hit.highlight.name|bi_highlight }}
```

| Function | Returns |
|---|---|
| `bi_search(query = '', options = {})` | Documents of the hits, translations and availability merged |
| `bi_recommendations(id, kind = 'related', options = {})` | Recommendations of `related`, `also` or `together`, `null` returns all three |
| `bi_highlights(options = {})` | `{searches, querySuggestions}` |
| `bi_document_url(document)` | Url by the [DocumentUrlResolverInterface](#2-link-the-documents) services |
| `\|bi_highlight` (filter) | Escaped value with the `<mark>` tags kept |

| Option of `bi_search` | Content |
|---|---|
| `per_page` (4), `page` | Paging |
| `type` | Shortcut for the filter `type`, e.g. `BLOG` |
| `filters` | `{'_PRODUCT.brand': ['Acme']}`, a single value is allowed |
| `sort`, `preset` | Like `BrowseSearchStruct` |
| `locale`, `availability` | Override the values of the request |
| `collection` | Name in the bundle configuration, also for `bi_recommendations` and `bi_highlights` |

`bi_recommendations` takes the document id (`PRODUCT-1`) or the identifier of a product (`1`).

{: .note }
A failed API call must not break the page: the functions log the error to the `batteryincluded` channel and return an
empty result. With `kernel.debug` (dev environment) they throw. Unknown options always throw. Enable the
[cache](configuration#cache) for highlights and recommendations, `bi_search` is never cached.
