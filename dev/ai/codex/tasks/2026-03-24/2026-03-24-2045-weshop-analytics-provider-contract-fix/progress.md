# Progress - weshop-analytics-provider-contract-fix

- 2026-03-24 20:45 Created the task workspace for the analytics contract fix slice.
- 2026-03-24 20:47 Confirmed the module is currently broken in three ways:
  - observers call `PixelDispatcher::track(...)`, but the dispatcher only exposes `dispatch(...)`
  - `PixelDispatcher` only loads `FacebookPixel`, not `GoogleAnalytics`
  - `GoogleAnalytics` does not implement the current `PixelProviderInterface` methods at all, while `FacebookPixel` still contains TODO stubs
- 2026-03-24 21:10 Implemented the provider contract repair:
  - added `isEnabled()` to `PixelProviderInterface`
  - restored `PixelDispatcher::track(...)` as a compatibility alias and broadened provider loading to Facebook + Google
  - replaced `FacebookPixel` TODO stubs with config-driven payload builders and pixel snippet output
  - aligned `GoogleAnalytics` with the shared provider contract and Measurement Protocol payload generation
- 2026-03-24 21:15 Added focused unit coverage for the dispatcher and both providers.
- 2026-03-24 21:18 Validation completed:
  - `php -l` passed for all touched analytics PHP files and new tests
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/Analytics/Test/Unit --colors=never` passed (`5 tests / 14 assertions`, with one existing PHPUnit deprecation notice)
  - `php bin/w setup:upgrade -m WeShop_Analytics --yes` scanned the module and refreshed registries, then failed later on the unrelated global SQLite adapter issue raised through `Aiweline\\Stock\\Service\\AiFinanceNewsService`
