# Result - wls-live-stop-restart-master-exit-latency

## Outcome

- Completed a stop-flow latency hardening pass for WLS on Windows.
- The false CLI early-switch heuristic is gone; CLI now waits for explicit Master-exit progress before changing into `waitForMasterExit()`.
- `ServiceOrchestrator` now finalizes stop with an explicit Master exit path, so the stop flow no longer depends on a slow natural fall-through after all children are gone.
- Phase 3 no longer blocks Master on broad synchronous process polling. It now only dispatches OS-level termination for children that have already fallen out of IPC control, while connected services rely on `SHUTDOWN` and later async verification.
- Windows batch signal handling now has a detached dispatch path for stop-flow phase 3, and Windows small PID-set running checks avoid the expensive full-process-table scan.

## Changed Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`
- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
- `php -l app/code/Weline/Server/Console/Server/Stop.php`
- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `php -l app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
- `php vendor/bin/phpunit --configuration phpunit.xml app/code/Weline/Framework/Test/ProcesserTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- Live `php bin/w server:stop default` probes on the real local WLS instance:
  - earlier reproduced baseline was about `61.9s`
  - intermediate stop-flow fixes brought that down into the high-40s/low-50s
  - latest verified run completed in about `32.3s`
- Final local runtime was restored after verification: `php bin/w server:status --all` now shows `default` back up on `https://127.0.0.1:9982` with `2/2 workers` ready.
- PHPUnit still reports the pre-existing environment warning `No code coverage driver available` plus one deprecation, so the raw command exit code remains non-zero despite green assertions.

## Remaining Risks

- Latest live logs still show phase 5 verification taking roughly `15s`, so stop performance is improved but not fully optimal yet.
- The current pass is tightly scoped to stop flow; reload/restart tail latency was not re-profiled end-to-end in this task.
- The workspace is otherwise dirty, so any follow-up should keep edits tightly scoped to WLS runtime files again.
