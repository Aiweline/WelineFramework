# Result - WeShop order summary persistence

## Outcome

- Completed the bounded order-summary persistence slice.
- Checkout-computed `subtotal`, `shipping_amount`, `discount_amount`, and `tax_amount` now persist
  into the order record, and both retry-payment context rebuilding and success-page fallback reads
  use the persisted values instead of zero defaults.

## Changed Files

- `app/code/WeShop/Order/Model/Order.php`
- `app/code/WeShop/Order/Service/OrderService.php`
- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/OrderSuccessPageDataService.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/OrderSuccessPageDataServiceTest.php`
- `app/code/WeShop/Order/Test/Unit/Service/OrderServiceTest.php`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php app/code/WeShop/Checkout/Test/Unit/Service/OrderSuccessPageDataServiceTest.php app/code/WeShop/Order/Test/Unit/Service/OrderServiceTest.php --colors=never`
  Result: `11 tests / 79 assertions`, existing PHPUnit deprecation unchanged.
- `php bin/w setup:upgrade WeShop_Order --yes`
  Result: passed; order schema updated and framework registries refreshed.
- `PLAYWRIGHT_RUNTIME_STRATEGY=wls PLAYWRIGHT_E2E_TRANSPORT=direct node tests/e2e/start.js specs/frontend/weshop-order-checkout-clean-routes.spec.js`
  Result: `2 passed` against `https://127.0.0.1:9982`.

## Remaining Risks

- Success-page correctness is now preserved without checkout context, but there is still no
  dedicated logged-in browser spec that completes checkout and asserts persisted totals end to end.
- Checkout shipping-method availability is still not fully address-aware on the initial page-data
  path; that is a separate bounded slice.

## Next Resume Step

- Continue the checkout/shipping integration follow-up: resolve saved-address shipping/tax context
  into initial checkout page-data and shipping-method selection.
