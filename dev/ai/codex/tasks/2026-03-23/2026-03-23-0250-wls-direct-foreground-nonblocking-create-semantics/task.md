# Task: wls direct foreground nonblocking create semantics

- Task ID: 2026-03-23-0250-wls-direct-foreground-nonblocking-create-semantics
- Started: 2026-03-23 02:50
- Status: in_progress
- Owner: Codex
- Source: Follow-up audit found Processer::create foreground path still coupling block=false with PID wait/fallback semantics

## Goal

- Keep `Processer::create(..., block: false, foreground: true)` truly non-blocking on Windows.
- Prevent the direct foreground-create path from falling back into a second hidden launch just because PID confirmation was not immediately available.

## Scope

- In scope:
- Windows direct foreground launch behavior inside `Processer::create()`
- Regression tests for the direct managed-PID wait policy
- Out of scope:
- Broader WLS startup ordering already handled in commit `1a61a2f8`
- Full runtime validation that would require disturbing a live user instance

## Constraints

- Preserve the user's parameter-isolation rule: `foreground` is a display choice, while `block` controls waiting semantics.
- Keep the fix small and safe because the worktree is already dirty in many unrelated places.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
