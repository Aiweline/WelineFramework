# Plan - wls async orchestration and imperial command redesign plan

## Outcome

- Produce a concrete redesign plan that turns WLS control flow into an event-driven scheduler instead of nested blocking loops.
- Define how imperial commands become serialized, cancellable, and non-overlapping.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Inspect current startup / reload / stop / IPC paths
- [x] Draft the cross-task redesign plan
- [x] Record findings and rollout stages
- [x] Update task artifacts

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
- [x] Code-path audit with concrete file/flow references
