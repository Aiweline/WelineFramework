# Result - weshop wishlist storefront slice

## Outcome

- The wishlist storefront slice is functionally complete for guest/runtime access in the `default` theme.
- `default` theme now has a service-backed wishlist page, guest-safe add/remove responses, and short public wishlist routes without exposing `frontend/...` in storefront URLs.
- The slice also fixed a real runtime DI gap by binding `CustomerContextInterface` to `CustomerContext`, which unblocks all WeShop storefront controllers that now depend on that interface.

## Changed Files

- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Add.php`
- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Index.php`
- `app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Remove.php`
- `app/code/WeShop/Wishlist/Controller/Add.php`
- `app/code/WeShop/Wishlist/Controller/Index.php`
- `app/code/WeShop/Wishlist/Controller/Remove.php`
- `app/code/WeShop/Wishlist/Service/WishlistPageDataService.php`
- `app/code/WeShop/Wishlist/Service/WishlistService.php`
- `app/code/WeShop/Wishlist/etc/env.php`
- `app/design/WeShop/default/frontend/pages/wishlist/index.phtml`
- `app/code/WeShop/Customer/Api/CustomerContextInterfaceFactory.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/AddTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/IndexTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/RemoveTest.php`
- `app/code/WeShop/Wishlist/Test/Unit/Service/WishlistPageDataServiceTest.php`

## Verification

- `php -l app/code/WeShop/Wishlist/Service/WishlistPageDataService.php`
- `php -l app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Index.php`
- `php -l app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Add.php`
- `php -l app/code/WeShop/Wishlist/Controller/Frontend/Wishlist/Remove.php`
- `php -l app/code/WeShop/Wishlist/Service/WishlistService.php`
- `php -l app/code/WeShop/Wishlist/etc/env.php`
- `php -l app/code/WeShop/Wishlist/Controller/Index.php`
- `php -l app/code/WeShop/Wishlist/Controller/Add.php`
- `php -l app/code/WeShop/Wishlist/Controller/Remove.php`
- `php -l app/code/WeShop/Customer/Api/CustomerContextInterfaceFactory.php`
- `php -l app/design/WeShop/default/frontend/pages/wishlist/index.phtml`
- `php vendor/bin/phpunit app/code/WeShop/Wishlist/Test/Unit/Service/WishlistPageDataServiceTest.php app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/IndexTest.php app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/AddTest.php app/code/WeShop/Wishlist/Test/Unit/Controller/Frontend/Wishlist/RemoveTest.php --colors=never`
  - Assertions passed (`7` tests / `31` assertions); PHPUnit still exits with the repo-wide `No code coverage driver available` warning.
- `php bin/w setup:upgrade -m WeShop_Wishlist --yes`
- `php bin/w setup:upgrade -m WeShop_Customer -m WeShop_Wishlist --yes`
  - Both upgrades succeeded; the environment still emits unrelated repo-wide warnings such as the existing ACL orphan cleanup issue and empty i18n CSV notices.
- `php bin/w http:req wishlist -P=9982 --https`
  - Guest request reaches the wishlist route and redirects to the localized storefront login page.
- `php bin/w http:req wishlist/add -P=9982 --https -m=POST -d='product_id=1'`
  - Guest request returns JSON with `success=false` and a valid localized `redirect_url` to login.
- `php bin/w http:req wishlist/remove -P=9982 --https -m=POST -d='wishlist_id=1'`
  - Guest request returns JSON with `success=false` and a valid localized `redirect_url` to login.

## Remaining Risks

- Logged-in browser e2e coverage for wishlist add/remove/page rendering is still missing in this slice.
- The broader repo still contains older storefront links that point at `weshop/customer/account/login`; those were not normalized here outside the wishlist/runtime contract needed for this slice.
- Framework upgrade still reports an unrelated ACL orphan cleanup type error and multiple pre-existing empty i18n CSV notices.

## Next Resume Step

- Stage only the wishlist/customer-context whitelist, commit this slice, then continue with the next storefront customer-facing module slice (likely recently-viewed or compare) using the same short-route pattern.
