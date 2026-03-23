# Plan - weshop-compare-default-theme-hook-completion

## Outcome

- `WeShop_Compare` works end-to-end on the storefront with clean URLs, guest-safe login redirect behavior, compare page rendering on the `default` theme, and compare hooks/entry points wired into existing/default theme slots.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add failing unit tests for compare controllers/page-data/dashboard touchpoints
- [x] Implement compare routes, services, controllers, hooks, and default-theme page
- [x] Patch default-theme/product-category-account compare injection points
- [x] Run validation commands
- [ ] Run targeted validation and live smoke
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit for `WeShop_Compare` and touched `WeShop_Customer`
- [x] Route refresh via `setup:upgrade`
- [ ] Live smoke for `/compare`, `/compare/add`, `/compare/remove` on `9982`
