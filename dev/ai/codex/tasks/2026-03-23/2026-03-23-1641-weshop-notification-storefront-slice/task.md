# Task: weshop-notification-storefront-slice

- Task ID: 2026-03-23-1641-weshop-notification-storefront-slice
- Started: 2026-03-23 16:41
- Status: in_progress
- Owner: Codex
- Source: user request: parallelize remaining WeShop modules

## Goal

- Complete a production-usable storefront slice for `WeShop_Notification` with clean routes, thin controllers, account-center discovery entry, default-theme page rendering, and targeted tests.

## Scope

- In scope:
- storefront `notification` route and default-theme page
- logged-in notification listing and mark-as-read flow
- customer account-center discovery-card injection via existing WeShop hooks
- targeted unit tests and route/module upgrade verification
- Out of scope:
- shared theme layout rewrites outside module-owned hooks
- backend notification management

## Constraints

- Keep edits inside `app/code/WeShop/Notification/**`, `app/design/WeShop/default/frontend/pages/notification/**`, and this task workspace.
- Reuse existing `WeShop_Customer` storefront hook slots instead of editing shared account templates.
- Controllers should stay thin and service-backed.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Notification/**`
- `app/design/WeShop/default/frontend/pages/notification/**`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
