# Plan - WeShop checkout dynamic method refresh

## Outcome

- Checkout has a dedicated clean-route JSON endpoint for refreshing shipping and payment methods.
- Dynamic method payloads reuse checkout page-data mapping and address-resolution semantics.
- Default-theme checkout refreshes available methods when the selected saved address or inline address fields change.

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
