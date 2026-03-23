# Task: weshop-membership-storefront-slice

- Task ID: 2026-03-24-0910-weshop-membership-storefront-slice
- Started: 2026-03-24 09:10
- Status: in_progress
- Owner: Codex
- Source: parallel-worker

## Goal

- Complete `WeShop_Membership` storefront/default-theme/account-center slice without touching Theme modules.
- Deliver a production-usable customer-facing membership route and page with hook-based account-center entry.

## Scope

- In scope:
- `WeShop_Membership` route/controller/page-data/hook/doc/test additions.
- `app/design/WeShop/default/frontend/pages/membership/index.phtml`.
- Out of scope:
- `WeShop_Theme` and `Weline_Theme`.
- Editing shared customer page template structure.
- Backend/admin IA work.

## Constraints

- Keep controller thin and business logic in services.
- Use existing customer-account host hook injection.
- Do not write mutable state into `ACTIVE.md`.
- Avoid reverting unrelated dirty changes.

## Related Plans

- None yet.

## Related Files

- app/code/WeShop/Membership/*
- app/design/WeShop/default/frontend/pages/membership/index.phtml

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
