---
title: Development
nav_order: 13
---

# Development
{: .no_toc }

1. TOC
{:toc}

## Setup

```bash
ddev start
make ddev-install
```

Then follow [the test application guide](test-application) to configure a test collection. Workflow, project layout
and conventions are described in
[CONTRIBUTING.md](https://github.com/eiling-io/batteryincluded-bundle/blob/main/CONTRIBUTING.md).

## Checks

| Command | Content |
|---|---|
| `make ddev-test` | PHPUnit: unit tests plus functional tests with a minimal kernel |
| `make ddev-cs` | php-cs-fixer (`@Symfony`, `@PSR12`), fixes the files |
| `make ddev-phpstan` | phpstan level 5 on `src/` |
| `make ddev-lint` | `lint:twig` and `lint:container` of the test application |
| `make ddev-quality` | all of the above |

## Tests

The tests never call the real API:

| Fixture | Content |
|---|---|
| `tests/Fixtures/FakeHttpClient.php` | Implements the http client of the SDK, records the requests, answers like the import endpoint or with queued responses |
| `tests/Fixtures/Collections.php` | `CollectionRegistry` with the collections `default` and `ch` on top of a `FakeHttpClient` |
| `tests/Fixtures/ArrayProvider.php` | Partial and collection aware data provider with generated products |
| `tests/Fixtures/TestKernel.php`, `MockApi.php` | Minimal Symfony application, the `MockHttpClient` answers browse and presets |
| `tests/Fixtures/Entity/` | Doctrine entities for the listener tests, SQLite in memory |

The CI runs the tests on PHP 8.2 to 8.5, i.e. Symfony 7 and 8. Try changes against a test collection in the test
application as well.

## Documentation

This documentation lives in `docs/` and is published with GitHub Pages. Preview it locally with Jekyll:

```bash
cd docs
bundle init && bundle add github-pages jekyll-remote-theme
bundle exec jekyll serve
```

Update `llms.txt` and `llms-full.txt` when a page or the behaviour changes.
