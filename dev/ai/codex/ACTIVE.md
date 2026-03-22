# Active Task

- Updated: 2026-03-22 18:11
- Task File: dev/ai/codex/tasks/2026-03-22/2026-03-22-1811-wls-frontend-worker-window-and-startup-hardening.md
- Status: in_progress

## Current Goal

Make WLS frontend-mode worker startup show visible worker windows reliably on Windows, then harden the remaining startup/reload edge cases so WLS reaches an industrial-grade startup and rolling-reload experience.

## Latest Progress

- Completed workspace startup context required by `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router` to `runtime-and-process` with `windows-command-quoting` support.
- Confirmed the workspace is dirty, including pre-existing edits in `app/code/Weline/Server/IPC/MasterControlServer.php`; integration must avoid overwriting unrelated changes.
- Began tracing the real Windows WLS launch path from `Start.php` through `Processer.php` and `ServiceOrchestrator.php`.
- Identified an existing comment in `Start.php` that explicitly intends to keep Worker windows visible in frontend mode, which suggests some runtime paths are bypassing or neutralizing that behavior.

## Verification

- Pending.

## Risks / Notes

- The worktree contains many unrelated modified and untracked files; do not revert them.
- The user also reported post-startup instability, so the task scope includes both visible window behavior and startup/reload control-plane hardening where needed.

## Next

- Inspect the exact Windows process creation code paths used by initial startup, worker resurrection, and rolling reload.
- Reproduce and fix any path that hides or detaches worker windows even when frontend mode is requested.
- Validate with lint plus real `server:start` / `server:reload` runtime checks.
