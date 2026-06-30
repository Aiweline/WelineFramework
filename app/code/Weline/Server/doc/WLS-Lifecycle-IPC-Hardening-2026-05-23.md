# WLS Lifecycle IPC Hardening - 2026-05-23

## Background

This pass focused on defects found across the WLS Master lifecycle, child-process registration, control-plane IPC, and instance-record cleanup paths.

## Defects Fixed

- Control-plane commands were accepted by port reachability alone. Master now generates a per-lifetime `control_token`, persists it in the instance endpoint record, and rejects command messages without a matching token.
- Child process registration and READY handling only enforced slot lease identity for selected roles. Lease checks now apply to all registered roles before the child can bind to a slot or become READY.
- Fresh Master lifetimes reused epoch `1`, allowing stale children from a prior lifetime to look current. Master now derives the next epoch from the endpoint record and persists epoch changes.
- Pending READY expiry was ineffective for non-worker roles and depended on fields that were not consistently checked. The expiry path now uses role-appropriate READY acknowledgement metadata.
- Instance cleanup trusted raw PID existence. Running checks now require WLS-managed process identity from pid metadata and use batch PID snapshots on hot exit paths to avoid slow Windows per-PID command-line probes.
- Stop/force-stop paths used direct `exit()`, bypassing `MasterProcess::run()` cleanup. Stop completion now requests the main loop to end, cancels pending main-loop tasks, lets `finally` run, and removes Master PID ownership metadata during controlled exit.

## Runtime Evidence

- Started `ai-test-wls-ipc-0523-005` on port `9502` with `--no-ssl` and one worker.
- Confirmed instance endpoint contains a 64-byte `control_token`, `epoch=1`, and `master_epoch=1`.
- Confirmed `server:ipc:ping ai-test-wls-ipc-0523-005` succeeds through the token-aware CLI path.
- Confirmed a raw TCP command without `control_token` returns `Unauthorized control command.`.
- Confirmed `server:stop ai-test-wls-ipc-0523-005` waits for Master exit and completes without forced Master cleanup.
- Confirmed `server:status ai-test-wls-ipc-0523-005` reports Master stopped after cleanup.

## Test Notes

- `MasterControlServerCommandClientTest` passes.
- `ServerInstanceManagerMasterExitRetentionTest` was updated to register an explicit WLS-managed child process identity instead of treating the current arbitrary PHP process PID as retained.
- `ServiceOrchestratorStopFlowTest` still reflects older mock expectations around stop-flow internals and should be updated separately if the stop-flow contract is rebaselined.
