---
title: Demo shop
nav_order: 11
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
| `/batteryincluded-demo/detail/{id}` | Detail page, e.g. `PRODUCT-5` |
| `/batteryincluded-demo/api/suggest?q=...` | JSON of the suggestions |

{: .note }
The demo shop shows the data of your collection as it is, use it to check an export and the portal configuration. Do
not expose it in production.

The [test application](test-application) mounts it at `/`. The code lives in `src/Demo/` and `templates/demo/`, it is
a port of the `examples` directory of the SDK.
