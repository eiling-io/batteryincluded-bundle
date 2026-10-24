MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

.PHONY: help
.PHONY: help
help:       ## Shows the help
help:
	@printf "\033[33mUsage:\033[0m\n  make TARGET\n\n\033[32m#\n# Commands\n#---------------------------------------------------------------------------\033[0m\n"
	@fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##//' | awk 'BEGIN {FS = ":"}; {printf "\033[33m%s:\033[0m%s\n", $$1, $$2}'

.PHONY: ddev-install
ddev-install:     ## installs the dependencies of the bundle and the test application in the running ddev container
ddev-install:
	ddev composer install
	ddev composer install -d tests/Application

.PHONY: ddev-export
ddev-export:     ## exports the test data of the test application to the configured collection (full import)
ddev-export:
	ddev exec -d /var/www/html/tests/Application "bin/console bi:export -v"

.PHONY: ddev-quality
ddev-quality:     ## executes all quality scripts in the running ddev container
ddev-quality: ddev-cs ddev-phpstan ddev-lint

.PHONY: ddev-cs
ddev-cs:     ## executes code style scripts in the running ddev container
ddev-cs:
	ddev exec -s web "PHP_CS_FIXER_IGNORE_ENV=true ./tools/php-cs-fixer fix --config=.php-cs-fixer.php"

.PHONY: ddev-phpstan
ddev-phpstan:     ## executes phpstan scripts in the running ddev container
ddev-phpstan:
	ddev exec -s web "./tools/phpstan analyse -c phpstan.neon"

.PHONY: ddev-lint
ddev-lint:     ## lints templates and the service container with the test application
ddev-lint:
	ddev exec -d /var/www/html/tests/Application "bin/console lint:twig ../../templates && bin/console lint:container"
