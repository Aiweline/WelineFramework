# Task: wls master loop imperial preemption

- Task ID: 2026-03-23-0537-wls-master-loop-imperial-preemption
- Started: 2026-03-23 05:37
- Status: completed
- Owner: Codex
- Source: User requested follow-up optimization because reload is still too slow; focus on Master main-loop preemption for imperial commands

## Goal

- Make imperial commands such as `server:reload --no-wait` stop waiting behind Master periodic maintenance work.
- Remove the remaining slow control-plane instance discovery path that still triggers realtime process checks before dispatching reload.

## Scope

- In scope:
- Master main-loop preemption/yield behavior for periodic work (`healthCheck`, `reconcile`, `worker liveness`, `resurrect`, `self-audit`).
- Fast persisted-instance discovery for control-plane command gating/dispatch.
- Focused tests and runtime validation for reload responsiveness.
- Out of scope:
- Deep redesign of WLS worker startup/reload orchestration itself.
- Shared cross-worker static cache architecture.

## Constraints

- Keep changes tightly scoped to WLS control-plane latency.
- Do not revert unrelated dirty worktree changes.
- Imperial commands must remain async-first: prefer yielding/queuing over blocking waits.

## Related Plans

- `dev/ai/plans/wls-async-control-plane-optimization.plan.md`

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`
- `app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
