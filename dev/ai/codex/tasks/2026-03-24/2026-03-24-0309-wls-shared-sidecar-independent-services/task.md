# Task: wls shared sidecar independent services

- Task ID: 2026-03-24-0309-wls-shared-sidecar-independent-services
- Started: 2026-03-24 03:09
- Status: completed
- Owner: Codex
- Source: WLS 多实例公用 session/memory 服务需独立于实例，重做判断逻辑与检测方式，并确保所有相关日志包含实例名

## Goal

- Redesign WLS shared `session_server` / `memory_server` so they are independent shared services instead of being effectively owned by the instance that launched them first.
- Make instance startup only need to ensure the shared service exists and is healthy; if absent, create it; if present, reuse it.
- Remove the old loose reuse/busy-port judgment path and replace it with a more reliable registration + probe flow.
- Ensure all related WLS process/log output carries an explicit instance name so multi-instance logs stay distinguishable.

## Scope

- In scope:
- introduce an instance-independent shared-service runtime/registry path for Session/Memory
- make `server:start` ensure the shared services before instance bootstrap instead of dynamically switching into instance-local sidecars
- harden shared-service detection from “occupied port + looks like Weline” into registry/probe-backed validation
- keep instance runtime metadata aligned with the shared service that was ensured
- update WLS process tags / key logs so Master / Dispatcher / Worker / Session / Redirect logs include instance names
- update focused WLS unit tests for the new shared-service architecture
- Out of scope:
- a brand-new CLI surface for separately starting/stopping shared services if the existing `server:start` ensure path is already sufficient
- broader WLS reload/stop latency work not directly caused by shared Session/Memory ownership
- unrelated non-WLS logging cleanup outside the touched WLS runtime processes

## Constraints

- Keep the shared services independent from any specific instance lifecycle; instance stop/restart must not implicitly own or destroy them.
- Prefer fixed configured shared-service endpoints over per-instance automatic sidecar port switching.
- Dirty worktree: only touch WLS-side files and this task workspace.
- Preserve backward compatibility for child runtime consumers that still read `shared_state` from instance runtime metadata.

## Related Plans

- Previous slice: [2026-03-24-0134-wls-shared-sidecar-reuse](e:/WelineFramework/DEV-workspace/dev/ai/codex/tasks/2026-03-24/2026-03-24-0134-wls-shared-sidecar-reuse/task.md)

## Related Files

- [Start.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Console/Server/Start.php)
- [SharedSidecarInspector.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/SharedSidecarInspector.php)
- [ServiceOrchestrator.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/ServiceOrchestrator.php)
- [MasterProcess.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/MasterProcess.php)
- [ServerInstanceManager.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/ServerInstanceManager.php)
- [session_server.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/session_server.php)
- [worker.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/worker.php)
- [worker_ssl.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/worker_ssl.php)
- [dispatcher.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/dispatcher.php)
- [http_redirect_worker.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/bin/http_redirect_worker.php)
- [StartSharedStateRuntimeConfigTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php)
- [ServiceOrchestratorStartupTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php)

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
