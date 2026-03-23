# Task: wls-live-stop-restart-master-exit-latency

- Task ID: 2026-03-23-1948-wls-live-stop-restart-master-exit-latency
- Started: 2026-03-23 19:48
- Status: completed
- Owner: Codex
- Source: User asked to continue after stop-flow fixes, with focus on real stop/restart latency and whether Master still stalls on exit

## Goal

- Run a live WLS stop/restart verification after the recent stop-flow fixes and eliminate any remaining Master-exit or stop-stage latency that still reproduces.

## Scope

- In scope:
- live `WLS` status / stop / restart verification for the current local instance
- Master exit latency investigation if stop or restart still stalls
- minimal code changes plus focused tests if a new root cause is confirmed
- Out of scope:
- unrelated Websites / WeShop / Theme dirty worktree changes

## Constraints

- keep edits tightly scoped to the WLS runtime path under investigation
- do not revert unrelated dirty files

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Service/MasterProcess.php`

## Resume

- Re-read `plan.md`, `progress.md`, and `result.md`.
