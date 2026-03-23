# Plan - wls startup ready gating and nonblocking batch launch

## Outcome

- Delay WLS ready notification until the startup sequence has finished submitting all planned services and the ready check is explicitly armed.
- Keep Windows batch startup non-blocking for non-blocking launch requests even when PID resolution is deferred.

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
