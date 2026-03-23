# Plan - wls-single-active-operation-queue-implementation

## Outcome

- Queue all mutating Master control commands behind one active operation slot, with clear ACK/queue/preempt semantics and no more long-running overlap launched directly from IPC callbacks.

## Steps

- [x] Clarify scope, affected files, and risks
- [ ] Implement single-active-operation queue / arbitration in `ServiceOrchestrator`
- [ ] Route stop and other mutating commands through the unified admission path
- [ ] Add or update targeted tests
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
