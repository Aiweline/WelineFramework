# Plan - weshop-next-modules-parallel-slices

## Outcome

- Parallelize the next WeShop storefront completion wave so `Review`, `QA`, `RMA`, and `Notification` can advance independently while shared product-detail/default-theme integration stays ready for safe module injection.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Launch and supervise the first worker wave (`Review`, `QA`)
- [x] Patch shared product-detail/default-theme integration points for review + QA rendering
- [x] Launch the second worker wave (`RMA`, `Notification`)
- [ ] Ship QA-specific storefront slice (controllers, service, page, i18n, tests)
- [x] Commit the shared theme/docs checkpoint
- [ ] Refactor shared `WeShop_Product` storefront controller glue so module slices no longer depend on controller-level TODO logic
- [ ] Integrate or mirror worker outcomes into the main branch
- [ ] Add or update tests / verification
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
