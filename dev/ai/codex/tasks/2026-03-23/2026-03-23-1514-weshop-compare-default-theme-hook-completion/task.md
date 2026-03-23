# Task: weshop-compare-default-theme-hook-completion

- Task ID: 2026-03-23-1514-weshop-compare-default-theme-hook-completion
- Started: 2026-03-23 15:14
- Status: completed
- Owner: Codex
- Source: user request

## Goal

- Complete the `WeShop_Compare` storefront slice so it is production-usable on the `default` theme with clean routes, thin controllers, page-data service, and default-theme hook coverage for compare entry points.

## Scope

- In scope:
- `WeShop_Compare` service/controller/theme completion for `/compare`, `/compare/add`, and `/compare/remove`
- default-theme compare entry points for product detail, category cards, and customer account discovery
- unit tests, route refresh, and live smoke on port `9982` when runtime is available
- Out of scope:
- unrelated WeShop modules beyond the compare-adjacent account/theme touchpoints required by this slice
- modifying `WeShop_Theme` or `Weline_Theme`

## Constraints

- Follow TDD-first for the new slice.
- Keep controllers thin and service-backed.
- Use only WeShop-side/default-theme changes for hook and layout compatibility.
- Worktree is dirty; stage and commit only explicit compare/default-theme/task files.

## Related Plans

- None yet.

## Related Files

- `app/code/WeShop/Compare/**`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`
- `app/code/WeShop/Customer/Test/Unit/Service/AccountDashboardDataServiceTest.php`
- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- `app/design/WeShop/default/frontend/pages/product/view.phtml`
- `app/design/WeShop/default/frontend/assets/js/main.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
