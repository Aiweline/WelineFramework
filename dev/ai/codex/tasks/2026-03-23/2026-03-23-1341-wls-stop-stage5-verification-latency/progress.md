# Progress - wls-stop-stage5-verification-latency

- 2026-03-23 13:41 Created the task workspace.
- 2026-03-23 22:52 Re-read `ServiceOrchestrator` stop-flow stage-4/stage-5 logic and confirmed phase 5 was still giving the same verification timeout to all remaining PIDs, even when a child had already dropped out of IPC control.
- 2026-03-23 22:56 Confirmed the phase-5 verification path still started with per-instance liveness checks, then moved into a batched verification loop, so disconnected residuals could still inherit the same graceful wait as IPC-connected children.
- 2026-03-23 23:02 Implemented a phase-5 partitioning change:
  - initial liveness collection now starts with one batched PID check
  - IPC-connected processes remain eligible for the short graceful verification loop
  - IPC-disconnected residuals now skip that wait and fall straight into the final batch force-kill set
- 2026-03-23 23:07 Added/updated `ServiceOrchestratorStopFlowTest` coverage for the new behavior, including a regression that asserts disconnected residual PIDs do not consume the graceful verification window.
- 2026-03-23 23:10 `php -l` passed for the touched PHP files.
- 2026-03-23 23:12 Focused PHPUnit assertions passed for `ServiceOrchestratorStopFlowTest`; the wider targeted suite also stayed green aside from the pre-existing PHPUnit warning about missing code coverage driver.
- 2026-03-23 23:18 Live validation was only partially possible:
  - one `server:stop default` probe hit the existing degraded path where Master PID was already gone, so stop fell back to residual cleanup in about `23.5s`
  - an additional immediate `server:start -frontend -p 9982` probe timed out after roughly `304s` and left no running instance
  - this indicates a separate runtime instability around Master/start lifecycle that is outside this phase-5 slice and still blocks clean end-to-end stop profiling
