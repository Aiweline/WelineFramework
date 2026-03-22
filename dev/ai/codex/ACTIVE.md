# Active Task

- Updated: 2026-03-22 11:07
- Task File: `dev/ai/codex/tasks/2026-03-22/2026-03-22-0135-server-query-provider-di-warning.md`
- Status: completed

## Current Goal

Fix the repeated request-time warning where `ServerQueryProvider` receives `SharedStateAdminService` for the `$broadcastControlDispatchService` constructor argument.

## Latest Progress

- Confirmed the warning was not caused by current source code wiring. The real mismatch was stale generated DI artifacts:
  - `generated/compiled_factories.php` still instantiated `ServerQueryProvider` with the old 6-argument signature
  - `generated/reflection_metadata.php` also still described the old constructor parameters
- Verified current source already expects the correct 7-argument constructor in `app/code/Weline/Server/extends/module/Weline_Framework/Query/ServerQueryProvider.php`.
- Regenerated reflection metadata and compiled factories with `php bin/w reflection:compile`.
- Cold-started WLS again with the refreshed generated artifacts and re-verified runtime behavior.
- Current runtime is healthy:
  - `https://127.0.0.1:9982/` returns `200 OK`
  - `var/server/instances/default.json` shows dispatcher, session server, memory server, and all 12 workers in `ready`
  - no new `ServerQueryProvider` constructor mismatch warnings were emitted after the new startup window
  - no new `register 超时`, `Unregistered(pid:0)`, or `长期无 IPC` entries were emitted after the current restart window

## Verification

- `php bin/w reflection:compile`
  - result: passed, regenerated `generated/reflection_metadata.php` and `generated/compiled_factories.php`
- `curl.exe -k -s -D - https://127.0.0.1:9982/ -o NUL`
  - result: `HTTP/1.1 200 OK`
- `var/server/instances/default.json`
  - result: all 12 workers plus dispatcher/session/memory are `ready`
- `var/log/wls/wls.log` and `var/log/query_provider.log`
  - result: only historical `ServerQueryProvider` mismatch lines remain; no new lines after the 2026-03-22 03:03 startup window
- `var/log/wls/wls.log` search for prior startup instability signatures
  - result: only historical pre-fix matches remain; no new matches after the current startup window

## Risks / Notes

- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php` remains an unrelated dirty file and must stay untouched.
- `php bin/w server:reload` did not detect a running WLS instance during this pass, so verification used `reflection:compile` plus a controlled cold `server:start`.

## Next

- Continue with other remaining runtime cleanup only if new logs expose a fresh issue.
