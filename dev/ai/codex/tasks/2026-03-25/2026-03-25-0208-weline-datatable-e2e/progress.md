# Progress - Complete Weline DataTable module and frontend E2E

- `2026-03-25 02:08` Created the task workspace and scoped the slice to a frontend-safe DataTable demo path plus focused unit/browser coverage.
- `2026-03-25` Confirmed the main baseline gaps: frontend demo routes were incomplete, taglib rendering was blocked on frontend contexts, JS managers still assumed backend API paths, and the public demo flow lacked stable verification.
- `2026-03-25` Kept the pragmatic demo strategy already in place instead of trying to repair nested `w:field` compile chains. The demo remains based on inline bootstrap, demo-only REST APIs, and stable plain HTML field/header definitions where needed.
- `2026-03-25` Hardened `DemoTableService` so semantic status/state/type-like fields resolve to `select` before generic numeric typing. Added unit coverage for semantic field types, option loading, and default sort normalization.
- `2026-03-25` Normalized demo-table default sorting to newest-first when no explicit sort is configured:
- `2026-03-25` single-model tables default to `id DESC`
- `2026-03-25` multi-model tables default to `mainAlias.id DESC`
- `2026-03-25` This keeps newly created rows visible on the current page after submit.
- `2026-03-25` Fixed upload-form DOM id collisions in `datatable-form-manager.js` by namespacing generated field ids with `formId`. This removed duplicate `#field-photo` and `#field-attachment` nodes in the demo DOM.
- `2026-03-25` Debugged the transaction/dependency save path and found the real multi-model insert blocker was PostgreSQL sequence drift, not the higher-level transaction orchestration.
- `2026-03-25` Updated `TransactionManager` to sync model sequences via `pg_get_serial_sequence(...)` instead of guessed sequence names, and to resync before and after new inserts. This removed duplicate-key failures on order inserts.
- `2026-03-25` Fixed the Playwright spec bug where dotted keys like `u.name` and `o.order_no` were asserted with `toHaveProperty`, which treated dots as object paths. The spec now checks literal object keys.
- `2026-03-25` Final focused verification passed:
- `2026-03-25` `php vendor/bin/phpunit --no-coverage app/code/Weline/DataTable/Test/Unit --colors=never` -> `76 tests`, `286 assertions`, `1` deprecation
- `2026-03-25` `php tests/e2e/framework/preflight-refresh.php` -> passed
- `2026-03-25` `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9996'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; node tests/e2e/start.js specs/frontend/weline-datatable.spec.js` -> `3 passed`
- `2026-03-25` Remaining environment note: the default unpinned wrapper still has the known local PHP runtime bootstrap issue, and `php bin/w setup:upgrade --route` remains blocked by the existing CLI argument-validation bug in this workspace.
