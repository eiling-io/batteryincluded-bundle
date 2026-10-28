---
title: Demo shop
nav_order: 13
---

# Demo shop

The demo shop of the SDK is part of the bundle: search with suggestions while typing, facets, sorting, pagination,
extensions (teasers, promotions, redirects, promotion codes), language and country switch and a detail page with the
source of every field (default value, translation, availability).

It needs the TwigBundle. Import the routes to enable it:

```yaml
# config/routes/batteryincluded.yaml
batteryincluded_demo:
    resource: '@BatteryincludedBundle/config/routes/demo.yaml'
    prefix: /batteryincluded-demo
```

| Url | Content |
|---|---|
| `/batteryincluded-demo/` | Search result page |
| `/batteryincluded-demo/detail/{id}` | Detail page, e.g. `PRODUCT-5`, `BLOG-3` or `CONTENT-1` |
| `/batteryincluded-demo/api/suggest?q=...` | JSON of the suggestions |

{: .note }
The demo shop shows the data of your collection as it is, use it to check an export and the portal configuration. Do
not expose it in production.

## Document types

The result list and the detail page show products (`PRODUCT`), blog posts (`BLOG`, title, author, date, cover) and
content pages (`CONTENT`, title, teaser, text, link), like the documents of the [test application](test-application).
Documents of other types are shown like products.

## Suggestions

The suggestions are grouped by document type with the `Suggester` of the bundle and its
[`suggest` configuration](frontend#configuration): up to four products, then up to three content pages, blog posts and
documents of any other type, each group with its own heading (the label of the type facet). The suggest endpoint
delivers only the fields selected under Suggest in the portal, the type is taken from the prefix of the document id
(`CONTENT-123`), otherwise from the root node (`_PRODUCT`, `_BLOG_i18n`, `_CONTENT_i18n`, …).

For your shop use the [suggest endpoint and the Stimulus controller](frontend) instead, the demo endpoint renders
for the demo templates.

Select `id` there (the document id in the root, e.g. `CONTENT-123`) so a suggestion links to its detail page. Without
the id the link searches the title within the type.

The [test application](test-application) mounts it at `/`. The code lives in `src/Demo/` and `templates/demo/`, it is
a port of the `examples` directory of the SDK.
