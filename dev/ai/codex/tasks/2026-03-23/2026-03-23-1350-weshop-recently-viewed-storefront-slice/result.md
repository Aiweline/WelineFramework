# Result - weshop recently viewed storefront slice

## Outcome

- Completed the `WeShop_RecentlyViewed` storefront slice for the `default` theme.
- Added a clean `/recently-viewed` route with guest-safe login redirect behavior, an AJAX remove endpoint, a service-backed history page, and logged-in product-view recording integration from the product detail controller.
- Updated account-center quick links and WeShop roadmap/acceptance/test docs so the slice is now part of the active storefront baseline.

## Changed Files

- `app/code/WeShop/RecentlyViewed/register.php`
- `app/code/WeShop/RecentlyViewed/etc/env.php`
- `app/code/WeShop/RecentlyViewed/Service/RecentlyViewedService.php`
- `app/code/WeShop/RecentlyViewed/Service/RecentlyViewedPageDataService.php`
- `app/code/WeShop/RecentlyViewed/Service/StorefrontRecentlyViewedRecorder.php`
- `app/code/WeShop/RecentlyViewed/Controller/Frontend/RecentlyViewed/Index.php`
- `app/code/WeShop/RecentlyViewed/Controller/Frontend/RecentlyViewed/Remove.php`
- `app/code/WeShop/RecentlyViewed/Controller/Index.php`
- `app/code/WeShop/RecentlyViewed/Controller/Remove.php`
- `app/code/WeShop/RecentlyViewed/Test/Unit/Service/RecentlyViewedPageDataServiceTest.php`
- `app/code/WeShop/RecentlyViewed/Test/Unit/Service/StorefrontRecentlyViewedRecorderTest.php`
- `app/code/WeShop/RecentlyViewed/Test/Unit/Controller/Frontend/RecentlyViewed/IndexTest.php`
- `app/code/WeShop/RecentlyViewed/Test/Unit/Controller/Frontend/RecentlyViewed/RemoveTest.php`
- `app/code/WeShop/RecentlyViewed/i18n/en_US.csv`
- `app/code/WeShop/RecentlyViewed/i18n/zh_Hans_CN.csv`
- `app/code/WeShop/Product/Controller/Frontend/Product/View.php`
- `app/code/WeShop/Product/Test/Unit/Controller/Frontend/Product/ViewTest.php`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- `app/design/WeShop/default/frontend/pages/recently-viewed/index.phtml`
- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Verification

- `php -l app/code/WeShop/RecentlyViewed/Service/RecentlyViewedService.php`
- `php -l app/code/WeShop/RecentlyViewed/Service/RecentlyViewedPageDataService.php`
- `php -l app/code/WeShop/RecentlyViewed/Controller/Frontend/RecentlyViewed/Index.php`
- `php -l app/code/WeShop/RecentlyViewed/Controller/Frontend/RecentlyViewed/Remove.php`
- `php -l app/code/WeShop/Product/Controller/Frontend/Product/View.php`
- `php -l app/code/WeShop/Product/Test/Unit/Controller/Frontend/Product/ViewTest.php`
- `php vendor/bin/phpunit app/code/WeShop/RecentlyViewed/Test/Unit`
  - Assertions passed: `7 tests / 28 assertions`
  - PHPUnit exit still carries repo/environment warning: `No code coverage driver available`
- `php vendor/bin/phpunit app/code/WeShop/Product/Test/Unit/Controller/Frontend/Product/ViewTest.php`
  - Logic no longer errors after constructor change
  - Still contains `3` pre-existing `Incomplete` tests
- `php vendor/bin/phpunit app/code/WeShop/Customer/Test/Unit/Service/AccountDashboardDataServiceTest.php app/code/WeShop/Customer/Test/Unit/Controller/Frontend/Account/IndexTest.php`
  - Assertions passed with the same coverage warning
- `php bin/w setup:upgrade -m WeShop_RecentlyViewed -m WeShop_Product --yes`
  - Route refresh succeeded
  - Existing unrelated warning remains: `AclOrphanCleanupService::buildNonUserAclQuery()` return-type mismatch during ACL orphan cleanup
  - Existing unrelated empty-i18n notices remain across other modules
- Live smoke while WLS was running on `9982`
  - `GET /recently-viewed` -> `301` guest redirect to localized login page
  - `POST /recently-viewed/remove` with AJAX headers -> JSON guest redirect payload

## Remaining Risks

- No logged-in browser/e2e fixture was available in this slice, so the rendered history page and product-view persistence were not verified with a real authenticated customer session.
- `WeShop\Product\Controller\Frontend\Product\View` still contains broader legacy ObjectManager-heavy logic outside the new recorder hook.
- Header account dropdown still has older WeShop routes in its template; this slice only updated account-center quick links and the dedicated account page links.

## Next Resume Step

- Start the next storefront completion slice, with `WeShop_Compare` as the best adjacent candidate to pair with the new account/discovery and recommendation flows.
