# Plan - wls-stop-batch-exit-and-stage5-verification

## Outcome

- `ServiceOrchestrator` stop/reload shutdown exits are batch-dispatched in phase 3, phase 4 is non-blocking settle only, and phase 5 owns aggregate verification and cleanup.

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
