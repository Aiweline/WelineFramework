# Result - wls async orchestration and imperial command redesign plan

## Outcome

- Completed the planning/design pass.
- Produced a concrete WLS redesign plan focused on:
  - event-driven orchestration instead of nested blocking waits
  - a real imperial-command arbiter and queued control-plane operations
  - non-blocking outbound IPC
  - coalesced state persistence / routing broadcasts
  - a stricter single-active-operation invariant for the Master control plane
  - a command-admission model with `enqueue` / `coalesce` / `preempt` / `reject`
  - an explicit split between “fix command overlap first” and “fix responsiveness via state machines next”

## Changed Files

- `dev/ai/plans/wls-async-control-plane-optimization.plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0256-wls-async-orchestration-and-imperial-command-redesign-plan/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0256-wls-async-orchestration-and-imperial-command-redesign-plan/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0256-wls-async-orchestration-and-imperial-command-redesign-plan/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0256-wls-async-orchestration-and-imperial-command-redesign-plan/result.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0312-wls-single-active-operation-queue-implementation/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0312-wls-single-active-operation-queue-implementation/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0312-wls-single-active-operation-queue-implementation/progress.md`

## Verification

- Code-path audit only:
  - `app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - `app/code/Weline/Server/IPC/MasterControlServer.php`
  - `app/code/Weline/Server/IPC/ControlMessage.php`
- No runtime commands or automated tests were run in this planning task because the requested output is an engineering plan rather than an implementation slice.

## Remaining Risks

- The current runtime likely still suffers from the diagnosed blocking behavior until the redesign is implemented.
- Existing WLS plans cover process hardening and escalation, but the async control-plane redesign remains a separate implementation effort.

## Next Resume Step

- Implement `Phase 1A` of `dev/ai/plans/wls-async-control-plane-optimization.plan.md`: make Master enforce one active control operation at a time and queue or coalesce the rest before touching the deeper state-machine rewrite.
