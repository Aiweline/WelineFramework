# Task: weshop address storefront slice

- Task ID: 2026-03-23-2114-weshop-address-storefront-slice
- Started: 2026-03-23 21:14
- Status: in_progress
- Owner: Codex
- Source: codex chat: continue after commit, add two agents, keep improving modules/default theme hooks

## Goal

- Complete `WeShop_Address` as a production-usable storefront/account address-book slice that works on the `default` theme without touching `WeShop_Theme` / `Weline_Theme`.
- Replace the legacy ObjectManager/session controllers with thin customer-context controllers and a service/page-data layer aligned to `Weline_Shipping` delivery addresses.
- Keep checkout compatibility by normalizing saved address data back into the legacy keys the checkout page already consumes.

## Scope

- In scope:
  - `WeShop_Address` clean route, storefront page, save/delete/set-default actions, and compatibility JSON list endpoint
  - default-theme address page and account-center discovery hook card
  - link normalization to the clean `address` route from customer account and checkout success layouts
  - targeted unit tests and module validation
- Out of scope:
  - backend address management IA
  - unified address REST API namespace
  - broader default-theme catalog/filter/checkout host normalization beyond the address slice

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme`.
- Keep the repo-safe pattern: no controller business logic, no new shared mutable task state, no reverting unrelated dirty-worktree files.
- Runtime validation may still be blocked by unrelated `Aiweline\Stock` SQLite/Pgsql environment failures during `setup:upgrade`.

## Related Plans

- Follow-up theme-host audit points to `Catalog + Filters + Checkout shipping` as the next storefront compatibility slice.
- Follow-up backend/API audit points to `Promotion backend` as the safest independent admin slice after address/theme work.

## Related Files

- `app/code/WeShop/Address/**`
- `app/code/Weline/Shipping/Service/DeliveryAddressService.php`
- `app/design/WeShop/default/frontend/pages/address/index.phtml`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout_success/order_confirmation_page_{1,2,3,4}.phtml`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
