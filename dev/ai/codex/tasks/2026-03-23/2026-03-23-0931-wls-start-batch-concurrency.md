# Task Log - WLS start batch concurrency

- Date: 2026-03-23
- Started: 2026-03-23 09:31:00
- Status: completed
- Request: WLS startup phase 1 still does not truly implement batch concurrent startup, and the worker startup phase is also not concurrent; solve only concurrent startup in this task.

## Context

- The user explicitly narrowed scope to startup concurrency only.
- Prior work already fixed rolling reload worker batches to use real concurrent startup, so this task should extend or reuse that mechanism for initial startup.
- The workspace is already dirty in many unrelated areas, including framework/runtime files, so changes must be minimal and conflict-aware.
- This task runs on Windows-sensitive startup code paths, so process-launch quoting/foreground behavior must not regress.

## Progress

- 09:31 Created task record and updated `ACTIVE.md`.
- 09:31 Completed workspace startup context per `AGENTS.md`.
- 09:31 Routed repo skill usage through `weline-framework-skill-router` to `runtime-and-process` and `windows-command-quoting`.
- 09:32 Read prior WLS task logs for reload batch concurrency and frontend worker startup hardening.
- 09:32 Started tracing `server:start` orchestration and `Processer` batch-start paths.
- 09:40 Confirmed the orchestrator startup path was already batched at the service layer; the real regression was lower-level Windows process launch fallback.
- 09:44 Patched `Processer::batchCreateWindows()` so mixed foreground/background batches no longer fall back to serial `create()`.
- 09:45 Added batch foreground PID resolution via shared polling helper and updated the Windows batch script builder to launch visible foreground items through `cmd.exe /d /c <temp>.cmd`.
- 09:47 Updated `ProcesserTest` coverage and removed the obsolete expectation that any foreground batch must return `null`.
- 09:49 Passed lint and targeted PHPUnit verification.

## Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Server/Console/Server/Start.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`

## Risks / Blockers

- No code blocker remained.
- Live WLS runtime verification stayed limited because `php bin/w server:status --all` timed out in this environment.

## Next

- Optional follow-up only if the user wants it: run a manual Windows frontend startup pass to visually confirm window behavior and measure wall-clock startup improvement.

## Result

- Fixed the real concurrency regression for Windows frontend startup:
  - phase-1 startup no longer loses batch concurrency just because dispatcher/session/memory commands are foreground launches
  - worker startup no longer loses batch concurrency just because frontend workers request visible windows
- The fix stays within this task's requested scope and does not alter unrelated startup/recovery behavior.

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
  - Passed.
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
  - Passed.
- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - Passed.
- `php vendor/bin/phpunit --filter ProcesserTest app/code/Weline/Framework/Test/ProcesserTest.php --no-coverage`
  - Passed.
  - `30` tests, `62` assertions.
  - Environment still emitted an existing PHPUnit deprecation notice.
- `php bin/w server:status --all`
  - Timed out in this environment, so no fresh end-to-end WLS startup cycle was recorded for this task.
