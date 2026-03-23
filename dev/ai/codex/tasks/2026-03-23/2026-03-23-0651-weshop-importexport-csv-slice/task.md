# Task: weshop-importexport-csv-slice

- Task ID: 2026-03-23-0651-weshop-importexport-csv-slice
- Started: 2026-03-23 06:51
- Status: in_progress
- Owner: Codex
- Source: Continue WeShop ecommerce implementation after auth route fix

## Goal

- Turn `WeShop_ImportExport` from a placeholder into a usable CSV import/export slice for products and orders.
- Keep the implementation production-oriented enough to support later backend UI and API wiring without redoing the service contract.

## Scope

- In scope:
- `WeShop\ImportExport\Service\ImportExportService` real CSV export/import logic
- backend export/import controllers that are currently empty files
- unit tests for product export, product import, order export, and controller behavior
- Out of scope:
- full admin IA/menu/page builder work for ImportExport
- non-CSV formats, bulk async jobs, and integration API endpoints
- broader WeShop payment/search/review/RMA gaps outside this slice

## Constraints

- Do not touch `WeShop_Theme` or `Weline_Theme`
- The worktree is very dirty; stage only this slice
- Follow TDD and keep controllers thin
- Runtime HTTP verification is optional for this slice if unit coverage closes the contract clearly

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- Prior WeShop auth foundation slices from `2026-03-23`

## Related Files

- `app/code/WeShop/ImportExport/Service/ImportExportService.php`
- `app/code/WeShop/ImportExport/Controller/Backend/ImportExport/Export.php`
- `app/code/WeShop/ImportExport/Controller/Backend/ImportExport/Import.php`
- `app/code/WeShop/Product/Model/Product.php`
- `app/code/WeShop/Order/Model/Order.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
