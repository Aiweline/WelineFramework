# Task: Complete Weline DataTable module and frontend E2E

- Task ID: `2026-03-25-0208-weline-datatable-e2e`
- Started: `2026-03-25 02:08`
- Status: `completed`
- Owner: `Codex`
- Source: `codex:user:完善 Weline 下的 DataTable 模块，让所有逻辑完备，以及测试前端的所有逻辑 e2e 完备`

## Goal

- Make the `Weline/DataTable` frontend demo path complete and safe to use.
- Cover core DataTable flows: basic table, join table, form submit, upload, dependency linkage, and transaction save.
- Add focused unit coverage and a stable Playwright browser spec for the public demo flow.

## Scope

- In scope:
- `app/code/Weline/DataTable/Taglib/*`
- `app/code/Weline/DataTable/Helper/*`
- `app/code/Weline/DataTable/Api/Rest/V1/*`
- `app/code/Weline/DataTable/Controller/Frontend/*`
- `app/code/Weline/DataTable/view/templates/frontend/*`
- `app/code/Weline/DataTable/view/statics/js/datatable-*.js`
- `app/code/Weline/DataTable/Test/Unit/*`
- `tests/e2e/specs/frontend/weline-datatable.spec.js`

- Out of scope:
- Broad backend private-API permission redesign
- Unrelated repository cleanup outside the DataTable slice

## Constraints

- Keep private backend CRUD APIs private; expose demo-only frontend-safe APIs for the whitelisted demo models.
- Use `apply_patch` for repository edits.
- New routes normally require `php bin/w setup:upgrade --route`, but that command is currently blocked in this workspace by an existing CLI argument-validation bug.

## Key Outcomes

- Frontend demo routes, templates, and API paths are complete and publicly reachable through the demo-only surface.
- Demo service behavior now handles semantic select fields, newest-first default sorting, and stable option loading.
- Form upload fields no longer collide on DOM ids across multiple forms.
- Transaction persistence now survives PostgreSQL sequence drift by syncing through `pg_get_serial_sequence(...)`.
- Focused unit tests and the browser spec both pass on the verified runtime.

## Verification Summary

- `php vendor/bin/phpunit --no-coverage app/code/Weline/DataTable/Test/Unit --colors=never`
  Result: `76 tests`, `286 assertions`, `1` deprecation.
- `php tests/e2e/framework/preflight-refresh.php`
  Result: passed.
- `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9996'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; node tests/e2e/start.js specs/frontend/weline-datatable.spec.js`
  Result: `3 passed`.

## Resume

- Closed. If follow-up work is needed, start from `result.md` and the focused DataTable/browser specs above.
