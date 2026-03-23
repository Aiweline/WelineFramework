# Task: wls-stop-batch-exit-and-stage5-verification

- Task ID: 2026-03-23-0650-wls-stop-batch-exit-and-stage5-verification
- Started: 2026-03-23 06:50
- Status: completed
- Owner: Codex
- Source: User requested stop/reload shutdown to batch exit once and defer waiting to stage-5 verification

## Goal

- Make WLS stop/reload shutdown batch-dispatch child exits in phase 3, keep phase 4 non-blocking, and move the real wait/verification/force-kill work into phase 5.

## Scope

- In scope:
- `ServiceOrchestrator` stop-all and stop-child-process shutdown flow
- Phase-3 batch exit dispatch, phase-4 non-blocking settle, and stage-5 aggregate verification
- Focused unit coverage for the stop flow
- Out of scope:
- Broader rolling-restart performance work outside this specific stop/reload exit path

## Constraints

- Do not touch unrelated dirty CLI files such as `Reload.php` / `Stop.php`
- Keep Master async-first; avoid per-process synchronous waits before stage 5

## Related Plans

- `dev/ai/plans/wls-async-control-plane-optimization.plan.md`

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
