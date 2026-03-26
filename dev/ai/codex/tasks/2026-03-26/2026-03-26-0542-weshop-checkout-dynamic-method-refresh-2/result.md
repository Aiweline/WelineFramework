# Result - WeShop checkout dynamic method refresh

## Outcome

- Completed the checkout dynamic method refresh slice.
- The checkout stack now exposes `/checkout/methods`, which returns address-aware shipping and payment methods for the logged-in customer using the same page-data/service semantics as the initial checkout render.
- The default-theme checkout page now refreshes shipping/payment sections when the selected saved address or inline shipping fields change, instead of leaving stale methods on screen.

## Changed Files

- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `app/code/WeShop/Checkout/Controller/Methods.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/CleanRouteAliasControllersTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `tests/e2e/specs/frontend/weshop-order-checkout-clean-routes.spec.js`

## Verification

- `php -l app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `php -l app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `php -l app/code/WeShop/Checkout/Controller/Methods.php`
- `php -l app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php`
- `php -l app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `php -l app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit/Controller/CleanRouteAliasControllersTest.php app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php --colors=never`
- `$env:PLAYWRIGHT_RUNTIME_STRATEGY='wls'; $env:PLAYWRIGHT_E2E_TRANSPORT='direct'; node tests/e2e/start.js specs/frontend/weshop-order-checkout-clean-routes.spec.js`

## Remaining Risks

- This slice refreshes method availability, but the visible checkout order-summary amounts are still static. The next checkout follow-up should decide whether shipping/tax/grand total preview needs the same dynamic refresh path.
- There is still no logged-in browser e2e that switches between multiple saved addresses and proves the refreshed methods on a real customer session.

## Next Resume Step

- Continue with either 1) checkout summary preview refresh aligned to shipping/tax rules or 2) a logged-in multi-address checkout browser spec to validate the new dynamic endpoint in a realistic flow.
