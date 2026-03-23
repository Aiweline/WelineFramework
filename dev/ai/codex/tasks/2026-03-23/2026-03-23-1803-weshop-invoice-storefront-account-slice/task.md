# Task: weshop-invoice-storefront-account-slice

- Task ID: 2026-03-23-1803-weshop-invoice-storefront-account-slice
- Started: 2026-03-23 18:03
- Status: in_progress
- Owner: Codex
- Source: user:invoice storefront/account slice with hook + tests

## Goal

- Complete a production-usable WeShop Invoice storefront/account slice without touching theme modules or shared customer host templates.

## Scope

- In scope:
- `app/code/WeShop/Invoice/**` storefront route, frontend controller, service, hooks/docs, and unit tests
- `app/design/WeShop/default/frontend/pages/invoice/**` default theme invoice page
- This task workspace logs (`task.md`, `plan.md`, `progress.md`, `result.md`)
- Out of scope:
- `WeShop_Theme` and `Weline_Theme` module source
- Shared host customer page template edits
- Any other WeShop module code

## Constraints

- Keep controller thin and service-backed.
- Keep clean route shape (`/invoice`) via module router env config.
- Prefer account-center injection through `view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`.
- Do not revert unrelated workspace changes.

## Related Plans

- None yet.

## Related Files

- `app/code/WeShop/Invoice/**`
- `app/design/WeShop/default/frontend/pages/invoice/**`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
