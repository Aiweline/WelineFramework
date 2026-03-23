# Plan - weshop default-theme host slot normalization product-catalog-cart

## Outcome

- Add the minimum modern host hooks to the `default` theme's product and cart pages so post-add-to-cart, popup, and cart-sidebar style extensions can inject cleanly without breaking the existing storefront markup.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change on product/cart pages
- [x] Add or update template-level regression tests
- [x] Run validation commands
- [ ] Decide whether to extend the same slice into Catalog/Filters or checkpoint the product/cart-only result
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
