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

## Post-promotion defect closure and final gateway retest

- Real legacy promotion completed on Linux in `wls2-promote-v50.service`: the
  command returned success after enrollment, candidate publication, Agent
  takeover and a 12.216-second stable public-route observation. The measured
  maintenance window was 69.897 seconds. The platform gateway remained active
  independently of the project.
- Restart v51/v52 exposed a delayed Worker bind failure on port 42774. Linux's
  ephemeral range was `32768-60999`; loopback connections created during
  startup could reserve the intended listener value as a client source port
  before Worker #1 bound it. This was a real port-allocation defect, not a
  gateway-version or persisted-config mismatch.
- `Start` now normalizes every private Worker allocation into the deterministic
  `10000-16999` range when a candidate touches the conservative ephemeral
  boundary, includes main/control/maintenance/surge ports in the reservation
  check, and fails when no complete safe range exists. The regression tests
  first reproduced 42774 and now pass `11 tests / 28 assertions`.
- Real v53 restarted all eight Workers on 16217-16224. The systemd gateway was
  enabled and active, public HTTP/2 returned 200, four routes were ACTIVE, and
  recovery failure counters remained zero.
- Final corrected-code gateway H2 report:
  `/home/weline.guest/wls-project-full/var/log/wls/benchmark_report_20260729_091823_496626_wls-health_pid131366.json`
  - 1,000,000/1,000,000 successful, 0 failed;
  - actual HTTP/2 hits: 1,000,000;
  - 2892.78 QPS, P95 187.865ms, P99 301.984ms;
  - 99.8991% connection reuse, 1009 new connections;
  - all eight ordinary Worker PIDs received traffic;
  - quality gate PASS.
- Final source validation uses the framework bootstrap: Weline_Server
  `1288 / 6441 / 17 platform skips`; WLS 2.0 extended scope
  `174 / 2209 / 15 platform skips`; real macOS native integration
  `12 / 1995`; Windows-path scope `87 / 1810 / 3 non-Windows skips`.
- Final cleanup after v53: `server:stop` completed the five-stage drain,
  removed all 11 project-owned processes and listeners, and restored the original
  `app/etc/env.php` byte-for-byte (SHA-256
  `ed4176dda1d428111d20bd213cfef77ce43c46a50de76a6d6f1b74c564ab8c72`).
  The host gateway stayed enabled, active and `HEALTHY`; the stopped project's
  four routes settled to `STALE` and returned the specified HTTP/2 503 while
  public 80/443 remained owned by the manifest-verified Nginx.

## Remaining release boundaries

This closes the discovered Linux lifecycle blockers. It does not convert the
whole WLS 2.0 plan to release-ready: Windows/macOS system-service ACL/reboot,
Windows real-host acceptance, mixed-project million traffic, first ACME, H3 million and the
remaining TEST-001..054 evidence retain their current plan status.
