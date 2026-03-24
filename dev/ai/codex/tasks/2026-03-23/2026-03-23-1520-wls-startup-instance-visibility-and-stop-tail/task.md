# Task: WLS Startup Instance Visibility And Stop Tail

- Task ID: 2026-03-23-1520-wls-startup-instance-visibility-and-stop-tail
- Started: 2026-03-23 15:20
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Fix the remaining WLS control-plane regressions where freshly started instances are not reliably visible to `server:status`, and stop/reload still spend excessive time in stale-process verification.

## Scope

- In scope:
  - WLS startup visibility between `Start`, `MasterProcess`, and `ServerInstanceManager`
  - Process index cleanup and residual PID collection performance
  - Focused validation for `server:start`, `server:status`, and `server:stop`
- Out of scope:
  - Unrelated Websites / WeShop / PageBuilder worktree changes
  - Large protocol redesign beyond the smallest safe fix for this slice

## Constraints

- Keep orchestration semantics independent from display/runtime parameters.
- Do not revert unrelated dirty worktree files.
- Preserve the async, non-blocking control-plane direction already established for WLS.

## Related Plans

- `dev/ai/codex/tasks/2026-03-23/2026-03-23-1341-wls-stop-stage5-verification-latency/`

## Related Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Server/Console/Server/Start.php`
- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
