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
| `make ddev-phpstan` | phpstan 2, level 5 on `src/`, analyses Symfony 7 and 8 |
| `make ddev-lint` | `lint:twig` and `lint:container` of the test application |
| `make ddev-quality` | all of the above |

## Tests

The tests never call the real API:

| Fixture | Content |
|---|---|
| `tests/Fixtures/FakeHttpClient.php` | Implements the http client of the SDK, records the requests, answers like the import endpoint or with queued responses |
| `tests/Fixtures/Collections.php` | `CollectionRegistry` with the collections `default` and `ch` on top of a `FakeHttpClient` |
| `tests/Fixtures/ArrayProvider.php` | Partial and collection aware data provider with generated products |
| `tests/Fixtures/TestKernel.php`, `MockApi.php` | Minimal Symfony application, the `MockHttpClient` answers browse, presets, suggest and delete; with `demo: true` it adds the TwigBundle and the demo shop routes |
| `tests/Functional/FunctionalTestCase.php` | Boots the `TestKernel`, `container()`, `application()` for console commands |
| `tests/Fixtures/Entity/` | Doctrine entities for the listener tests, SQLite in memory |

| Suite | Content |
|---|---|
| `tests/Unit/` | Http client chain, commands (`bi:export`, `bi:sync`, `bi:delete`), synchronizer, change queue, messenger handlers, Doctrine listener, value resolver, availability, profiler collector |
| `tests/Functional/` | Configuration and container of the bundle, search request, console commands, demo shop with grouped suggestions |

The CI (`.github/workflows/php.yml`) runs phpstan and the tests for every entry of its matrix, `symfony/flex` restricts
the Symfony packages with `SYMFONY_REQUIRE`:

| PHP | Symfony | Why |
|---|---|---|
| 8.2 | 7.0 with the lowest versions of all dependencies (`--prefer-lowest`) | Lower bounds of `composer.json` |
| 8.2, 8.3, 8.4 | 7.4 | LTS, the most common setup |
| 8.4 | 8.0 | First Symfony 8 release |
| 8.5 | 8.x (latest) | Upcoming changes and deprecations |

Try changes against a test collection in the test application as well.

## Documentation

This documentation lives in `docs/` and is published with GitHub Pages. Preview it locally with Jekyll:

```bash
cd docs
bundle init && bundle add github-pages jekyll-remote-theme
bundle exec jekyll serve
```

Update `llms.txt` and `llms-full.txt` when a page or the behaviour changes.
