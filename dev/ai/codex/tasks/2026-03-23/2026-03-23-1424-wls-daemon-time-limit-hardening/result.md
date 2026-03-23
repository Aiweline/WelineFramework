# Result - wls-daemon-time-limit-hardening

## Outcome

- Completed the runtime-hardening slice:
- added a reusable `LongRunningPhpRuntime` guard for Master and long-lived WLS child entrypoints, so PHP execution limits no longer silently kill the control plane
- fixed foreground Master liveness checks by accepting a recorded command-line hash match, which preserves identity checks even when the real foreground `server:start -frontend` command line has no `--name=...`

## Changed Files

- `app/code/Weline/Server/Service/LongRunningPhpRuntime.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/bin/session_server.php`
- `app/code/Weline/Server/bin/http_redirect_worker.php`
- `app/code/Weline/Server/bin/dispatcher.php`
- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`
- `app/code/Weline/Server/Test/Unit/Service/LongRunningPhpRuntimeTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`

## Verification

- `php -l app/code/Weline/Server/Service/LongRunningPhpRuntime.php`
- `php -l app/code/Weline/Server/Service/MasterProcess.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/LongRunningPhpRuntimeTest.php`
- `php -l app/code/Weline/Server/bin/worker.php`
- `php -l app/code/Weline/Server/bin/worker_ssl.php`
- `php -l app/code/Weline/Server/bin/session_server.php`
- `php -l app/code/Weline/Server/bin/http_redirect_worker.php`
- `php -l app/code/Weline/Server/bin/dispatcher.php`
- `php -l app/code/Weline/Framework/System/Process/Processer.php`
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/LongRunningPhpRuntimeTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
- `php vendor/bin/phpunit app/code/Weline/Framework/Test/ProcesserTest.php`
- live verification: detached foreground `php bin/w server:start codex-fg-hash -p 9990 -c 1 -frontend --no-ssl`, then `php bin/w server:stop codex-fg-hash` completed via IPC instead of the false `Master 进程不存在` branch

## Remaining Risks

- stop/reload wall-clock latency is still dominated by later orchestrator phases; this slice only fixed the false negative liveness path plus the PHP time-limit hazard
- old orphaned pid-index records can still exist from earlier broken runs, but new foreground Master stops now cleanly remove their own instance again

## Next Resume Step

- continue profiling the remaining stop/reload latency with the false foreground-Master miss removed from the control path
