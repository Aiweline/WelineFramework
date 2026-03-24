# Plan - weshop-checkout-payment-dynamic-layout

## Outcome

- Checkout payment methods are rendered through reusable checkout hook hosts with payment data still coming from `w_query()` composition.

## Steps

- [x] Clarify checkout/payment/theme scope and risks
- [x] Implement checkout payment hook hosts and normalized page data
- [x] Update default theme checkout page and layout variants to use the new hosts
- [x] Add or update focused unit tests
- [x] Run validation commands
- [x] Record results and remaining gaps

## Verification Targets

- [x] Unit / phpunit
- [x] Syntax / php -l
- [ ] Route / live smoke if runtime is available on 9982
