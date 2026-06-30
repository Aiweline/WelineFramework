# WLS default startup orchestration fixes - 2026-05-27

## Scope

This change addresses the default instance startup incident where WLS was running,
but CLI status and startup confirmation could report misleading failure states.

## Fixed areas

1. Worker SSL diagnostics
   - Added structured `WorkerExitTrace` records for fatal shutdown, unexpected process shutdown, and IPC unexpected disconnect.
   - Trace payload includes PID, instance, role, worker id, port, control port, launch id, last startup stage, IPC connected state, and memory usage.
   - The reconnect behavior is unchanged; this is observability only.

2. `server:status`
   - IPC remains the primary Master health signal.
   - If IPC does not answer within the short status window, status now falls back to the managed Master PID check before reporting the instance stopped.
   - Single-instance status prints a warning when PID is alive but IPC is not responsive.

3. `http:request`
   - The command now prefers `var/server/instances/{instance}.json` for host, port, and SSL mode.
   - This prevents the default command from falling back to the historical `9981` default while the default WLS instance is actually listening on HTTPS `443`.
   - `--instance` / `-I` can select another WLS runtime instance; `--port` and `--https` / `--http` still override.

4. `server:start`
   - The Master control-plane confirmation timeout remains bounded, but the Windows default was raised from 18 seconds to 120 seconds.
   - The explicit confirmation timeout cap was raised to 900 seconds through `wls.orchestrator.background_master_confirm_wait_sec`.
   - If `wls.orchestrator.startup_timeout_sec` is configured, the Master confirmation window follows that value up to 900 seconds.
   - The default background service-ready hard wait now covers a 600-second Orchestrator cycle instead of timing out around a short cold-start window.
   - The timeout warning now explains that an Orchestrator bootstrap or full-restart cycle may still be in progress.

5. `server:stop`
   - The success output now states the ownership boundary: WLS stops only WLS-managed processes. Independent `bin/w` commands such as `setup:upgrade` and `cron:task:run` must be handled separately.

## Verification

- `php -l` passed for all changed PHP files.
- Targeted PHPUnit passed with `--no-coverage`:
  - `app/code/Weline/Framework/Test/Unit/Http/ConsoleHttpRequestServerConfigGuardTest.php`
  - `app/code/Weline/Server/Test/Unit/Console/StatusCommandTest.php`
  - `app/code/Weline/Server/Test/Unit/Console/StartBackgroundStartupReadyTest.php`
- Runtime read-only checks:
  - `php bin/w server:status default` reported Master and 10/10 services running.
  - `php bin/w http:request / -n=1` requested `https://127.0.0.1:443/` and returned HTTP 200.
- End-to-end isolated acceptance:
  - `php bin/w server:start ai-test-wls-acceptance-0527 -p 9602 --no-ssl -c 2` reached running in the startup window.
  - `php bin/w server:status ai-test-wls-acceptance-0527` reported Master and 3/3 services running.
  - `php bin/w http:request / -I ai-test-wls-acceptance-0527 -n=1` requested `http://127.0.0.1:9602/` and returned HTTP 200.
  - `php bin/w server:stop ai-test-wls-acceptance-0527` stopped the test instance through IPC; no LISTENING ports remained for 9602, 26054, 26055, or 36054.
  - `php bin/w server:status default` still reported Master and 10/10 services running after the isolated test.
- Wait-window acceptance after correction:
  - `vendor\bin\phpunit.bat --no-coverage app/code/Weline/Server/Test/Unit/Console/StartBackgroundStartupReadyTest.php` passed 15 tests / 39 assertions.
  - `php bin/w server:start ai-test-wls-wait-0527 -p 9604 --no-ssl -c 2` completed without the 18-second false warning.
  - `php bin/w server:status ai-test-wls-wait-0527` reported Master and 3/3 services running.
  - `php bin/w http:request / -I ai-test-wls-wait-0527 -n=1` requested `http://127.0.0.1:9604/` and returned HTTP 200.
  - `php bin/w server:stop ai-test-wls-wait-0527` stopped the test instance through IPC; no LISTENING ports remained for 9604, 26056, 26057, or 36056.

## Not changed

- No restart was performed for `default`.
- No broad process killer was added for cron/setup CLI processes.
- No fallback path was added to hide Worker IPC disconnects; the next disconnect should produce actionable trace data.
