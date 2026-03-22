# Task: Fix ServerQueryProvider DI Warning

- Started: 2026-03-22 01:35
- Status: completed
- Owner: Codex

## Goal

Fix the request-time warning where `Weline\Server\Extends\Module\Weline_Framework\Query\ServerQueryProvider` is constructed with `SharedStateAdminService` in the `$broadcastControlDispatchService` slot.

## Context

- The previous WLS startup instability is already fixed and committed as `48f0c97f`.
- The site is reachable, but requests still log:
  - `ServerQueryProvider::__construct(): Argument #5 ($broadcastControlDispatchService) must be of type Weline\Server\Service\Control\BroadcastControlDispatchService, Weline\Server\Service\Control\SharedStateAdminService given`
- Recent log samples point to request handling on multiple workers and to generated factory wiring in `generated/compiled_factories.php`.

## Investigation

- Read `app/code/Weline/Server/extends/module/Weline_Framework/Query/ServerQueryProvider.php` and confirmed the live source constructor already expects:
  - `BroadcastControlDispatchService` as argument #5
  - `SharedStateAdminService` as argument #6
- Compared that against generated DI artifacts and found they were stale:
  - `generated/compiled_factories.php` still instantiated `ServerQueryProvider` with the old 6-argument constructor
  - `generated/reflection_metadata.php` still described argument #5 as `SharedStateAdminService`
- That stale generated wiring exactly matches the runtime warning and explains why the source file itself looked correct.

## Changes

- Regenerated the framework reflection metadata and compiled factories with:
  - `php bin/w reflection:compile`
- Re-started WLS with the refreshed generated artifacts so long-running workers would load the corrected factory metadata.

## Verification

- `php bin/w reflection:compile`
  - result: passed
- `curl.exe -k -s -D - https://127.0.0.1:9982/ -o NUL`
  - result: `HTTP/1.1 200 OK`
- `var/server/instances/default.json`
  - result: dispatcher, session server, memory server, and workers `1..12` are all `ready`
- `var/log/wls/wls.log`
  - result: current startup window around `2026-03-22 03:03` shows normal worker startup, IPC registration, and request handling
  - result: no new `register 超时`, `Unregistered(pid:0)`, or `长期无 IPC` lines after the current startup window
- `var/log/query_provider.log`
  - result: only historical constructor mismatch warnings remain; no new `ServerQueryProvider` mismatch entries were written after the refresh and restart

## Notes

- Keep the unrelated dirty file `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php` untouched.
- The fix for this task was generated-artifact refresh rather than a new source-code patch.
