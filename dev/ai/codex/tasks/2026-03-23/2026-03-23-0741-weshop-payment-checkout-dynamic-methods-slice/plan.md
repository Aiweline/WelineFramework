# Plan - weshop-payment-checkout-dynamic-methods-slice

## Outcome

- Checkout renders payment methods from `w_query('payment', 'getCheckoutPaymentMethods', ...)`.
- `CheckoutService::placeOrder()` validates input, creates the order from cart, triggers payment processing, and returns structured payment/order data.
- `default` checkout layout variants render controller `content` first, so the dynamic checkout page works across layout variants.
- Targeted unit tests cover payment registry/query behavior and checkout flow wiring.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add failing tests for payment registry/query provider and checkout flow/controller wiring
- [x] Implement payment registry + query provider + checkout page data service + real place-order flow
- [x] Update `default` checkout page and layout variants so dynamic controller content is primary
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Focused PHPUnit for `WeShop_Payment` slice tests (`PaymentServiceTest`, `PaymentQueryProviderTest`, `ProcessTest`, `CallbackTest`)
- [x] Focused PHPUnit for `WeShop_Checkout` slice tests (`CheckoutServiceTest`, `CheckoutPageDataServiceTest`)
- [x] `php -l` on key service files
- [x] Checkout/payment route smoke validation on runtime port `9982`
- [x] Browser/e2e gap recorded if full login checkout automation cannot be completed in this slice
