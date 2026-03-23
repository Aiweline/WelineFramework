# Progress - weshop logistics backend slice

- 2026-03-24 11:xx Created the logistics-backend task workspace after the order backend commit `c7fbf430`.
- 2026-03-24 11:xx Confirmed the module gap: frontend-only tracking view, no `etc/env.php`, no backend menu/controllers/templates, and no unit tests.
- 2026-03-24 11:xx Replaced `TrackingService` with a fuller admin-capable version and added `TrackingAdminPageDataService` for backend page shaping.
- 2026-03-24 11:xx Added backend router/menu/controller/template support for listing and maintaining tracking events from the admin area.
- 2026-03-24 11:xx Added focused controller/service unit tests and refreshed module i18n strings.
- 2026-03-24 11:xx Targeted syntax checks and `php vendor/bin/phpunit --no-coverage app/code/WeShop/Logistics/Test/Unit --colors=never` passed; `setup:upgrade -m WeShop_Logistics --yes` later failed on the same repo-wide/global schema issue `未知的索引类型：BTREE`.

- 2026-03-23 22:51 Created the task workspace.
