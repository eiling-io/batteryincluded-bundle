# Contributing

Contributions are welcome, bug reports and feature requests as issue, changes as pull request against `main`.

## Setup

```bash
ddev start
make ddev-install
```

Then follow [the test application guide](docs/test-application.md) to configure a test collection and to export the
test data. Without DDEV, PHP ≥ 8.2 with `curl` and Composer are enough:

```bash
composer install
composer install -d tests/Application
php -S 127.0.0.1:8000 -t tests/Application/public
```

## Project layout

| Path | Content |
|---|---|
| `src/BatteryincludedBundle.php` | Configuration tree, loads `config/services*.yaml` and registers the http client chain plus one `ApiClient` and the sdk services per collection |
| `src/Client/` | Http client chain: `TraceableHttpClient` (profiler, stopwatch) → `CachingHttpClient` → `LoggingHttpClient` → curl or `SymfonyHttpClient` |
| `src/Collection/CollectionRegistry.php` | Configured collections, their sdk services and data providers |
| `src/Commands/` | `bi:export`, `bi:sync`, `bi:delete`, `bi:status`, `bi:search` |
| `src/Sync/` | `DocumentSynchronizer` (single documents), `DocumentChangeQueue` (sent at the end of a request) and `SyncResult` |
| `src/Doctrine/` | `#[BatteryIncludedDocument]` and the listener, registered with the DoctrineBundle |
| `src/Event/` | `PreExportEvent`, `DocumentsEvent`, `PostExportEvent` |
| `src/Messenger/`, `config/services_messenger.yaml` | Messages and handlers, registered if symfony/messenger is installed |
| `src/Http/` | `SearchStructValueResolver` (controller arguments), `MapSearchStruct`, `RequestFilterParser`, availability resolver and listener |
| `tests/Unit/`, `tests/Functional/`, `tests/Fixtures/` | PHPUnit tests, `FakeHttpClient` and the `TestKernel` (with `MockApi`) replace the api |
| `src/Provider/` | `DataProviderInterface` and its optional extensions |
| `src/Profiler/ApiDataCollector.php`, `templates/profiler/` | Toolbar item and profiler panel |
| `src/Demo/`, `templates/demo/`, `config/demo/`, `config/routes/demo.yaml` | Demo shop |
| `config/services.yaml` | Service definitions, the classes of the sdk are registered as services |
| `tests/Application/` | Symfony application to try the bundle |

The bundle uses the sdk `dev-main`. Changes which need a new sdk feature belong into the
[sdk repository](https://github.com/eiling-io/batteryincluded-php-sdk) first.

## Workflow

1. Create a branch, e.g. `#12/main/short-description` with the number of the issue.
2. Make the change and try it in the test application, look at the api calls in the profiler.
3. Run the checks:

   ```bash
   make ddev-quality   # code style, phpstan, twig and container lint
   ```

4. Update the documentation (`README.md`, `docs/`) if the behaviour changes.
5. Open a pull request against `main` and describe how you tested the change.

## Checks

| Command | Content |
|---|---|
| `make ddev-cs` | php-cs-fixer (`.php-cs-fixer.php`, `@Symfony` and `@PSR12`), fixes the files |
| `make ddev-phpstan` | phpstan level 5 on `src/` |
| `make ddev-lint` | `lint:twig` for all templates of the bundle and `lint:container` of the test application |
| `make ddev-test` | PHPUnit, unit tests plus functional tests with a minimal kernel, the api is mocked |
| `make ddev-quality` | all of the above |

Run phpstan in DDEV (PHP 8.3): there Composer installs Symfony 7.4. With PHP ≥ 8.4 Composer installs Symfony 8, which
the bundled phpstan 1.12 cannot analyse (it reports the Symfony classes as unknown).

The CI (`.github/workflows/php.yml`) runs code style (dry run), phpstan and PHPUnit on PHP 8.2 to 8.5. The tests never
call the real api, try changes against a test collection in the test application as well. The tools in `tools/` are managed with
[phive](https://phar.io) (`phive.xml`).

## Conventions

- `declare(strict_types=1)` in every file, short comments which explain why.
- Never commit credentials. Collection and api key belong into `tests/Application/.env.dev.local`.
- The profiler must not expose the api key, mask it like `ApiDataCollector::maskApiKey()` does.
- `bi:export`, `bi:sync` and `bi:delete` write to the collection, test them against a dedicated test collection only.
- Optional integrations (messenger, lock, cache, http client, doctrine) must not be required, guard them like
  `config/services_messenger.yaml` or the Doctrine listener in `BatteryincludedBundle::loadExtension()`.
- New behaviour comes with a test, the `FakeHttpClient` records the requests and answers like the import endpoint.
