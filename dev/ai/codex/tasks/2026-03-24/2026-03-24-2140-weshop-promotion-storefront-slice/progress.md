# Progress - weshop promotion storefront slice

- 2026-03-24 21:40 Created task workspace and started Promotion storefront audit.
- 2026-03-24 21:43 Confirmed key gaps: empty coupon apply controller, heavy promotion index controller, no default-theme promotion pages under `pages/promotion`, and thin test coverage only for class existence/layout property.
- 2026-03-24 21:51 Added `PromotionPageDataService` and refactored `Promotion\Index` to thin controller with `index/deals/sale` actions reusing a single render flow.
- 2026-03-24 21:55 Implemented `Coupon\Apply` storefront endpoint for JSON coupon validation/apply flow.
- 2026-03-24 21:58 Added default-theme pages: `pages/promotion/index.phtml`, `deals.phtml`, `sale.phtml`.
- 2026-03-24 22:02 Added Promotion hook specs/docs using framework-compliant hook names and corresponding hook templates for page-before/page-after slots.
- 2026-03-24 22:05 Added and updated focused unit tests (`PromotionPageDataServiceTest`, upgraded `IndexTest`) and i18n for new storefront copy.
- 2026-03-24 22:07 Validation:
  - `php -l` passed for touched Promotion PHP and default-theme promotion pages.
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/Promotion/Test/Unit --colors=never` passed (`3` tests, `16` assertions; existing PHPUnit deprecation noise remains).
  - `php bin/w setup:upgrade -m WeShop_Promotion --yes` passed module hook/registry checks for this slice; command later failed at unrelated global SQLite adapter deprecation (`use Pgsql`).
- 2026-03-24 23:28 Resumed the slice to fix a runtime defect in `Coupon\Apply`: it had been reading a non-existent session API (`getLoginCustomerId()`), which would break real coupon requests in storefront checkout/account flows.
- 2026-03-24 23:31 Refactored `Coupon\Apply` to depend on `CustomerContextInterface`, keeping guest-safe fallback to customer id `0` while aligning the controller with the newer WeShop auth/context pattern.
- 2026-03-24 23:34 Added focused controller coverage in `ApplyTest` for both the required-code error path and the logged-in coupon-apply success path.
- 2026-03-24 23:36 Re-validated the slice:
  - `php -l app/code/WeShop/Promotion/Controller/Frontend/Coupon/Apply.php`
  - `php -l app/code/WeShop/Promotion/Test/Unit/Controller/Frontend/Coupon/ApplyTest.php`
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/Promotion/Test/Unit/Controller/Frontend/Coupon/ApplyTest.php app/code/WeShop/Promotion/Test/Unit/Controller/Frontend/Promotion/IndexTest.php app/code/WeShop/Promotion/Test/Unit/Service/PromotionPageDataServiceTest.php --colors=never` passed (`5` tests, `24` assertions; one existing PHPUnit deprecation remains).
