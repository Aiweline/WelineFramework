# Task Log - IPC idle timeout force-kill master

- Date: 2026-03-22
- Started: 2026-03-22 11:27:30
- Status: completed
- Request: Investigate and fix `[IPC] 等待超时（空闲 15s）` followed by forced Master termination and instance process cleanup.

## Context

- Symptom: stop command could hit idle-timeout quickly and force-kill Master.
- Source path identified in `Weline\\Server\\Console\\Server\\Stop::sendStopViaIpcAndWait()`.
- Related stop lifecycle path in `Weline\\Server\\Service\\ServiceOrchestrator` had long wait stages without periodic progress heartbeats.

## Plan

1. Locate timeout trigger and cleanup path in source and logs.
2. Confirm whether timeout was false-positive idle vs. real hard stall.
3. Patch stop-wait policy to avoid premature idle failure.
4. Add orchestrator heartbeat progress during long stop stages.
5. Verify syntax and runtime behavior, then document outcome.

## Progress

- Session startup files read per AGENTS.md and task record initialized.
- Routed skills via `weline-framework-skill-router` -> `runtime-and-process`.
- Located timeout branch in `Stop.php` and long wait stages in `ServiceOrchestrator.php`.
- Implemented idle-wait hardening in stop IPC loop and adaptive hard-timeout calculation.
- Implemented periodic stop progress heartbeats for:
  - stage2 drain wait
  - stage4 disconnect wait
- Re-ran lint checks and multiple start/stop reproductions.

## Decisions

- Idle timeout should be a warning-only signal, not immediate stop failure.
- Hard timeout should be adaptive to orchestrator stop config (`stop_all_drain_wait_sec`, `stop_terminate_timeout_sec`) with platform caps.
- Long stop stages must emit periodic progress heartbeats to keep CLI-side IPC activity alive.

## Verification

- `php -l app/code/Weline/Server/Console/Server/Stop.php` -> passed
- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php` -> passed
- Reproduction run with IPC stop path no longer returned immediate `空闲 15s` hard-fail behavior; stop flow entered long wait path instead of instant force-kill.
- Final environment check:
  - `php bin/w server:status --all` -> no running instances

## Changed Files

- app/code/Weline/Server/Console/Server/Stop.php
- app/code/Weline/Server/Service/ServiceOrchestrator.php
- memory/2026-03-22.md
- dev/ai/codex/tasks/2026-03-22/2026-03-22-1127-ipc-idle-timeout-master-forced-kill.md
- dev/ai/codex/ACTIVE.md

## Risks / Notes

- Runtime environment also showed unstable/legacy instance metadata (`control_port` missing, partial instance states). This is adjacent and may still affect stop UX independently of this patch.
- Existing dirty workspace files were left untouched.

## Result

- Completed targeted timeout-robustness patch for WLS stop flow.
- Reduced false-positive idle timeout risk in stop command.
- Added orchestrator stop heartbeats to improve IPC progress continuity.

## Resume Notes

- If stop UX still feels inconsistent, next diagnostic target is instance metadata persistence (`control_port` / master mode consistency) in instance manager/startup flow.
