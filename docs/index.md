---
title: Home
layout: home
nav_order: 1
---

# BatteryIncluded Symfony Bundle

The Symfony integration of the [BatteryIncluded PHP SDK](https://eiling-io.github.io/batteryincluded-php-sdk/). It
configures the SDK from the environment, exports your catalog with one command, keeps the collection in sync when your
entities change and puts every API call into the Symfony web profiler.

```php
// config/bundles.php
Batteryincluded\BatteryincludedBundle\BatteryincludedBundle::class => ['all' => true],
```

```php
#[Route('/search')]
public function search(BrowseSearchStruct $searchStruct, BrowseService $browseService): Response
{
    // q, filters, sorting, page, locale and availability are taken from the request
    return $this->render('search.html.twig', ['result' => $browseService->browse($searchStruct)]);
}
```

```bash
bin/console bi:export --force     # full import of all data providers
bin/console bi:sync PRODUCT-5     # update a single document
bin/console bi:status             # check configuration and connection
bin/console bi:diff               # compare providers and collection
```

{% raw %}
```twig
<form {{ stimulus_controller('@batteryincluded/batteryincluded-bundle/suggest', {url: path('batteryincluded_suggest')}) }}>
    <input name="q" {{ stimulus_target('@batteryincluded/batteryincluded-bundle/suggest', 'input') }}>
</form>

{% for post in bi_search('', {type: 'BLOG', per_page: 3}) %}…{% endfor %}
```
{% endraw %}

## What's inside

| Topic | Content |
|---|---|
| [Getting started](getting-started) | Installation, credentials, first search |
| [Configuration](configuration) | All options, several collections, Symfony http client, cache, logging |
| [Export](export) | Data providers, own document types, `bi:export`, full import and its safety nets, report, scheduler |
| [Single documents](single-documents) | `bi:sync`, `bi:delete`, `DocumentSynchronizer`, Messenger |
| [Doctrine](doctrine) | `#[BatteryIncludedDocument]`: sync entities after flush |
| [Events & extension points](events) | All events, interfaces, attributes, messages and services |
| [Search in controllers](search) | `BrowseSearchStruct` arguments, `#[MapSearchStruct]`, availability |
| [Suggestions & Twig](frontend) | Suggest endpoint, Stimulus controller, document urls, `bi_search()` and more Twig functions |
| [Console commands](console) | Reference of all `bi:*` commands, `bi:diff` |
| [Testing your application](testing) | In-memory API and assertions for your tests |
| [Profiler & logging](profiler) | Web profiler panel, timeline, log channel |
| [Demo shop](demo-shop) | A complete storefront inside the bundle, suggestions grouped by document type |
| [Test application](test-application) | The Symfony app used to develop the bundle |
| [Development](development) | Tests, code style, static analysis, this documentation |

## For AI coding agents

Let your agent read [`llms-full.txt`](llms-full.txt): installation, configuration, export, sync, search and a
verification checklist in one file. [`llms.txt`](llms.txt) lists all pages of this documentation.

## Requirements

- PHP 8.2 or higher, Symfony 7 or 8
- A BatteryIncluded collection and its API key

Optional packages unlock more features, nothing of it is required:

| Package | Feature |
|---|---|
| `symfony/twig-bundle` | Twig functions, demo shop and profiler panel |
| `symfony/stimulus-bundle` | Suggest Stimulus controller with AssetMapper or Webpack Encore |
| `symfony/scheduler` | Recurring exports |
| `symfony/web-profiler-bundle` | API calls in the toolbar and profiler |
| `symfony/messenger` | Asynchronous sync and delete |
| `doctrine/doctrine-bundle` | Sync entities after flush |
| `symfony/http-client` | Timeouts, retries, proxies and mocking of the API calls |
| `symfony/cache` | Cache for rarely changing responses |
| `symfony/lock` | Lock of the application for `bi:export` |
| `symfony/monolog-bundle` | Own `batteryincluded` log channel |
