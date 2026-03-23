# Progress - wls startup ready gating and nonblocking batch launch

- 2026-03-23 02:22 Created the task workspace.
- 2026-03-23 10:31 Re-read workspace context, previous WLS concurrency findings, and routed the task through `runtime-and-process` plus `testing`.
- 2026-03-23 10:36 Confirmed two concrete follow-up issues:
  - `ServiceOrchestrator::checkAndNotifyServerReady()` could fire during phase-one because it only inspected currently registered instances.
  - `Processer::batchCreateWindows()` still waited for managed PID resolution even for non-blocking startup batches, which likely caused the observed pause before worker launch.
- 2026-03-23 10:39 Implemented a startup ready-notification arm/reset flow in `ServiceOrchestrator` so the ready banner can only emit after startup submission/acceptance completes.
- 2026-03-23 10:40 Implemented `collectBlockingLaunchItemsNeedingPidResolution()` in `Processer` and wired batch PID waiting to blocking items only.
- 2026-03-23 10:42 Added regression coverage in `ProcesserTest` and new `ServiceOrchestratorStartupTest`.
- 2026-03-23 10:43 Validation passed:
  - `php -l` on all touched PHP files
  - `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/ProcesserTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
