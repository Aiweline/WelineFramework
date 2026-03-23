# Plan - wls master loop imperial preemption

## Outcome

- `php bin/w server:reload --no-wait` returns quickly because control-plane instance discovery no longer performs expensive stale-instance realtime checks, and Master periodic work yields promptly when a control command arrives.

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
