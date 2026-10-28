---
title: Console commands
nav_order: 10
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
bin/console bi:export --force --report=var/log/export.json
```

The command prints a table per provider with the documents per type. `--report` writes the same as JSON, e.g. for
cron and CI logs, see [Export](export#report).

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

## bi:diff

Compares the documents of the data providers with the collection, nothing is changed. Lists the ids which are
**missing** in the collection (e.g. after a failed sync) and the **orphaned** ids no provider delivers anymore
(e.g. deleted products).

```bash
bin/console bi:diff
bin/console bi:diff -t PRODUCT -l 0
bin/console bi:diff --format=json
```

```
 --------- ----------- ------------ --------- ----------
  Type      Providers   Collection   Missing   Orphaned
 --------- ----------- ------------ --------- ----------
  BLOG      10          10           0         0
  PRODUCT   720         719          1         0
 --------- ----------- ------------ --------- ----------

Missing in the collection
-------------------------

PRODUCT-17
```

| Option | Default | Meaning |
|---|---|---|
| `--type`, `-t` | all | Compares only this type, repeatable |
| `--limit`, `-l` | 20 | Listed ids per difference, `0` lists all |
| `--format` | `txt` | `json` for monitoring: `consistent`, counts per type, `missing`, `orphaned` |

Exits with `1` if there are differences: use it as a nightly check and fix them with `bi:sync <ids>`, `bi:delete <ids>`
or a full import. The providers are read like by `bi:export`, including the [`DocumentsEvent`](events#documentsevent)
listeners, the collection is read with the browse endpoint (250 ids per request).

{: .note }
The collection needs a few seconds after an export until it contains all documents, run `bi:diff` not directly
afterwards. If the API delivers fewer ids than it found, the command warns: the orphaned ids are incomplete then.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Failure: API error, rejected documents, cancelled, confirmation declined, differences of `bi:diff` |
| `2` | Invalid options, e.g. unknown collection or provider |
