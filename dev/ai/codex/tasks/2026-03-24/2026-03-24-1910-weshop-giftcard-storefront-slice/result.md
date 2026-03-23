# Result - weshop-giftcard-storefront-slice

## Summary

- Completed GiftCard storefront/default-theme/account-center slice.
- Added clean storefront route (`gift-card`) with thin frontend controller and root alias controller.
- Added GiftCard page-data service, upgraded GiftCard service/model for customer-scoped cards and summary aggregation, and added account-center discovery hook card plus default theme gift-card page.
- Added unit tests for page-data, controller, and GiftCard summary calculation.

## Changed Files

- `app/code/WeShop/GiftCard/Controller/Frontend/GiftCard/Index.php`
- `app/code/WeShop/GiftCard/Controller/Index.php`
- `app/code/WeShop/GiftCard/Model/GiftCard.php`
- `app/code/WeShop/GiftCard/Service/GiftCardService.php`
- `app/code/WeShop/GiftCard/Service/GiftCardPageDataService.php`
- `app/code/WeShop/GiftCard/hook.php`
- `app/code/WeShop/GiftCard/etc/env.php`
- `app/code/WeShop/GiftCard/view/hooks/WeShop_Customer/frontend/account/discovery/cards.phtml`
- `app/code/WeShop/GiftCard/doc/hook/frontend/gift-card/page-before.md`
- `app/code/WeShop/GiftCard/doc/hook/frontend/gift-card/item-after.md`
- `app/design/WeShop/default/frontend/pages/gift-card/index.phtml`
- `app/code/WeShop/GiftCard/i18n/en_US.csv`
- `app/code/WeShop/GiftCard/i18n/zh_Hans_CN.csv`
- `app/code/WeShop/GiftCard/Test/Unit/Service/GiftCardPageDataServiceTest.php`
- `app/code/WeShop/GiftCard/Test/Unit/Service/GiftCardServiceTest.php`
- `app/code/WeShop/GiftCard/Test/Unit/Controller/Frontend/GiftCard/IndexTest.php`

## Verification

- `php -l app/code/WeShop/GiftCard/Model/GiftCard.php`
- `php -l app/code/WeShop/GiftCard/Service/GiftCardService.php`
- `php -l app/code/WeShop/GiftCard/Service/GiftCardPageDataService.php`
- `php -l app/code/WeShop/GiftCard/Controller/Frontend/GiftCard/Index.php`
- `php -l app/code/WeShop/GiftCard/Controller/Index.php`
- `php -l app/code/WeShop/GiftCard/hook.php`
- `php -l app/code/WeShop/GiftCard/Test/Unit/Service/GiftCardPageDataServiceTest.php`
- `php -l app/code/WeShop/GiftCard/Test/Unit/Service/GiftCardServiceTest.php`
- `php -l app/code/WeShop/GiftCard/Test/Unit/Controller/Frontend/GiftCard/IndexTest.php`
- `php -l app/design/WeShop/default/frontend/pages/gift-card/index.phtml`
- `php vendor/bin/phpunit app/code/WeShop/GiftCard/Test/Unit --colors=never`
  - Assertions passed (`Tests: 4, Assertions: 20`)
  - PHPUnit exits non-zero due repo/global warning: `No code coverage driver available`
- `php bin/w setup:upgrade -m WeShop_GiftCard --yes`
  - GiftCard route/hook/tag scanning path succeeded
  - command failed later due unrelated global environment error: `SQLite 数据库连接适配器已停止使用，请使用 Pgsql。`

## Risks / Follow-ups

- GiftCard model now includes `customer_id`; schema migration requires a healthy `setup:upgrade` runtime (currently blocked by unrelated SQLite adapter path in another module/service).
