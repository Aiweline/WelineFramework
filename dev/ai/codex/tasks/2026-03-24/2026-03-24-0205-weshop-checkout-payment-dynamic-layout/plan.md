# Plan - weshop-checkout-payment-dynamic-layout

## Outcome

- Checkout payment methods are rendered through reusable checkout hook hosts with payment data still coming from `w_query()` composition.

## Steps

- [x] Clarify checkout/payment/theme scope and risks
- [ ] Implement checkout payment hook hosts and normalized page data
- [ ] Update default theme checkout page and layout variants to use the new hosts
- [ ] Add or update focused unit tests
- [ ] Run validation commands
- [ ] Record results and remaining gaps

## Verification Targets

- [ ] Unit / phpunit
- [ ] Syntax / php -l
- [ ] Route / live smoke if runtime is available on 9982
