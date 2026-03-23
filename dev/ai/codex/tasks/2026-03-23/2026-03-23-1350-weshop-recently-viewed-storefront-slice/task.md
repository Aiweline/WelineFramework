# Task: weshop recently viewed storefront slice

- Task ID: 2026-03-23-1350-weshop-recently-viewed-storefront-slice
- Started: 2026-03-23 13:50
- Status: in_progress
- Owner: Codex
- Source: user: continue weshop storefront module completion

## Goal

- Complete the `WeShop_RecentlyViewed` storefront slice so it is production-usable on the `default` theme.
- Add a clean customer-facing route and page, wire product-view recording for logged-in customers, and keep account-center discovery flows coherent without touching `WeShop_Theme` or `Weline_Theme`.

## Scope

- In scope:
  - `WeShop_RecentlyViewed` storefront page, route, and page data service
  - Logged-in product-view recording integration from the product detail flow
  - `default` theme support for the recently viewed page and account/discovery links
  - Targeted unit tests plus runtime verification on port `9982`
- Out of scope:
  - Theme-module source changes
  - Full guest session/cookie history sync
  - Broad refactors of unrelated customer/order/header modules

## Constraints

- Follow the workspace-task policy: task state lives only in this directory.
- Preserve `default` theme compatibility through WeShop-side pages/hooks only.
- New storefront-facing routes should stay clean and avoid exposing extra `frontend/...` path layers.
- Verification should use runtime port `9982`.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Related Files

- `app/code/WeShop/RecentlyViewed/*`
- `app/code/WeShop/Product/Controller/Frontend/Product/View.php`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
