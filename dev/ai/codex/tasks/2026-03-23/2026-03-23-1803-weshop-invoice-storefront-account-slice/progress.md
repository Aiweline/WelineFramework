# Progress - weshop-invoice-storefront-account-slice

- 2026-03-23 18:03 Created the task workspace.
- 2026-03-24 01:xx Confirmed `WeShop_Invoice` only had backend/event/model/service scaffolding and no storefront route or default theme invoice page.
- 2026-03-24 01:xx Added clean router config (`etc/env.php`) and new frontend controller `Controller/Frontend/Invoice/Index.php` with login guard + thin assignment flow.
- 2026-03-24 01:xx Added `InvoicePageDataService` and expanded `InvoiceService` with customer invoice query methods.
- 2026-03-24 01:xx Added account-center injection implementation at `view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml` and related hook docs.
- 2026-03-24 01:xx Added `default` theme storefront page `app/design/WeShop/default/frontend/pages/invoice/index.phtml`.
- 2026-03-24 01:xx Added unit tests for frontend controller and page-data service.
- 2026-03-24 01:xx Ran syntax checks and unit tests. PHPUnit assertions passed (`3 tests / 17 assertions`), but command exits non-zero due repo environment warning (`No code coverage driver available`).
- 2026-03-24 01:xx Ran `setup:upgrade`. `-m WeShop_Invoice` alone failed due cross-module hook specification check for `WeShop_Customer::frontend::account::orders::cards`; rerunning with `-m WeShop_Invoice -m WeShop_Customer` completed invoice route/module refresh successfully.
