# Plan - weshop auth token endpoint contract slice

## Outcome

- `Auth` and `Challenge` REST controllers have explicit unit coverage for grant dispatch, missing-parameter validation, and challenge exchange success behavior.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing controller tests
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
