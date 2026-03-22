# Task: Fix WLS Worker Register Timeout Resurrection

- Started: 2026-03-21 23:10
- Status: completed
- Owner: Codex

## Goal

Fix the WLS instability where workers are marked `register timeout` after startup and then get auto-resurrected by the orchestrator.

## Context

- User-provided logs show the service appears up first, then begins logging:
  - `register 超时: worker#N`
  - `安排复活 worker#N`
- Recent `var/log/wls/wls.log` confirms workers connect to IPC and send ready, but later slots are still treated as timed out.

## Investigation

- Confirmed the apparent “self-start” comes from `ServiceOrchestrator::scheduleResurrection()`, not an external launcher.
- Found the timing bug in startup bookkeeping:
  - `startProvidersBatch()` and `startInstancesBatch()` create all `ServiceInstance` objects up front.
  - `startedAt` is assigned before actual process creation.
  - On Windows, `Processer::batchCreate()` is effectively sequential because each `Fiber::start()` runs synchronous `create()`.
  - With 12 workers, later slots can start tens of seconds after the first ones.
  - Timeout logic compares current time to stale `startedAt`, so late workers are judged as already older than `register_timeout`.

## Changes

- Updated `app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - refresh `startedAt` when each batch-started instance is actually added after spawn
  - refresh `startedAt` in single-instance startup too, so resurrection and refill paths use the same correct baseline

## Verification

- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - result: passed
- Pending:
  - rerun `php bin/w s:start`
  - watch `var/log/wls/wls.log` for worker 2..12
  - verify the chained `register 超时` messages no longer appear

## Notes

- This patch fixes the false timeout baseline.
- Startup may still be slower than ideal because `Processer::batchCreate()` is not truly parallel on Windows.
- If more issues remain, inspect `MasterControlServer` handling of late IPC connects and unregistered disconnects.

## 2026-03-21 Follow-up

- Verified with a new startup window (around 15:28 in wls.log): chained worker register timeout still reproduced.
- Root cause of incomplete fix identified: startInstancesBatch() path still used stale pre-spawn startedAt.
- Applied second fix in pp/code/Weline/Server/Service/ServiceOrchestrator.php to refresh startedAt inside startInstancesBatch() after each spawn result.
- php -l app/code/Weline/Server/Service/ServiceOrchestrator.php passed after patch.
- Remaining verification pending: rerun a clean startup and confirm worker#2..#12 no longer hit register timeout.

## 2026-03-22 IPC Follow-up

- Re-read `var/log/wls/wls.log` around the unstable windows near 15:38-16:13 and correlated master-side and worker-side evidence.
- Confirmed a second bug pattern beyond stale `startedAt`:
  - worker-side logs for worker#8..#12 show `CONNECT 已连接 Master` followed by `已上报就绪状态`
  - master-side logs do not record the corresponding initial `服务就绪` for those same workers
  - master later emits repeated `DISCONNECT Unregistered(pid:0)` and then `register 超时` / `长期无 IPC`
- This strongly suggests some control connections are accepted but their first `register` / `ready` frames are not fully processed or associated.
- Code inspection found two concrete weak points:
  - `app/code/Weline/Server/IPC/MasterControlServer.php`
    - `poll()` accepts only one pending socket per readable cycle
    - newly accepted sockets are not processed immediately in the same cycle
  - `app/code/Weline/Server/IPC/ControlClient.php`
    - `send()` uses a single non-blocking `fwrite()`
    - any partial write is currently treated as success, which can truncate NDJSON frames
- Planned fix:
  - drain all pending accepts in a single `poll()`
  - immediately attempt to read the first packet from newly accepted sockets
  - change IPC send paths to full-write loops with bounded wait
- Verification plan after patch:
  - `php bin/w server:stop default -f`
  - `php bin/w server:start -r`
  - watch logs for at least one full timeout window
  - verify `https://127.0.0.1:9982/` returns success

## 2026-03-22 Final Validation

- Applied the IPC robustness patch set:
  - `app/code/Weline/Server/IPC/MasterControlServer.php`
    - disable socket write buffering on accepted control connections
    - drain all pending accepts in one poll cycle
    - immediately process readable data for newly accepted clients
    - send replies with a bounded full-write loop instead of a single `fwrite()`
  - `app/code/Weline/Server/IPC/ControlClient.php`
    - disable socket write buffering after connect
    - send control frames with a bounded full-write loop
    - treat partial/failed writes as disconnects
    - abort reconnect if `register()` or `sendReady()` does not fully send
  - `app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php`
    - require both `register()` and `sendReady()` to succeed during startup registration
- Re-validated syntax:
  - `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - `php -l app/code/Weline/Server/IPC/MasterControlServer.php`
  - `php -l app/code/Weline/Server/IPC/ControlClient.php`
  - `php -l app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php`
  - all passed
- Re-validated runtime after restart and additional uptime:
  - `var/server/instances/default.json` shows dispatcher, session server, memory server, and all 12 workers in `ready`
  - `curl.exe -k -I https://127.0.0.1:9982/` returned `HTTP/1.1 200 OK`
  - repeated requests returned route hints across healthy workers, including ports `10007` through `10012`
  - browser validation with HTTPS errors ignored loaded the home page and returned title `Poker Arena - India's Premier Online Card Games Platform`
  - post-restart log window around `2026-03-21 17:07` through `17:19` shows normal startup and request handling
- Searched `var/log/wls/wls.log` again for the previous failure signatures:
  - `register 超时`
  - `Unregistered(pid:0)`
  - `长期无 IPC`
  - result: only older pre-fix matches remain; no new matches were emitted after the current restart window
- Residual unrelated warning still appears during requests:
  - `Weline\\Server\\Extends\\Module\\Weline_Framework\\Query\\ServerQueryProvider::__construct(): Argument #5 ($broadcastControlDispatchService) must be of type Weline\\Server\\Service\\Control\\BroadcastControlDispatchService, Weline\\Server\\Service\\Control\\SharedStateAdminService given`
  - this does not block startup stability or page access, so it is tracked as a separate cleanup item
- Browser automation note:
  - a default Playwright navigation to `https://127.0.0.1:9982/` reports `ERR_CERT_AUTHORITY_INVALID`
  - the page itself loads correctly when HTTPS certificate errors are ignored, so this is a local certificate trust issue rather than a WLS startup failure
- Outcome:
  - fixed the false worker timeout / resurrection loop
  - WLS stays up beyond the old timeout window
  - the site is reachable in the current run

