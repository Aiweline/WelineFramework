# Task: wls-single-active-operation-queue-implementation

- Task ID: 2026-03-23-0312-wls-single-active-operation-queue-implementation
- Started: 2026-03-23 03:12
- Status: in_progress
- Owner: Codex
- Source: User requested proactive WLS control-plane redesign with one active master operation at a time

## Goal

- Implement the first concrete WLS control-plane redesign slice: Master may execute only one active control operation at a time, while later operations are queued/coalesced/preempted by policy instead of overlapping inline in IPC callbacks.

## Scope

- In scope:
- `ServiceOrchestrator` command admission and command execution entrypoints
- Single-active-operation queue / arbiter / ACK semantics for mutating control commands
- Stop preemption and queue clearing rules
- Targeted tests around queueing / preemption / callback de-fatting behavior
- Out of scope:
- Full startup state-machine rewrite
- Full reload / stop / rolling-restart state-machine rewrite
- Non-blocking outbound IPC queue refactor
- Hot-path persistence / broadcast coalescing beyond what is needed for this slice

## Constraints

- This slice should solve command overlap and control-plane chaos first; it is not expected to fully solve startup or reload latency by itself.
- Child-process protocol messages (`register`, `ready`, `disconnect`, `exited`, etc.) must continue to be processed outside the queued control-operation lane.
- Do not re-couple runtime semantics to CLI parameters; parameter parsing and execution policy must stay separated.

## Related Plans

- `dev/ai/plans/wls-async-control-plane-optimization.plan.md`

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/IPC/ControlMessage.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
