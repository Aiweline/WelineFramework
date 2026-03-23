# Progress - weshop-notification-storefront-slice

- 2026-03-23 16:41 Created the task workspace.
- 2026-03-24 Refactored `Controller/Frontend/Notification/Index.php` into a thin storefront controller backed by `CustomerContextInterface` and `NotificationPageDataService`.
- 2026-03-24 Refactored `Controller/Frontend/Notification/MarkRead.php` to use dependency injection, customer ownership checks, and structured JSON redirect data for unauthenticated storefront requests.
- 2026-03-24 Extended `NotificationService` with clean list, unread-count, and mark-as-read helpers suitable for storefront controllers.
- 2026-03-24 Added clean `notification` router metadata, a `default`-theme notification page, and a discovery-card hook implementation for `WeShop_Customer::frontend::account::discovery::cards`.
- 2026-03-24 Added targeted unit coverage for notification page-data mapping and storefront controller flows.
- 2026-03-24 Validation summary:
- `php -l app/code/WeShop/Notification/Service/NotificationPageDataService.php`
- `php vendor/bin/phpunit app/code/WeShop/Notification/Test/Unit --colors=never` (assertions passed; existing repo warning about missing code coverage driver keeps exit code non-zero)
