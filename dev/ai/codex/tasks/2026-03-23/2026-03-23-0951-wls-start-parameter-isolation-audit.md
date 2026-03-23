# Task Log - WLS start parameter isolation audit

- Date: 2026-03-23
- Started: 2026-03-23 09:51:13
- Status: completed
- Request: Re-clarify that parameters are just parameters and should not let one flag alter the whole startup logic; check whether there are more issues of this class.

## Context

- This task follows immediately after the WLS startup batch-concurrency fix.
- The previous bug was an example of parameter bleed: one `foreground=true` item caused the whole Windows batch launcher to fall back to serial startup.
- The goal here is to audit the startup chain for the same design smell, then fix any concrete remaining issue in scope.

## Progress

- 09:51 Created a new follow-up task record and updated `ACTIVE.md`.
- 09:52 Re-loaded the relevant startup files:
  - `app/code/Weline/Framework/System/Process/Processer.php`
  - `app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - `app/code/Weline/Server/Console/Server/Start.php`
- 09:52 Began searching for parameter-driven branches around:
  - `foreground` / `frontend`
  - `block`
  - `enableLog`
  - `daemon`
  - `master-only`
  - `strategy`
- 09:53 Confirmed the audit should focus on whether a per-command/per-instance parameter escalates into a batch-level or architecture-level fallback.
- 09:57 Confirmed a second same-class issue on real startup paths:
  - `Processer::create()` only tried fast managed-process reuse when `block && !$foreground`
  - strategy-mode and legacy direct-start callers using `foreground=true` could therefore skip normal reuse/de-duplication
- 09:59 Added `Processer::shouldTryManagedProcessReuse()` and wired both `create()` and `batchCreateWindows()` through the same rule so `foreground` no longer changes reuse semantics.
- 10:00 Added unit coverage for the rule and reran targeted `ProcesserTest`.
- 10:01 Finished the audit pass; no additional concrete parameter-driven whole-batch fallback was found in the inspected startup chain.

## Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Console/Server/Start.php`

## Risks / Blockers

- No code blocker remained.
- One residual behavior choice was intentionally left unchanged: foreground-launch failure can still fall back to background launch in `Processer::create()`.

## Next

- Optional follow-up only if requested: tighten foreground-launch semantics so a failed foreground request becomes an explicit failure instead of a compatibility fallback.

## Result

- The startup-chain parameter isolation audit found and fixed one additional same-class bug:
  - `foreground` no longer disables managed-process reuse / de-duplication
- After this fix, no further concrete case was found in the inspected startup chain where a per-process parameter still changes the whole batch startup strategy.

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
  - Passed.
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
  - Passed.
- `php vendor/bin/phpunit --filter ProcesserTest app/code/Weline/Framework/Test/ProcesserTest.php --no-coverage`
  - Passed.
  - `31` tests, `66` assertions.
  - Environment still emitted an existing PHPUnit deprecation notice.
