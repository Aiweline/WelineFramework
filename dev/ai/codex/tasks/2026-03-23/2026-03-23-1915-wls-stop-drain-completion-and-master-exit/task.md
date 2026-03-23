# Task: wls-stop-drain-completion-and-master-exit

- Task ID: 2026-03-23-1915-wls-stop-drain-completion-and-master-exit
- Started: 2026-03-23 19:15
- Status: in_progress
- Owner: Codex
- Source: User provided a real `server:stop` / `s:start -r` stop trace showing false stage-2 drain timeout and later IPC hard timeout while waiting for Master exit

## Goal

- Fix WLS stop flow so global drain does not leave Dispatcher stuck in `DRAINING`, and make stop progress more resilient when children disconnect during drain.

## Scope

- In scope:
- `ServiceOrchestrator` global drain broadcast semantics during stop flow
- stop-flow disconnect state handling for draining instances
- `Stop` CLI fallback when all child exits are already observed but the final Master-exit progress sentence is missing
- focused unit coverage for the stop-flow regression
- Out of scope:
- broader reload pipeline redesign
- unrelated static cache or startup topology work

## Constraints

- worktree is dirty; keep edits tightly scoped
- avoid overwriting unrelated local changes in `Stop.php` / `Reload.php` unless a minimal surgical fix is necessary

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`

## Resume

- Re-read `plan.md`, `progress.md`, and `result.md`.
