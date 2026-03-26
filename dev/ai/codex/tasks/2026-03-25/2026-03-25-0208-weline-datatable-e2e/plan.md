# Plan - Complete Weline DataTable module and frontend E2E

## Outcome

- Public DataTable demo pages are reachable and render stable table/form skeletons.
- Frontend-safe demo APIs cover table data, field metadata, record read/write, seed/reset, upload, and transaction/dependency scenarios for the demo models.
- Unit and browser coverage protect the main public demo flow against regressions.

## Steps

- [x] Audit the existing DataTable frontend gaps across taglib rendering, demo pages, JS API paths, and test coverage.
- [x] Add frontend-safe rendering support and API URL passthrough for DataTable/Form/Field usage.
- [x] Fix `datatable-manager.js` and `datatable-form-manager.js` integration details for frontend demo flows.
- [x] Implement frontend-safe demo APIs, controllers, templates, and seed/reset helpers.
- [x] Close the logic gaps found during verification:
- [x] semantic select fields resolve before generic numeric typing
- [x] default sort falls back to newest-first when the demo config does not define one
- [x] upload field DOM ids are namespaced by `formId`
- [x] PostgreSQL insert paths resync sequences before/after inserts through `pg_get_serial_sequence(...)`
- [x] Playwright dotted-key assertions use literal property checks
- [x] Add focused PHPUnit and Playwright coverage for the public DataTable demo path.
- [x] Execute focused verification and record the results.

## Verification Targets

- [ ] `php bin/w setup:upgrade --route`
  Blocked by an existing workspace/framework CLI bug: the command advertises `--route` but rejects it during argument validation.
- [x] `php vendor/bin/phpunit --no-coverage app/code/Weline/DataTable/Test/Unit --colors=never`
  Passed: `76 tests`, `286 assertions`, `1` deprecation.
- [x] `php tests/e2e/framework/preflight-refresh.php`
  Passed.
- [x] `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9996'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; node tests/e2e/start.js specs/frontend/weline-datatable.spec.js`
  Passed: `3 passed`.
