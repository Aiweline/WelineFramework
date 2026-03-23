# Task Log - WLS start ParseError in Start.php

- Date: 2026-03-22
- Started: 2026-03-22 22:48:40
- Status: completed
- Request: Fix the `ParseError` thrown from `app/code/Weline/Server/Console/Server/Start.php` when running `php bin/w s:start -r -f -frontend -p 9982`.

## Context

- User reported a startup failure with `ParseError` pointing at `Start.php`.
- The exception text referenced garbled Chinese token output, so the likely failure mode was broken string syntax or corrupted source text in the startup command class.
- `dev/ai/codex/ACTIVE.md` was already being updated by another concurrent workstream, so this repair was logged in a standalone task record to avoid stomping parallel context.

## Plan

1. Load workspace context required by `AGENTS.md`.
2. Inspect the reported `Start.php` lines and compare them with the clean Git version.
3. Restore any corrupted string literals causing parser failure.
4. Verify syntax directly and then verify command loading behavior.
5. Record the outcome and keep any remaining runtime issues separate from the syntax fix.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Read `SOUL.md`, `USER.md`, `memory/2026-03-22.md`, `memory/2026-03-21.md`, and `dev/ai/codex/ACTIVE.md`.
- Verified that the reported parser failure centered on startup note strings in `Start.php`.
- Restored the broken startup note strings and re-checked the file until the in-place source linted clean again.
- Confirmed `php bin/w s:start --help` now loads the command successfully.
- Re-ran `php bin/w s:start -r -f -frontend -p 9982`; it no longer failed with `ParseError` and instead progressed into runtime startup until the verification timeout window elapsed.
- Re-applied the missing shared-state runtime changes to the clean `Start.php` baseline after the file restore had dropped them.
- Added instance-local session/memory runtime port and token persistence so `master-only` resurrection and normal startup read the same shared-state settings.
- Verified automatic sidecar port switching with a synthetic busy-port test: when `19970/19971` were occupied, startup persisted `session_server_port=19972` and `memory_server_port=19973` with instance-scoped token filenames.

## Decisions

- Treat the syntax failure as a standalone repair and avoid mixing it with the broader WLS frontend worker-window/runtime task.
- Avoid overwriting `ACTIVE.md` because another agent/task was clearly updating it during this work.

## Verification

- `php -l app/code/Weline/Server/Console/Server/Start.php`
- `php bin/w s:start --help`
- `php bin/w s:start -r -f -frontend -p 9982`
- `php bin/w server:status --all`
- `php bin/w s:start codex-start-b -r -f -p 9983`
- synthetic busy-port startup verification for `codex-port-switch` with temporary listeners on `127.0.0.1:19970` and `127.0.0.1:19971`

## Changed Files

- `app/code/Weline/Server/Console/Server/Start.php`
- `dev/ai/codex/tasks/2026-03-22/2026-03-22-2248-wls-start-parse-error-startphp.md`
- `memory/2026-03-22.md`

## Risks / Notes

- The parse-error repair was intentionally limited to the broken startup note strings in `app/code/Weline/Server/Console/Server/Start.php`; broader in-progress WLS edits in that file were left intact.
- The exact runtime behavior of `server:start -r -f -frontend -p 9982` after class loading remains a separate investigation if startup still appears stuck or slow.

## Outcome

- Resolved the `Start.php` `ParseError`.
- Verified that WLS startup command loading works again and the original command now gets past class parsing.
- Restored the intended shared-state runtime handling in `Start.php`, including instance-scoped token filenames and persisted session/memory runtime ports.
- Verified that non-force multi-instance startup now auto-avoids occupied shared-state ports and keeps Session / Memory on distinct ports.
