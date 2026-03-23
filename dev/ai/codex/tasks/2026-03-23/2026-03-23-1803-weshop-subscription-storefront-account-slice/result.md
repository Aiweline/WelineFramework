# Result - weshop subscription storefront account slice

## Outcome

- Completed `WeShop_Subscription` storefront/account slice within scoped paths:
- added clean route metadata (`subscription`)
- moved storefront list/detail to thin controllers with service-backed page-data
- added default-theme subscription pages (`index`, `view`)
- implemented account-center orders-card injection via `WeShop_Customer::frontend::account::orders::cards`
- added module hook definitions/docs for subscription page extension
- added PHPUnit coverage for list page-data normalization

## Changed Files

- `app/code/WeShop/Subscription/etc/env.php`
- `app/code/WeShop/Subscription/hook.php`
- `app/code/WeShop/Subscription/doc/hook/frontend/subscription/page-before.md`
- `app/code/WeShop/Subscription/doc/hook/frontend/subscription/item-after.md`
- `app/code/WeShop/Subscription/doc/hook/frontend/account/orders-cards.md`
- `app/code/WeShop/Subscription/Controller/Frontend/Subscription/Index.php`
- `app/code/WeShop/Subscription/Controller/Frontend/Subscription/SubscriptionList.php`
- `app/code/WeShop/Subscription/Controller/Frontend/Subscription/View.php`
- `app/code/WeShop/Subscription/Controller/Frontend/Subscription/Pause.php`
- `app/code/WeShop/Subscription/Controller/Frontend/Subscription/Cancel.php`
- `app/code/WeShop/Subscription/Service/SubscriptionListPageDataService.php`
- `app/code/WeShop/Subscription/Service/SubscriptionDetailPageDataService.php`
- `app/code/WeShop/Subscription/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`
- `app/code/WeShop/Subscription/view/hooks/Weline_Theme/frontend/layouts/account/content-after.phtml`
- `app/code/WeShop/Subscription/Test/Unit/Service/SubscriptionListPageDataServiceTest.php`
- `app/code/WeShop/Subscription/i18n/en_US.csv`
- `app/code/WeShop/Subscription/i18n/zh_Hans_CN.csv`
- `app/design/WeShop/default/frontend/pages/subscription/index.phtml`
- `app/design/WeShop/default/frontend/pages/subscription/view.phtml`

## Verification

- `php -l` passed for all touched Subscription PHP files and new subscription `.phtml` templates.
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Subscription/Test/Unit/Service/SubscriptionListPageDataServiceTest.php`
  - result: `2 tests`, `20 assertions`, pass (with existing repo deprecation noise).
- `php bin/w setup:upgrade -m WeShop_Subscription --yes`
  - module registration/hook scan for this slice passed after local hook fixes.
  - command finally failed at framework/global stage due unrelated environment issue: `SQLite 数据库连接适配器已停止使用，请使用 Pgsql`.

## Remaining Risks

- Shared host account page does not yet provide dedicated subscription metrics in dashboard aggregate data; current orders-card hook computes count internally in the hook template to avoid host-file edits.
- No browser e2e run in this subtask.
- `setup:upgrade` cannot fully complete until global SQLite adapter environment issue is resolved.

## Next Resume Step

- After global `setup:upgrade` environment issue is fixed, re-run module upgrade and smoke-check `/subscription` + `/subscription/view?id=...` on runtime port `9982`.
