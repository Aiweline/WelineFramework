# Plan - WeShop checkout default address selection

## Outcome

- Checkout page-data exposes the primary saved address id.
- Default-theme checkout radios preselect that id instead of always selecting the first address.
- Focused phpunit and checkout storefront smoke remain green on `9982`.

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
