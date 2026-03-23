# Plan - weshop-affiliate-storefront-slice

## Outcome

- Deliver a storefront-usable Affiliate module slice under default theme with account-center entry injection.

## Steps

- [x] Audit current Affiliate module and existing account host hook patterns
- [x] Add failing unit tests for Affiliate page-data/controller/service summary behavior
- [x] Implement Affiliate route/controller/page-data/service behavior
- [x] Add hook manifest/docs and account-center discovery card injection
- [x] Add default theme affiliate page and verify syntax/tests
- [ ] Commit scoped changes

## Verification Targets

- [x] `php -l` for touched Affiliate/default-theme files
- [x] `php vendor/bin/phpunit app/code/WeShop/Affiliate/Test/Unit --colors=never`
