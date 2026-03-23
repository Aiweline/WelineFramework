# Plan - weshop address storefront slice

## Outcome

- A customer can open `/address`, manage saved delivery addresses inside the default account layout, and the saved-address data remains compatible with checkout.
- The address slice exposes clean storefront routes and account discovery entry points without relying on the old `WeShop_Address` model/session implementation.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
