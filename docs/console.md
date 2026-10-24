---
title: Console commands
nav_order: 9
---

# Console commands
{: .no_toc }

1. TOC
{:toc}

Every command takes `--collection=<name>` (`-c`) to work with a further [collection](configuration#several-collections).

## bi:status

Shows the versions of SDK and bundle, the configuration (API key masked) and checks every collection with a search:
status, response time and number of documents.

```bash
bin/console bi:status
bin/console bi:status -c ch
```

Exits with `1` if a collection is not reachable, use it as deployment or health check.

## bi:search

Searches like the shop does, with translations and availability resolved by the SDK.

```bash
bin/console bi:search laptop
bin/console bi:search laptop -f type=PRODUCT -f _PRODUCT.price=500..900 -s _PRODUCT.price:asc
bin/console bi:search laptop -l en -a ch --facets
bin/console bi:search laptop --json
```

| Option | Default | Meaning |
|---|---|---|
| `query` | empty | Search term, empty lists all documents |
| `--filter`, `-f` | | `field=value` or a range `field=from..till`, one bound is enough, repeatable |
| `--sort`, `-s` | | e.g. `_PRODUCT.price:asc` |
| `--page`, `--per-page` | 1, 10 | |
| `--locale`, `-l` | `de` | Locale of the translations |
| `--availability`, `-a` | | Availability scope, e.g. the country |
| `--preset` | | Id of a preset |
| `--facets` | | Shows the facets with their values |
| `--json` | | Prints the raw response |

## bi:export

Full import of all [data providers](export). See [Export](export#options) for all options.

```bash
bin/console bi:export --dry-run
bin/console bi:export --force
bin/console bi:export -p ProductProvider
```

## bi:sync

Creates or replaces single documents, loaded from the providers implementing `PartialDataProviderInterface`.

```bash
bin/console bi:sync PRODUCT-5 BLOG-3
bin/console bi:sync PRODUCT-5 --dry-run
bin/console bi:sync PRODUCT-5 --delete-missing
```

| Option | Meaning |
|---|---|
| `ids` | Document ids, e.g. `PRODUCT-5` |
| `--delete-missing` | Deletes the ids no provider delivers |
| `--dry-run` | Prints the documents as JSON |

## bi:delete

```bash
bin/console bi:delete PRODUCT-5 PRODUCT-6
bin/console bi:delete PRODUCT-5 --force
```

Asks for confirmation, `--force` is required without interaction. Reports ids that did not exist.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Failure: API error, rejected documents, cancelled, confirmation declined |
| `2` | Invalid options, e.g. unknown collection or provider |
