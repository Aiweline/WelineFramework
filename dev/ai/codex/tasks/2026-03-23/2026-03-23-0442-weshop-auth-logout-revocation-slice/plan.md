# Plan - weshop auth logout revocation slice

## Outcome

- Logout invalidates the actor's active token pair instead of leaving refresh valid.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing unit coverage for logout revocation semantics
- [x] Implement the smallest correct service fix
- [x] Run validation commands
- [ ] Commit the slice and update result.md with the commit hash

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
