# Changelog

All notable changes of the bundle. Changes up to 0.0.2 are listed in the git history only.

## Unreleased

Needs the current `dev-main` of batteryincluded/batteryincluded-php-sdk (`ce6f51c` or newer), update both packages
together: `composer update batteryincluded/*`.

### Breaking

- `bi_recommendations()` returns the documents with the translations and the availability of the request merged into
  `_<TYPE>`, like `bi_search()`. Templates that read `_PRODUCT_i18n` themselves have to use `_PRODUCT` instead. The
  options `locale` and `availability` override the values of the request.
- `bi_recommendations()` and `bi_highlights()` always send the locale (`v[locale]`), cached responses are stored per
  locale.
- `SymfonyHttpClient` throws the `NetworkException` of the SDK instead of a `RuntimeException`. It still extends
  `RuntimeException`.
- `ApiException` extends the `BatteryIncludedException` of the SDK, its message contains the message of the API instead
  of the raw response.
- Demo shop: `DemoShop::visibleFacets()` takes only the facets, `DemoShop::categoryField()` is removed.
- Removed by the SDK: `SuggestService::suggest()` (use `suggestWithFilter()`), `ProductBaseDto::setId()`/`getId()` and
  `BlogBaseDto::setId()`/`getId()` (the document is identified by the identifier of the constructor), the second
  argument of `ApiClient::getJson()`.

### Added

- `batteryincluded.curl.timeout` (10 s) and `curl.connect_timeout` (5 s) for the requests with curl. Imports, updates
  and deletes have no limit.
- `bi_highlights()` accepts the option `locale`.

### Changed

- `bi:export`, `bi:sync`, `bi:delete` and `bi:diff` handle every exception of the SDK. A network error stops a full
  import before the commit, the collection stays unchanged.
- The dry run of `bi:export` encodes the documents like the export and reports a document that cannot be encoded
  (e.g. a price of `NAN`) with its id. Invalid UTF-8 is replaced instead of failing.
- `SyncDocumentsHandler` does not retry documents that cannot be encoded.
- The demo shop leaves the choice of the category facet and the facets of other languages to the SDK.

### Fixed

- `bi:search` fails with the message of the API on an error status instead of printing an empty result.
