# Task: weshop-affiliate-storefront-slice

- Task ID: 2026-03-24-1945-weshop-affiliate-storefront-slice
- Started: 2026-03-24 19:45
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Complete `WeShop_Affiliate` storefront/default-theme/account-center slice with clean route, thin controller, page-data service, hook docs, and unit tests.

## Scope

- In scope:
- `app/code/WeShop/Affiliate/**`
- `app/design/WeShop/default/frontend/pages/affiliate/**`
- Out of scope:
- `WeShop_Theme` and `Weline_Theme`
- Shared `customer/index.phtml` structure changes

## Constraints

- Use host hook injection for account-center card.
- Keep frontend URL clean (`/affiliate`).
- Follow TDD + SOLID.

## Related Files

- `app/code/WeShop/Affiliate`
- `app/design/WeShop/default/frontend/pages/affiliate/index.phtml`

## Resume

- Read `plan.md`, `progress.md`, and `result.md`.
