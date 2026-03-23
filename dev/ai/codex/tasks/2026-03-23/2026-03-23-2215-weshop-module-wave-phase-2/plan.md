# Plan - weshop module wave phase 2

## Outcome

- Commit the already-finished default-theme compatibility slice, then move the next wave onto backend/admin completion with `Order` locally and `Promotion` / `Report` in parallel workers, all with targeted tests and documented task state.

## Steps

- [x] Clarify scope, affected files, and risks
- [ ] Implement the `WeShop_Order` backend/admin slice locally
- [x] Review and integrate the `WeShop_Promotion` backend sidecar result
- [x] Review and integrate the `WeShop_Report` backend sidecar result
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
