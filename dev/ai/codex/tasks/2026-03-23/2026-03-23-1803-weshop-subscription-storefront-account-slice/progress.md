# Progress - weshop subscription storefront account slice

- 2026-03-23 18:03 Created the task workspace.
- 2026-03-23 18:06 Audited existing `WeShop_Subscription` implementation and confirmed missing clean route, heavy controllers, and no default-theme subscription pages.
- 2026-03-23 18:12 Added `etc/env.php` router (`subscription`), new page-data services, and refactored frontend controllers (`Index`, `View`, compatibility `SubscriptionList`) to thin controller style.
- 2026-03-23 18:18 Added account-center order-card hook implementation at `view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`; kept legacy `Weline_Theme` hook file as compatibility shim.
- 2026-03-23 18:23 Added default-theme pages `pages/subscription/index.phtml` and `pages/subscription/view.phtml`, plus module hook config/docs and updated i18n entries.
- 2026-03-23 18:27 Added PHPUnit suite `SubscriptionListPageDataServiceTest` and ran syntax + unit verification.
- 2026-03-23 18:32 Ran `setup:upgrade -m WeShop_Subscription --yes`; fixed hook naming/doc metadata issues in this slice; final attempt progressed through module refresh but failed on unrelated global SQLite adapter deprecation in framework upgrade stage.
