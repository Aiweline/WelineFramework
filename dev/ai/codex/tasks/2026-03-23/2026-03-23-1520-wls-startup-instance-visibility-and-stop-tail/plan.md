# Plan - WLS Startup Visibility And Stop Tail

## Outcome

- Keep WLS runtime visibility and stop/reload control paths fast and non-blocking.
- For this continuation, eliminate the stop CLI tail where IPC stream fragmentation can hide the final Master-exit progress and force a false hard-timeout wait.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Investigate startup visibility and stale-instance classification across `Start`, `MasterProcess`, and `ServerInstanceManager`
- [x] Localize the remaining stop-tail risk to CLI-side IPC progress handling
- [x] Implement the smallest correct change in `Stop.php`
- [x] Add focused regression tests for fragmented IPC lines and final-stage stop heuristics
- [x] Run targeted validation commands
- [x] Update task records for resume

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] Live WLS start/status/stop probe
