# Plan - weshop checkout retry product consistency

## Outcome

- A clean checkpoint commit that keeps retry-payment flows on the existing order, aligns product status filtering with current service expectations, and leaves the touched unit/E2E paths green on the current runtime.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [x] E2E / browser flow
