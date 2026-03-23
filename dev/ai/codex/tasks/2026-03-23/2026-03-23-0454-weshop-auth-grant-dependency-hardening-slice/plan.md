# Plan - weshop auth grant dependency hardening slice

## Outcome

- `AuthGrantService` becomes a thin coordinator for backend and integration grants, with credential lookup logic moved into dedicated services and covered by unit tests.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing unit coverage for the service decomposition
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
