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

## v54 multi-tenant million and revoke defect closure

- DEF-058 closed the administrator publication timeout mismatch. `repair`,
  `revoke`, `transfer` and `upgrade` can synchronously consume the candidate
  validation and stable-probe window, so the PHP client and Windows Native
  Broker now preserve the authenticated response for up to 90 seconds. Short
  status/project operations keep their bounded default timeout.
- DEF-059 closed route resurrection after project revoke. Every path that
  selects backends now treats a matching project tombstone or `REMOVED` status
  as irreversible, preserves the first `removed_at`, and clears instances,
  backends, backend-instance mappings and backend identity before returning.
- Red/green regression: the focused scope first failed exactly on the old
  2-second timeout and `REMOVED → ACTIVE` transition, then passed
  `41 tests / 932 assertions`. The final Weline_Server regression passed
  `1290 / 6469 / 17 platform skips`; the `Gateway|Windows|Nginx` scope passed
  `221 / 2444 / 15 capability skips`; opt-in native macOS Broker/Launcher/
  data-plane recovery passed `12 / 1986` with no skips.
- Fresh Linux host `wls-gateway-v2-v54` installed the current immutable package
  on real 80/443 and reached `wls-edge/2`, slot B, `HEALTHY`, with zero
  recovery failures. The package used an isolated acceptance signing root;
  an initial mismatch between that PHP trust root and the launcher-embedded
  public key was corrected in the acceptance package and was not classified as
  a product defect.
- Mixed H2 million: project one and two each completed 500,000 requests with
  zero failures, 100% actual HTTP/2 and exact tenant marker. QPS was
  8268.25/10433 and P95 was 3.137/3.043ms. Persistent raw-log SHA-256 values:
  `7e197b6a84c5bac6df4d0d86e6e88eaa47b16a97f71e01cae6daa401d32c59bf` and
  `ec50c19a8b512c46c37e3ef1b7d27fd514004dd61b62121f4a919e93b99e215f`.
- Mixed H3 million: project one and two each completed 500,000 requests with
  zero failures and 500,000 exact ALPN `h3`, tenant and UUID matches. QPS was
  3166.78/3262.12 and P95 was 44.218/39.847ms. Report SHA-256 values:
  `a0c2fb2fe74066cbc929ef251d240165c5211e108115d38d06d843a72d32df60` and
  `9aa1c7f0ebd0c0da554fe7d6ffe1bee0fb38bfc1a7ba4478216722ce892847cf`.
- Real independent-project revoke returned authenticated success in
  30.67403 seconds with tombstone generation 13. Three samples after about
  three minutes retained the same `removed_at`, empty routing identities and
  `REMOVED`; the revoked domain returned neutral 421 while the survivor stayed
  exact HTTP/2 200 and the gateway remained `HEALTHY`. Evidence SHA-256:
  `7af2509bcc4c223f0362c52a4ddb85f0ca2b3c506557b9c5543d513189ede83e` and
  `73c4c464219d46e0092ac12bc3ea4415aa3e06e8db595444862d3b76deb46191`.
- The first revoke harness colocated two nominal projects in one runner;
  revoking one therefore stopped both and correctly forced the gateway into
  fail-closed rebuild. That topology does not model independent projects and
  is retained as a harness finding: every multi-project failure-isolation
  acceptance must use one independently supervised runtime per project.
- The earlier in-app Browser check rejected the `file://` target. Under the current
  plain-Markdown gate, documentation is accepted by targeted staged diff, path and
  format checks; no live WLS instance was created merely to bypass the old policy.

## Runtime capability proof and regression closure

- DEF-060 closed the missing production capability writer. `Start` now emits a
  generation-bound `dynamic` or explicitly configured `stateless` declaration;
  shared Session is never accepted from configuration or an endpoint hint.
- The project resolver proves shared Session only for the effective WLS storage,
  a registered loopback Session Server and an authenticated token health probe.
  Failure demotes immediately; recovery requires a shared 30-second healthy
  window. The Controller binds the proof schema, host, port and token-scope
  digest to the exact endpoint and still requires matching enrollment capability.
- DEF-061 closed project-generation and disk-write amplification. Stateless
  evidence remains bound to each instance generation, but the project desired
  digest contains only the stable stateless mode. A second stateless instance no
  longer rewrites project capability state solely because its generation differs.
- DEF-062 closed stale per-instance capability identity. Heartbeats now carry
  `instance_digest`; a mismatch does not mutate routing or renew the old lease,
  and instead asks the Agent to replay full registration. Isolated diagnostic
  changes are excluded from route identity so recovery-pending messages do not
  create a register loop.
- DEF-063 closed two false-negative native H3 harness paths. H3 probes wait for
  actual QUIC readiness, read the current advertised capability on every phase,
  require an explicit downgrade reason, and refresh manual fixture leases before
  bounded Controller/H3 fault injection.
- DEF-064 closed unsafe shared Session distribution. Persisted capability proof
  must remain complete and digest-valid for every active instance, and all
  shared-session evidence digests must match. Different Session services, legacy
  state without proof and corrupted proof now retain deterministic single-instance
  routing.
- DEF-065 closed project-generation oscillation. Project desired state now carries
  only the stable runtime-attested policy; stateless proof never writes the
  project recovery file. A same-generation, same-live-launch replay is accepted
  only when domain, certificate, route set, backend and all non-capability identity
  fields are byte-equivalent.
- Red/green evidence reproduced the undefined capability projection, stateless
  multi-instance state rewrite, missing heartbeat replay, mismatched shared Session distribution, project-generation oscillation and asynchronous H3/lease
  failures. Final validation: capability/protocol scope `61 / 1060`; complete
  Weline_Server `1312 / 6606 / 17 skips`; Gateway/Windows/Nginx
  `243 / 2581 / 15 skips`; opt-in native suite `12 / 1985`; focused real H3 and
  recovery `1 / 1479`.
- Current-source Linux million remains intentionally unclaimed. The existing
  `wls-gateway-v2-perf` host is durably `CLOCK_UNTRUSTED` after deliberate wall
  clock injection; its security state was not reset. The v54 mixed H2/H3 million
  remains evidence for the unchanged data plane, while a fresh signed package and
  three-run median remain TASK-014 boundaries.

## Remaining release boundaries

This closes the discovered Linux lifecycle blockers, mixed-project H2/H3
million traffic, and revoke invariants. It does not convert the whole WLS 2.0
plan to release-ready: Windows/macOS system-service ACL/reboot, Windows
real-host acceptance, first ACME, three-run performance medians and the
remaining TEST-001..054 evidence retain their current plan status.
