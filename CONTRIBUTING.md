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
| `src/BatteryincludedBundle.php` | Loads `config/services.yaml`, plus `config/services_demo.yaml` if the TwigBundle is enabled |
| `src/Client/TraceableHttpClient.php` | Decorates the curl client of the sdk and logs every call for the profiler and the stopwatch |
| `src/Commands/ExportCommand.php` | `bi:export`, full import of all `DataProviderInterface` services |
| `src/Profiler/ApiDataCollector.php`, `templates/profiler/` | Toolbar item and profiler panel |
| `src/Demo/`, `templates/demo/`, `config/demo/`, `config/routes/demo.yaml` | Demo shop |
| `config/services.yaml` | Service definitions, the classes of the sdk are registered as services, `ApiClient` gets its configuration from the environment |
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
| `make ddev-quality` | all of the above |

Run phpstan in DDEV (PHP 8.3): there Composer installs Symfony 7.4. With PHP ≥ 8.4 Composer installs Symfony 8, which
the bundled phpstan 1.12 cannot analyse (it reports the Symfony classes as unknown).

The CI (`.github/workflows/php.yml`) runs code style (dry run) and phpstan on PHP 8.2 to 8.5. There are no automated
tests yet, so test changes manually in the test application. The tools in `tools/` are managed with
[phive](https://phar.io) (`phive.xml`).

## Conventions

- `declare(strict_types=1)` in every file, short comments which explain why.
- Never commit credentials. Collection and api key belong into `tests/Application/.env.dev.local`.
- The profiler must not expose the api key, mask it like `ApiDataCollector::maskApiKey()` does.
- `bi:export` is a full import, test it against a dedicated test collection only.
