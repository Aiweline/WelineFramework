# Plan - weshop storefront module wave promotion-b2b-and-next

## Outcome

- Finish the current uncommitted WeShop storefront hardening slices cleanly, then use focused audits to pick the next independent module wave that improves real storefront/theme/backend/API completeness.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Harden the Promotion storefront slice and commit it separately
- [x] Harden the B2B storefront slice and commit it separately
- [x] Run targeted syntax / PHPUnit / setup-upgrade validation and record the environmental blockers
- [x] Audit the next default-theme hook gaps and incomplete module slices
- [ ] Implement the next module slice from the audit queue
- [ ] Update result.md and memory when this broader wave pauses or completes

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / setup-upgrade signal
- [ ] E2E / browser flow
