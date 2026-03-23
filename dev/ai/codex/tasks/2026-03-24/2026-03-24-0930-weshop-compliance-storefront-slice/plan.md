# Plan - weshop-compliance-storefront-slice

## Outcome

- `/compliance`, `/compliance/consent`, and `/compliance/privacy` storefront flows are available under default theme.
- Consent management can be viewed and updated through a thin controller + service layer with tests.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add failing unit tests for page-data/controller/save flow
- [x] Implement router/controllers/page-data/hooks/docs/default-theme pages
- [x] Add account-center hook entry card
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / setup:upgrade
- [ ] E2E / browser flow
