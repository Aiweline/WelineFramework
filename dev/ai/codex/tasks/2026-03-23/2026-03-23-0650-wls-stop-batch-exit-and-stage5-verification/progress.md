# Progress - wls-stop-batch-exit-and-stage5-verification

- 2026-03-23 06:50 Created the task workspace.
- 2026-03-23 07:10 Traced the visible one-by-one exit behavior to `ServiceOrchestrator` phase-3/4 waiting and per-instance reporting, not to missing batch kill support in `Processer`.
- 2026-03-23 07:22 Changed stop-all and stop-child-process shutdown so phase 3 only batch-dispatches exit signals, phase 4 becomes a non-blocking settle, and phase 5 owns the aggregate wait/verification/force-kill path.
- 2026-03-23 07:28 Added `ServiceOrchestratorStopFlowTest` to pin batch dispatch, non-blocking phase-4 settle, and stage-5 aggregate cleanup.
- 2026-03-23 07:31 Re-verified with `php -l` and focused PHPUnit; assertions passed, while PHPUnit still exits non-zero only because of the existing `No code coverage driver available` warning and one deprecation.
- 2026-03-23 18:41 Resumed the slice, removed an accidental UTF-8 BOM introduced during an earlier file restore, confirmed `ServiceOrchestrator.php` had no dead-code tail, and re-ran the focused syntax/PHPUnit checks successfully.
