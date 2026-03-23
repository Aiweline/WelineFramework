# Progress - wls master loop imperial preemption

- 2026-03-23 05:37 Created the task workspace.
- 2026-03-23 13:38 Traced the remaining reload latency to two sources:
- `ServerInstanceManager` control-plane gating still walked through stale-instance realtime validation.
- `ServiceOrchestrator` periodic maintenance slices did not opportunistically poll IPC or yield once a queued control command arrived.
- 2026-03-23 13:44 Added persisted-instance fast paths in `ServerInstanceManager` and switched broadcast dispatch instance discovery to use persisted names instead of filtered realtime instance listing.
- 2026-03-23 13:49 Added periodic-work preemption helpers in `ServiceOrchestrator`, then threaded them through health checks, reconcile, resurrect, worker liveness, emergency restart, self-audit, and port-release waits.
- 2026-03-23 13:55 Runtime validation improved sharply: `php bin/w server:reload --no-wait` dropped from the earlier ~21s wall time to ~1.74s / ~1.82s in repeated local probes.
- 2026-03-23 14:19 Re-verified the committed slice with focused `php -l`, targeted PHPUnit, and a post-commit live `php bin/w server:reload --no-wait` probe at `0.69s`.
- 2026-03-23 14:20 Committed the slice as `2e6634db` (`perf(wls): let imperial commands preempt master loop`).
