# Plan - wls-stop-drain-completion-and-master-exit

## Outcome

- Fixed the false stage-2 drain timeout and hardened stop CLI so it can switch to waiting for Master exit once all child exits are already observed.

## Steps

- [x] Trace the user log against current stop-flow code
- [x] Patch the stop-flow drain/disconnect logic
- [x] Add or update focused unit tests
- [x] Run targeted validation
- [x] Update result and memory notes

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
