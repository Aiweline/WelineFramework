# Plan - wls-live-stop-restart-master-exit-latency

## Outcome

- Verify the real stop/restart path after the recent fixes and either confirm the latency is gone or patch the next concrete bottleneck.

## Steps

- [x] Create task workspace and restate scope
- [x] Inspect current runtime status
- [x] Run a live stop/restart trace
- [x] Patch the next confirmed bottleneck if needed
- [x] Run targeted validation
- [x] Update result and memory notes

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
