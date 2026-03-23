# Progress - weshop order backend slice

- 2026-03-24 10:xx Created the dedicated order-backend task workspace after already starting implementation under the phase-2 umbrella task.
- 2026-03-24 10:xx Confirmed the backend gap: empty `Index.php`, empty `UpdateStatus.php`, no backend menu, blank backend template, and no backend unit coverage.
- 2026-03-24 10:xx Added `OrderService` admin helpers (`getAvailableStatuses`, `isValidStatus`, `getOrders`, `getOrderSummary`) and the new `OrderAdminPageDataService` to keep controllers thin.
- 2026-03-24 10:xx Replaced the backend controllers with `BaseController` implementations for list/detail/status-update flows and created backend templates for the order list and order detail pages.
- 2026-03-24 10:xx Added backend menu wiring plus unit tests for the admin page-data service and backend controllers.
- 2026-03-24 10:xx Validation passed for targeted syntax and `php vendor/bin/phpunit --no-coverage app/code/WeShop/Order/Test/Unit --colors=never`; `setup:upgrade -m WeShop_Order --yes` later failed on the repo-wide/global schema issue `未知的索引类型：BTREE`.

- 2026-03-23 22:44 Created the task workspace.
