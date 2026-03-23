# Task: weshop order backend slice

- Task ID: 2026-03-23-2244-weshop-order-backend-slice
- Started: 2026-03-23 22:44
- Status: in_progress
- Owner: Codex
- Source: Local phase-2 slice: complete WeShop_Order backend/admin list/detail/status management with menu, templates, tests, and validation

## Goal

- Make `WeShop_Order` backend/admin usable with a real order list page, order detail page, status update flow, backend menu entry, and targeted unit coverage.

## Scope

- In scope:
- `app/code/WeShop/Order/**` backend controllers, services, templates, menu, tests, and i18n
- Out of scope:
- frontend/default-theme order pages
- cross-module API/auth refactors
- `WeShop_Theme` / `Weline_Theme`

## Constraints

- Keep controllers thin and push shaping logic into a service.
- Do not touch unrelated dirty-worktree files.
- Validate with targeted `php -l`, `phpunit`, and a best-effort `setup:upgrade`.

## Related Plans

- WeShop phase-2 module completion wave.

## Related Files

- `app/code/WeShop/Order/Service/OrderService.php`
- `app/code/WeShop/Order/Service/OrderAdminPageDataService.php`
- `app/code/WeShop/Order/Controller/Backend/Order/Index.php`
- `app/code/WeShop/Order/Controller/Backend/Order/View.php`
- `app/code/WeShop/Order/Controller/Backend/Order/UpdateStatus.php`
- `app/code/WeShop/Order/etc/backend/menu.xml`
- `app/code/WeShop/Order/view/backend/templates/order/index.phtml`
- `app/code/WeShop/Order/view/backend/templates/order/view/index.phtml`
- `app/code/WeShop/Order/Test/Unit/**`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
