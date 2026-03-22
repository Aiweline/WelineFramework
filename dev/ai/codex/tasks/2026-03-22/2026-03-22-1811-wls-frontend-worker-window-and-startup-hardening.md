# Task Log - WLS frontend worker window and startup hardening

- Date: 2026-03-22
- Started: 2026-03-22 18:11:00
- Status: in_progress
- Request: Fix WLS so frontend-mode worker startup shows worker windows on Windows, then produce and implement an industrial-grade startup hardening plan for the remaining startup issues.

## Context

- User reported that when WLS starts in frontend mode, worker processes do not show their own windows.
- User also reported that startup still has remaining issues after launch and asked for a refined plan plus fixes at an industrial-grade quality bar.
- Recent task history already improved rolling reload batching and Windows concurrent child startup, so this task must build on top of that work rather than regress it.
- The current workspace is dirty, including pre-existing edits in `app/code/Weline/Server/IPC/MasterControlServer.php`.

## Plan

1. Trace the real Windows worker launch paths for initial startup, reload, resurrection, and restart under frontend mode.
2. Identify why worker windows are not visible even though frontend mode is intended to keep them visible.
3. Audit the startup control plane for the remaining instability exposed by the latest logs.
4. Implement focused fixes and guardrails without overwriting unrelated work.
5. Run lint plus live WLS verification and document the outcome and residual risks.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router` to `runtime-and-process`, with `windows-command-quoting` also loaded because the issue is Windows process-launch specific.
- Read:
  - `SOUL.md`
  - `USER.md`
  - `memory/2026-03-22.md`
  - `memory/2026-03-21.md`
  - `dev/ai/codex/ACTIVE.md`
  - prior task log `2026-03-22-1341-server-reload-rolling-batch-concurrency.md`
- Established current constraints:
  - `MEMORY.md` does not exist in this workspace.
  - `BOOTSTRAP.md` does not exist in this workspace.
  - The worktree has many unrelated changes, including WLS files, so edits must be minimal and conflict-aware.
- Initial finding:
  - `Start.php` contains a Windows/frontend-specific intent comment for keeping Worker windows visible, so the missing windows likely come from another launch path or an option mismatch lower in the process layer.

## Decisions

- Keep this task focused on WLS startup/runtime behavior; do not touch unrelated dirty files.

## Verification

- Pending.

## Changed Files

- `dev/ai/codex/ACTIVE.md`
- `dev/ai/codex/tasks/2026-03-22/2026-03-22-1811-wls-frontend-worker-window-and-startup-hardening.md`

## Risks / Notes

- Runtime verification will interact with the local WLS environment, so process cleanup and saved instance config need to be respected.
- If the current dirty edit in `MasterControlServer.php` materially changes the control-plane behavior, integrate with it rather than reverting it.

## Outcome

- Pending.
