# Plan - weshop-giftcard-storefront-slice

## Outcome

- Deliver a production-usable GiftCard storefront slice under default theme and account center hook host.

## Steps

- [x] Audit current GiftCard module gaps and reference completed storefront slices
- [x] Add failing unit tests for page-data/controller and GiftCard summary behavior
- [x] Implement GiftCard route/controller/page-data/service/query behavior
- [x] Add hook definitions/docs and account-center card injection view
- [x] Add default theme gift-card page and verify syntax/tests
- [ ] Commit scoped changes

## Verification Targets

- [x] `php -l` on touched GiftCard PHP and new default theme page
- [x] `php vendor/bin/phpunit app/code/WeShop/GiftCard/Test/Unit --colors=never`
