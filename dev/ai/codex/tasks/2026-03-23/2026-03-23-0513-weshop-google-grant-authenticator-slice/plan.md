# Plan - weshop google grant authenticator slice

## Outcome

- `AuthGrantService` delegates all credential entry points, including Google code login, to dedicated collaborators and stays focused on 2FA/token orchestration.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing unit coverage for the Google grant extraction
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
