# Task Log - WLS reload rolling batch concurrency

- Date: 2026-03-22
- Started: 2026-03-22 13:41:00
- Status: completed
- Request: Improve `server:reload` / `se:rel` so worker reload uses better three-batch handling, drains during reload, and starts WLS child processes with real concurrency.

## Context

- User reproduction showed `php bin/w se:rel` sends the rolling reload command but the wait-mode CLI times out after 120s.
- Current orchestrator already had worker three-batch grouping, but batch handling still restarted workers one by one inside each batch.
- Current `Weline\Framework\System\Process\Processer::batchCreate()` was not truly concurrent because it wrapped synchronous `create()` calls in Fibers and then started them sequentially.

## Plan

1. Confirm the real current reload and startup paths in `ServiceOrchestrator` and `Processer`.
2. Implement real concurrent child startup in the process layer.
3. Refactor worker batch restart to perform dispatcher removal, batch drain, batch stop, batch concurrent start, and batch READY gating.
4. Improve progress visibility for wait-mode reload.
5. Run lint and targeted WLS verification.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router` to `runtime-and-process`.
- Inspected:
  - `app/code/Weline/Server/Console/Server/Reload.php`
  - `app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - `app/code/Weline/Framework/System/Process/Processer.php`
  - related prior task logs and WLS orchestration plan
- Findings:
  - Worker reload already uses three-batch grouping when count reaches `wls.orchestrator.worker_three_batch_min_count`.
  - `restartWorkerBatchDispatcherAware()` drained a whole batch together, but then started each slot sequentially with `startInstance()`.
  - `Processer::batchCreate()` did not deliver real concurrency.
- Implemented dispatcher-aware batch restart flow for reload:
  - dispatcher removes the whole batch first
  - batch enters drain together
  - old instances are stopped and cleaned together
  - new processes are started concurrently as a batch
  - the batch waits until all new workers become `ready`
  - dispatcher adds the batch back only after readiness is confirmed
- Added batch startup helper `startInstanceIdsBatch()` so startup/reload reuse the same concurrent process-launch path.
- Reworked Windows `Processer::batchCreate()` to use a true concurrent launcher path instead of sequential Fiber-wrapped `create()` calls.
- Improved reload wait-mode output in `server:reload` so long-running reloads show richer staged progress and use adaptive waiting.
- Temporary processer test scripts were patched to use an isolated role (`processer_batch_session`) instead of the live `session_server` role.
- Final runtime validation passed after recovering from a false negative caused by temporary token-file pollution during ad-hoc session-server experiments.

## Decisions

- Keep the existing three-batch policy and improve its batch internals rather than replacing it.
- Make batch restart semantics explicit: remove from dispatcher first, drain the whole batch, stop/cleanup the whole batch, batch-start concurrently, then wait until the whole batch is READY before adding workers back.
- Fix concurrency at the lower `Processer` layer so startup improvements benefit both initial WLS startup and reload paths.
- On Windows, keep the original argument string for batch child launches instead of splitting it into an argv array; earlier array-splitting broke quoting and launch semantics.
- Use temp result/error files for the PowerShell batch launcher instead of reading child pipes directly; this avoids inherited-handle blocking on Windows.
- Invoke PowerShell via `proc_open([...], ..., ['bypass_shell' => true])` to reduce shell quoting and command-length issues.

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
  - Passed.
- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - Passed.
- `php -l app/code/Weline/Server/Console/Server/Reload.php`
  - Passed.
- `php bin/w server:start`
  - Used once to recover to a clean runtime after temporary session-token pollution from ad-hoc test scripts.
- `php bin/w server:reload`
  - Passed.
  - CLI reported all 3 Worker batches completing.
  - Final CLI status reported successful rolling reload after 101.6s for 12 Workers.
- `var/server/instances/default.json`
  - Confirmed all 12 Worker instances end in `ready` state after reload.

## Changed Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Console/Server/Reload.php`
- `tmp-test-processer-batch.php`
- `tmp-test-processer-batch-return.php`
- `tmp-test-processer-create.php`
- `tmp-test-processer-create-exact.php`
- `dev/ai/codex/ACTIVE.md`
- `dev/ai/codex/tasks/2026-03-22/2026-03-22-1341-server-reload-rolling-batch-concurrency.md`

## Risks / Notes

- `app/code/Weline/Server/IPC/MasterControlServer.php` is already dirty in the workspace and was left untouched.
- Runtime verification depends on local WLS/session state; an earlier failed reload run turned out to be a test artifact, not a reload logic bug.
- Root cause of the false failure: temporary session-server scripts used `--role=session_server`, overwriting `var/session/session_server.token`. New workers then connected to the session port but failed auth and exited after repeated retries.
- Recovery path:
  - stop the temporary hidden/manual session-server test processes
  - restart the real WLS stack with `php bin/w server:start`
  - confirm the real `var/session/session_server.token` was regenerated
  - rerun `php bin/w server:reload` and verify success
- Temporary logs/scripts remain in the workspace for now; they may be useful for regression troubleshooting but are not required by the final fix.

## Outcome

- `server:reload` now performs real three-batch rolling reload for Workers with dispatcher-aware draining and batch readiness gating.
- Windows WLS child startup is now truly concurrent instead of pseudo-concurrent.
- Wait-mode CLI no longer relies on a brittle fixed 120s assumption and now reports long reload progress more clearly.
