# Plan - weshop recently viewed storefront slice

## Outcome

- `WeShop_RecentlyViewed` exposes a clean storefront page on the `default` theme, backed by a service layer and tests.
- Product detail views record recently viewed items for logged-in customers without coupling Theme modules.
- Account-center discovery and roadmap docs reflect the newly completed slice.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add failing tests for page data, login gating, and product-view recording
- [x] Implement RecentlyViewed routes, controllers, services, and theme page
- [x] Wire product-view recording and account/discovery links
- [x] Run validation commands and document results

## Verification Targets

- [x] Unit / phpunit
- [x] Route / setup:upgrade / live HTTP check on `9982`
- [ ] Browser smoke validation for the storefront route
