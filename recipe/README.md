# Symfony Flex recipe

Recipe of `batteryincluded/batteryincluded-bundle` in the layout of
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib): copy `batteryincluded/` into a fork of the
repository and open a pull request. Flex applies it on `composer require batteryincluded/batteryincluded-bundle`:

- registers the bundle in `config/bundles.php`
- adds `BATTERYINCLUDED_COLLECTION` and `BATTERYINCLUDED_API_KEY` (empty) to `.env`, the values belong into `.env.local`
- copies `config/packages/batteryincluded.yaml` (the in-memory api in the test environment) and
  `config/routes/batteryincluded.yaml` (suggest endpoint and demo shop, commented)

The directory `0.0` is the lowest bundle version the recipe applies to. Change the recipe in a new version directory
once it was merged, Flex keeps the applied version in `symfony.lock`.
