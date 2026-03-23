# Task: weshop-compliance-storefront-slice

- Task ID: 2026-03-24-0930-weshop-compliance-storefront-slice
- Started: 2026-03-24 09:30
- Status: in_progress
- Owner: Codex
- Source: parallel-worker

## Goal

- Deliver a usable storefront compliance module in `default` theme, based on the existing `ConsentService` and consent-save flow.

## Scope

- In scope:
- `WeShop_Compliance` storefront routing/controllers/page-data/hooks/docs/tests.
- `app/design/WeShop/default/frontend/pages/compliance/*` new pages.
- Out of scope:
- `WeShop_Theme`/`Weline_Theme` module code.
- Shared `WeShop_Customer` account page structure changes.

## Constraints

- Keep controllers thin and move page assembly to services.
- Prefer account-center host hook injection for entry card.
- Do not use `ACTIVE.md` for mutable task state.
- Do not revert unrelated dirty worktree changes.

## Related Plans

- None yet.

## Related Files

- app/code/WeShop/Compliance/*
- app/design/WeShop/default/frontend/pages/compliance/*
- app/code/WeShop/Customer/hook.php (host reference only; avoid edits)

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
