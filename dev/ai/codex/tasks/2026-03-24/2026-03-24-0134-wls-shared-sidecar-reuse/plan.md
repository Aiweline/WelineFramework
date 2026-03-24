# Plan - wls shared sidecar reuse

## Outcome

- New WLS instances reuse matching shared Session/Memory sidecars instead of force-releasing their ports, and consumer masters treat those sidecars as externally managed shared dependencies.

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
