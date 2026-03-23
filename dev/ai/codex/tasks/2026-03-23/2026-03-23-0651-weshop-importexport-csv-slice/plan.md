# Plan - weshop-importexport-csv-slice

## Outcome

- `WeShop_ImportExport` can export products/orders to CSV files and import products from CSV with deterministic defaults and per-row error reporting.
- Empty backend controllers are replaced with thin controller adapters over the service contract.

## Steps

- [x] Clarify scope, affected files, and risks
- [ ] Add or update tests / verification first
- [ ] Implement the smallest correct change
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
