# Plan - wls-stop-stage5-verification-latency

## Outcome

- Phase 5 should stop treating every still-alive PID equally; disconnected residual processes should bypass the graceful verification window and move straight to batch cleanup, while IPC-connected processes keep the short graceful wait.

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
