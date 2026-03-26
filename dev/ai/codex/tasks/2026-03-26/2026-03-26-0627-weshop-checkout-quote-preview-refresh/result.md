# Result - WeShop checkout quote preview refresh

## Outcome

- Completed the checkout quote preview refresh slice.
- The existing `/checkout/methods` JSON flow now returns `cart_summary` so the frontend can refresh shipping, tax, discount, subtotal, and grand total without adding a second quote endpoint.
- The default-theme checkout page now exposes stable summary DOM anchors and updates visible amounts when the shipping address or shipping method changes.
- Retry-payment preview stays pinned to the persisted order summary instead of recalculating a new quote.

## Changed Files

- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0627-weshop-checkout-quote-preview-refresh/task.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0627-weshop-checkout-quote-preview-refresh/plan.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0627-weshop-checkout-quote-preview-refresh/progress.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0627-weshop-checkout-quote-preview-refresh/result.md`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php --colors=never`
- `php -l app/code/WeShop/Checkout/Service/CheckoutService.php`
- `php -l app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `php -l app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `php -l app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `$env:PLAYWRIGHT_RUNTIME_STRATEGY='wls'; $env:PLAYWRIGHT_E2E_TRANSPORT='direct'; node tests/e2e/start.js specs/frontend/weshop-order-checkout-clean-routes.spec.js`

## Remaining Risks

- There is still no logged-in browser e2e that proves live summary changes on a real checkout session after switching addresses or shipping methods.
- The initial page render still comes from the existing checkout page-data path; this slice only guarantees live refresh consistency after user interaction.

## Next Resume Step

- Add a logged-in checkout e2e that switches shipping methods or saved addresses and asserts the refreshed summary values on the real page.
- Then continue the broader checkout/account-center lane or move to the next unfinished WeShop module group.
