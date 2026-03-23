# Active Task

- Updated: 2026-03-23 09:31
- Task File: dev/ai/codex/tasks/2026-03-23/2026-03-23-0931-wls-start-batch-concurrency.md
- Status: in_progress

## Current Goal

Fix WLS startup concurrency only:

- phase-1 startup must perform real batch concurrent startup instead of serial launch
- worker startup phase must also launch workers concurrently
- do not expand this task into unrelated WLS startup hardening or other runtime issues

## Latest Progress

- Completed workspace startup context per `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router` to `runtime-and-process`, with `windows-command-quoting` loaded because the startup path is Windows-sensitive.
- Read the prior WLS task logs for:
  - reload rolling batch concurrency
  - frontend worker window and startup hardening
- Confirmed the recent reload fix already has batch concurrency at the reload path, so this task should reuse that capability for initial startup rather than invent a second implementation.
- Confirmed the worktree is dirty in many unrelated areas, so edits must stay tightly scoped to WLS startup files and task logs.

## Verification

- Pending.

## Risks / Notes

- Existing unrelated changes in the worktree must not be reverted.
- Startup verification will interact with the live local WLS runtime and may be influenced by existing running instances.

## Next

- Trace the exact `server:start` orchestration path and find where phase-1 and worker startup still call serial launch helpers.
- Patch startup orchestration to use true batch concurrency.
- Run focused lint/tests and, if feasible, a startup verification pass.
