# Plan - WeShop effective price filters

## Outcome

- Product-level fallback search/filter/stat logic uses effective price consistently with indexed
  search documents and storefront rendering.
- Category/search fallback paths no longer drift when products have discounted effective price.

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
