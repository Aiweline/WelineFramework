# Task: weshop promotion storefront slice

- Task ID: 2026-03-24-2140-weshop-promotion-storefront-slice
- Started: 2026-03-24 21:40
- Status: completed
- Owner: Codex
- Source: user direct assignment

## Goal

- Deliver a production-usable `WeShop_Promotion` storefront/default-theme slice with thin controllers, service-backed page data, and hook-based compatibility without touching `WeShop_Theme`/`Weline_Theme`.

## Scope

- In scope:
- `app/code/WeShop/Promotion/**`
- `app/design/WeShop/default/frontend/pages/promotion/**`
- this task workspace
- Out of scope:
- `WeShop_Theme` / `Weline_Theme` source changes
- edits to shared host pages/controllers outside Promotion ownership

## Constraints

- Keep work compatible with concurrent edits in the same repository.
- Do not revert unrelated worktree changes.

## Resume

- Read `plan.md`, `progress.md`, `result.md`.
