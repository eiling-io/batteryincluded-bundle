# BatteryIncluded Bundle

Symfony integration of the [BatteryIncluded PHP SDK](https://github.com/eiling-io/batteryincluded-php-sdk).

## Configuration

```dotenv
BATTERYINCLUDED_BASE_URL=https://api.batteryincluded.io/api/v1/collections/
BATTERYINCLUDED_COLLECTION=customer.my-shop
BATTERYINCLUDED_API_KEY=your-api-key
```

## Export

Services implementing `Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface` deliver the documents,
`bin/console bi:export` runs a full import (all other documents of the collection are deleted). Use `-v` to see the
api responses.

## Web profiler

With the WebProfilerBundle enabled the toolbar shows the number, duration and errors of the api calls of a request.
The "BatteryIncluded" panel lists every call with endpoint, parameters, headers (api key masked), request body,
status, duration, a result summary (hits, facets, search time) and the decoded response, plus a "Copy as cURL"
button which reads the api key from `$BATTERYINCLUDED_API_KEY`. The configuration tab shows base url, collection
and the installed versions of sdk and bundle. Api calls appear in the "Performance" timeline as well.

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
