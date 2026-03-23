# Task: weshop logistics backend slice

- Task ID: 2026-03-23-2251-weshop-logistics-backend-slice
- Started: 2026-03-23 22:51
- Status: in_progress
- Owner: Codex
- Source: Next local slice after order backend commit c7fbf430: add backend/admin operability for WeShop_Logistics without touching Theme modules

## Goal

- Make `WeShop_Logistics` backend/admin usable with a backend route, menu entry, tracking list page, tracking create/update flow, and targeted unit coverage.

## Scope

- In scope:
- `app/code/WeShop/Logistics/**` backend/admin services, controllers, templates, menu, env, tests, and i18n
- Out of scope:
- frontend tracking page redesign
- Theme-module source changes
- cross-module shipment integrations

## Constraints

- Keep controllers thin and move list/form shaping to a service.
- Do not touch `WeShop_Theme` or `Weline_Theme`.
- Validate with targeted `php -l`, `phpunit`, and best-effort `setup:upgrade`.

## Related Plans

- WeShop phase-2 module completion wave.

## Related Files

- `app/code/WeShop/Logistics/Service/TrackingService.php`
- `app/code/WeShop/Logistics/Service/TrackingAdminPageDataService.php`
- `app/code/WeShop/Logistics/Controller/Backend/Tracking/Index.php`
- `app/code/WeShop/Logistics/Controller/Backend/Tracking/Save.php`
- `app/code/WeShop/Logistics/etc/env.php`
- `app/code/WeShop/Logistics/etc/backend/menu.xml`
- `app/code/WeShop/Logistics/view/backend/templates/tracking/index.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
