# Plan - weshop auth controller contract slice

## Outcome

- Route-specific auth API endpoints behave according to their own contract instead of delegating too much logic to the generic token endpoint.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing controller tests for the route-specific auth contract
- [x] Implement the smallest correct controller fix
- [x] Run validation commands
- [ ] Commit the slice and update result.md with the commit hash

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
