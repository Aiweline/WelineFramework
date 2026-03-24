# Plan - certificate-cron-invalid-cert-reapply

## Outcome

- High-frequency certificate maintenance now verifies managed certificate health before requesting, so invalid certificates can fall back and re-enter the request flow in the same cron run.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
