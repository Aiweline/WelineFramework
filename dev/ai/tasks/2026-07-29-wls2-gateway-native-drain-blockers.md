# WLS 2.0 Gateway native drain blockers

## Scope

Close the lifecycle blockers found after the Linux gateway million-request run:

- gateway backend slot identity drift;
- stale project edge projection after gateway recovery;
- one-shot native drain with no restart-safe completion;
- resurrection after `desired=0`;
- memory-pressure scale-up and full child restart bypassing the drained state;
- Status reporting a released native edge as fallback;
- Benchmark selecting the wrong carrier for either of the two gateway backend
  topologies.

## Implemented

- `SupervisorChildClient` keeps the authoritative assigned slot through READY,
  heartbeat and release.
- `Agent` retries native drain every heartbeat until durable `DRAINED`; retries
  never reset the Master-owned deadline.
- `ServiceOrchestrator` uses an idempotent
  `START → DRAINING → FINALIZE → DRAINED` transaction, batch-stops only the
  project-native edge roles, rejects recovery for slots outside current
  desired state, and uses the durable native-edge state to fence startup,
  full child restart, convergence, manual scale and memory-pressure scale-up.
- `GatewayRuntimeEndpointPublisher` accepts only authenticated healthy gateway
  observations and atomically projects mode/protocol/epoch/public ports without
  replacing project route, certificate or launch facts.
- `Status` distinguishes native drain, real supplemental fallback and released
  gateway-active state.
- `Benchmark` resolves host-gateway public targets before the persisted WLS
  adapter, selects ordinary Workers for a gateway-ready project start or
  `gateway_backend` for auto rejoin, and compares the selected carrier
  PID/IPC lease/generation fingerprint before and after a run.

## Validation evidence

- Focused regression before the final benchmark: 156 tests / 775 assertions.
- Final Weline_Server Unit regression: 1075 tests / 4904 assertions /
  2 environment skips.
- Gateway/Nginx integration regression: 56 tests / 943 assertions /
  13 environment capability skips.
- Browser verification: attempted
  `file:///Users/weline/Project/Official/框架/app/code/Weline/Server/doc/WLS-Gateway使用指南.md`;
  the in-app Browser rejected the local file URL before navigation under its
  URL policy, so visible result and console evidence are unavailable
  (`BLOCKED_ENVIRONMENT`). No alternate browser or temporary HTTP workaround
  was used; static document structure/content checks remain required.
- Linux isolated host: Lima `wls-gateway-v2-perf`, systemd gateway, real 80/443.
- Auto rejoin:
  - pure WLS `:26439` and gateway `:443` both returned 200 during migration;
  - 8/8 Gateway Backend leases became READY;
  - native deadline remained fixed for 300 seconds;
  - original Workers exited without resurrection;
  - final state `mode=gateway`, `fallback_state=GATEWAY_ACTIVE`,
    `native_edge.state=DRAINED`;
  - `:26439` no longer accepted connections while `:443` remained 200.
  - after more than two memory-pressure scale-up periods, ordinary Workers
    remained at 0 while all 8 Gateway Backends remained active.
- Gateway-ready project start topology: 10,000/10,000 H2 requests, 0 failed,
  all 8 ordinary Worker PIDs hit, quality gate PASS.
- Final exact gateway H2 report:
  `/home/weline.guest/wls-project-full/var/log/wls/benchmark_report_20260729_041601_978297_wls-health_pid76379.json`
  - 1,000,000/1,000,000 successful, 0 failed;
  - actual HTTP/2 hits: 1,000,000;
  - 6182.44 QPS;
  - P95 101.23ms, P99 198.54ms;
  - all 8 Gateway Backend PIDs received traffic;
  - quality gate PASS.
- Cleanup: project stop returned 0, Master/Gateway Backend process counts are
  0, and `weline-wls-gateway-v2.service` is inactive. A prior revoke attempted
  immediately after a Lima wall-clock jump was correctly rejected by
  `CLOCK_UNTRUSTED`; after the stable recovery window, the final stop completed
  normally without weakening the clock security gate.

## Remaining release boundaries

This closes the discovered Linux lifecycle blockers. It does not convert the
whole WLS 2.0 plan to release-ready: Windows/macOS system-service ACL/reboot,
mixed-project million traffic, first ACME, legacy promote, H3 million and the
remaining TEST-001..054 evidence retain their current plan status.
