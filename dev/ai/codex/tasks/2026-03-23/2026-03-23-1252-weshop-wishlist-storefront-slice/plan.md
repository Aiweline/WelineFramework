# Plan - weshop wishlist storefront slice

## Outcome

- Ship a validated wishlist storefront slice with short public routes, service-backed page data, and guest-safe add/remove/login flows for the `default` theme.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the storefront controller, page data, theme template, and route changes
- [x] Add or update targeted unit tests for guest and logged-in flows
- [x] Run syntax, PHPUnit, module upgrade, and runtime route verification on `9982`
- [ ] Update result.md and memory if needed
- [ ] Stage only the wishlist slice and commit it

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
