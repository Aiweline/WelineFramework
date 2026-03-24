# Task: wls shared sidecar reuse

- Task ID: 2026-03-24-0134-wls-shared-sidecar-reuse
- Started: 2026-03-24 01:34
- Status: in_progress
- Owner: Codex
- Source: user: multi-instance Session/Memory should reuse shared services instead of force-kill

## Goal

- Make multi-instance WLS startup reuse an already-running shared `Session Server` / `Memory Service`
- Stop force-killing shared sidecars on `19970` / `19971` when they already belong to a live Weline instance

## Scope

- In scope:
- detect whether the occupied shared-state port is a reusable Weline sidecar
- keep startup/runtime token-file resolution aligned with the adopted sidecar
- make the consumer master treat adopted sidecars as external shared services and skip local stop/kill paths
- Out of scope:
- redesign shared-sidecar ownership handoff across full owner-master shutdowns
- broader WLS reload/stop latency work outside this specific reuse fix

## Constraints

- Engineering fix only; no parameter-coupled hacks
- Dirty worktree, so only touch WLS-side files and this task workspace

## Related Plans

- None yet.

## Related Files

- [Start.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Console/Server/Start.php)
- [ServiceOrchestrator.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/ServiceOrchestrator.php)
- [SharedSidecarInspector.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/SharedSidecarInspector.php)
- [StartSharedStateRuntimeConfigTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php)
- [ServiceOrchestratorStartupTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php)
- [ServiceOrchestratorStopFlowTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php)

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
