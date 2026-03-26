# Plan - WeShop checkout quote preview refresh

## Outcome

- Reuse the existing checkout methods refresh endpoint so method changes also return an updated summary preview that matches the effective shipping method and retry-payment rules.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add or update tests for preview payload and summary anchors
- [x] Implement the smallest correct change in checkout service/page-data/template
- [x] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
