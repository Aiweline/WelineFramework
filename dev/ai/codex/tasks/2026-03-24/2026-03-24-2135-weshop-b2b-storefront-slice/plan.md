# Plan - WeShop B2B storefront slice

## Outcome
- Provide a bounded B2B storefront/account slice with clean routes, default-theme `b2b` page assets, hooks/slots integration, and targeted tests so the module can ship alongside other storefront slices in this wave.

## Steps
- [x] Clarify scope, affected files, and risks for this slice.
- [x] Implement the storefront route controllers/page-data/services plus matching hooks or slots for the default theme.
- [x] Add or update targeted PHPUnit/tests that cover the new B2B storefront or account hook behavior.
- [x] Run validation commands such as `php -l`, relevant unit suites, and sanity-check routes (e.g., `php bin/w setup:upgrade -m WeShop_B2B --dry-run`).
- [ ] Update result.md and memory if needed.

## Verification Targets
- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
