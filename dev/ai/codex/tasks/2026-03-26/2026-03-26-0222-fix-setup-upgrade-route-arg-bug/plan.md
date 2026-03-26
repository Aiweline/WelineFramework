# Plan - fix setup-upgrade route arg bug

## Outcome

- `setup:upgrade --route` no longer fails during supported-argument validation when the CLI parser provides prefixed keys like `--route`.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
