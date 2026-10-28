---
title: Profiler & logging
nav_order: 12
---

# Profiler & logging
{: .no_toc }

1. TOC
{:toc}

## Web profiler

With the WebProfilerBundle (and the TwigBundle) every page shows the BatteryIncluded item in the toolbar: number of API
calls, their total time, and red as soon as a call failed. Suggestions and other ajax calls appear in the ajax item.

The BatteryIncluded panel lists every call with

- method, endpoint, full url, collection and status,
- duration, request and response size, **served from cache** for [cached](configuration#cache) responses,
- a summary of the result: found, hits, facets, search time,
- the decoded parameters, headers (API key masked), request body (also NDJSON of imports) and response,
- a **Copy as cURL** button, the command reads the API key from `$BATTERYINCLUDED_API_KEY`.

The configuration tab shows base url, collection and masked API key of every collection and the installed versions
of SDK and bundle. API calls are part of the **Performance** timeline as well (category `batteryincluded`).

{: .note }
The profiler never stores the API key. Mask it in your own code with `ApiDataCollector::maskApiKey()`.

## Logging

Failed calls are logged as `error`, calls slower than `logging.slow_threshold` as `warning`, every call as `debug`, in
the channel `batteryincluded`. See [Configuration](configuration#logging). Failures of the
[`DocumentChangeQueue`](single-documents#documentchangequeue) go to the same logger.

## Console

`bin/console bi:status` checks the connection, `bin/console bi:search --json` shows the raw response of a search and
`-v` on `bi:export` prints every API response.
