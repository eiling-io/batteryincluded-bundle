# Test application

`tests/Application` is a minimal Symfony 7.4 application which uses the bundle. It is used to develop the bundle,
to try the demo shop and to look at the api calls in the web profiler.

## How it is wired

| Path | Content |
|---|---|
| `composer.json` | Requires `batteryincluded/batteryincluded-bundle: dev-main` from a path repository (`../../`, symlinked), so changes to the bundle are active immediately. The sdk is installed as `dev-main` from Packagist. |
| `config/bundles.php` | FrameworkBundle, TwigBundle, BatteryincludedBundle, WebProfilerBundle (dev and test) |
| `config/routes/batteryincluded.yaml` | Imports the demo shop routes of the bundle at `/` |
| `src/Provider/ProductProvider.php` | 720 products (smartphones, tablets, laptops), German and English translations, prices and status for Germany, Austria and Switzerland, the same data as `tests/Helper.php` of the sdk (with placeholder images) |
| `src/Provider/BlogProvider.php` | 10 blog posts with German and English translations |
| `src/Controller/SearchController.php` | Minimal example of an own controller (`/search`), the `BrowseSearchStruct` argument is filled from the query string |
| `config/packages/batteryincluded.yaml` | Bundle configuration: scoped Symfony http client with retries, cache, logging |
| `config/packages/messenger.yaml` | Routes the BatteryIncluded messages to the `sync` transport |
| `.env`, `.env.dev` | Defaults, the BatteryIncluded variables are empty on purpose |
| `.env.dev.local` | Your collection and api key, ignored by git |

Both providers implement `PartialDataProviderInterface`, they are tagged automatically, exported by `bi:export` and
deliver single documents to `bi:sync`.

## Setup

The repository ships a [DDEV](https://ddev.com) configuration with `tests/Application/public` as document root.

```bash
ddev start
make ddev-install   # composer install for the bundle and the test application
```

Create `tests/Application/.env.dev.local` with the credentials of a test collection:

```dotenv
BATTERYINCLUDED_BASE_URL=https://api.batteryincluded.io/api/v1/collections/
BATTERYINCLUDED_COLLECTION=customer.my-test-collection
BATTERYINCLUDED_API_KEY=your-api-key
```

Never put credentials into `.env` or `.env.dev`, both are committed.

## Test data

```bash
make ddev-export
# or
ddev exec -d /var/www/html/tests/Application "bin/console bi:export --force -v"
```

The command sends the documents of all providers in batches of 500 within one transaction and commits it with the
last batch. `--dry-run` checks the providers without sending anything.

> **Warning:** this is a full import, all other documents of the collection are deleted. Use a dedicated test collection.

With `-v` every api response is printed, the command stops with exit code 1 before the commit as soon as the api
answers with an error status or rejects documents.

Check the connection and the data with

```bash
ddev exec -d /var/www/html/tests/Application "bin/console bi:status"
ddev exec -d /var/www/html/tests/Application "bin/console bi:search laptop --facets"
```

## Demo shop

Open `https://batteryincluded-bundle.ddev.site`.

| Url | Content |
|---|---|
| `/` | Search result page: suggestions while typing, facets, sorting, pagination, teasers, promotions, redirects, promotion codes |
| `/detail/{id}` | Detail page of a document, e.g. `/detail/PRODUCT-5` or `/detail/BLOG-3`, with every field and its source (default value, translation, availability) |
| `/api/suggest?q=...` | JSON endpoint of the suggestions |

The flags in the header switch the language (`?lang=de|en`) and the country (`?country=de|at|ch`), both are
remembered in a cookie. "API details" at the end of a page shows the request and the first hit before and after the
sdk resolved translations and availability.

The code of the demo shop lives in the bundle:

| Path | Content |
|---|---|
| `src/Demo/Controller/DemoController.php` | Actions `index`, `detail`, `suggest`, a port of `examples/index.php`, `detail.php` and `api/suggest.php` of the sdk |
| `src/Demo/DemoShop.php` | Languages, countries, sortings, request state and view helpers, available as `demo` in the templates |
| `templates/demo/` | Twig templates, `assets/app.css` and `assets/app.js` are inlined, nothing has to be installed |
| `config/demo/translations.php` | UI texts per language |
| `config/routes/demo.yaml`, `config/services_demo.yaml` | Routes and services, the services are only registered with the TwigBundle |

When the demo of the sdk changes, port the change to these files.

## Web profiler

Every page of the test application shows the Symfony toolbar. The BatteryIncluded item shows the number of api
calls, their total time and turns red if a call failed. The suggestions are ajax requests, their profiles are listed
in the ajax item of the toolbar.

The BatteryIncluded panel of the profiler contains

- per api call: method, endpoint and full url, status, duration, request and response size, a summary of the result
  (found, hits, facets, search time), the decoded parameters, headers (api key masked), request body and response,
- a "Copy as cURL" button, the command reads the api key from `$BATTERYINCLUDED_API_KEY`,
- a configuration tab with base url, collection, masked api key and the installed versions of sdk and bundle.

Api calls are also visible in the "Performance" timeline (category `batteryincluded`).

## Troubleshooting

| Symptom | Cause |
|---|---|
| Error message on the page and a red toolbar item | Open the profiler panel, the response of the api shows the reason, e.g. `query failed, Synonym index not found` while a new collection is not set up yet. |
| `0 results` without error | The collection is empty, run the export. |
| Changes of services or config are not visible | `ddev exec -d /var/www/html/tests/Application "bin/console cache:clear"` |
| Container error about `BatteryIncludedSdk\...` classes | The bundle registers the classes of the sdk as services, directories without classes have to be excluded in `config/services.yaml` (see `Polyfill`). |
