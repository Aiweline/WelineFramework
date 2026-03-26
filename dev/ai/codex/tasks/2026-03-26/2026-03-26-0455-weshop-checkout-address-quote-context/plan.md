# Plan - WeShop checkout address quote context

## Outcome

- Checkout uses the saved default address when loading shipping methods.
- Place-order quote calculation resolves `shipping_address_id` into shipping/tax address context.
- Focused phpunit and storefront smoke are green on the `9982` runtime.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [x] E2E / browser flow
