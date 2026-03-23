# Task: wls startup ready gating and nonblocking batch launch

- Task ID: 2026-03-23-0222-wls-startup-ready-gating-and-nonblocking-batch-launch
- Started: 2026-03-23 02:22
- Status: in_progress
- Owner: Codex
- Source: User requested WLS startup ordering/performance optimization after parameter-isolation commit

## Goal

- Make WLS startup order match real readiness: do not emit "server ready" before worker startup is actually submitted and the startup sequence has fully completed.
- Remove unnecessary waiting inside Windows batch startup so non-blocking startup batches are not delayed by best-effort PID resolution.

## Scope

- In scope:
- `ServiceOrchestrator` startup ready-notification timing for the current `server:start` lifecycle
- Windows `Processer::batchCreateWindows()` handling of unresolved PIDs for non-blocking batch launches
- Focused regression tests for the new gating / wait semantics
- Out of scope:
- Broader WLS lifecycle redesigns outside startup ordering and startup latency
- Unrelated parameter parsing / CLI option behavior already fixed in commit `c30bdd6c`
- Live end-to-end changes that would require disturbing an actively running user instance

## Constraints

- Follow the user's parameter-isolation rule: display / launch parameters must not silently change startup semantics.
- Keep edits tightly scoped because the worktree contains many unrelated user changes.
- Prefer unit-level validation first; record any missing runtime/E2E proof explicitly.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
