# Task: wls async orchestration and imperial command redesign plan

- Task ID: 2026-03-23-0256-wls-async-orchestration-and-imperial-command-redesign-plan
- Started: 2026-03-23 02:56
- Status: in_progress
- Owner: Codex
- Source: User requested engineering plan for WLS startup/reload/stop slowness and async master-loop optimization

## Goal

- Produce an engineering-grade WLS optimization plan focused on asynchronous orchestration, master-loop responsiveness, and strict imperial-command serialization/preemption.
- Identify the real architectural bottlenecks behind slow startup, reload, stop, and command handling before starting the next implementation slice.

## Scope

- In scope:
- `ServiceOrchestrator` startup / reload / stop / imperial-command control flow
- `MasterControlServer` IPC read/write behavior and control-plane message handling
- Cross-task WLS redesign plan and staged rollout recommendations
- Out of scope:
- Full implementation of the redesign in this planning task
- Unrelated frontend/business runtime issues outside WLS orchestration
- Broad worker/business logic rewrites unless they are needed by the orchestration plan

## Constraints

- Follow engineering delivery: diagnose first, then produce a staged plan with acceptance criteria instead of patching blindly.
- Treat WLS as an async server: the plan must reduce or remove master-side blocking waits.
- Keep recommendations grounded in the current codebase, not generic async-server advice.

## Related Plans

- `dev/ai/plans/wls-orchestration-optimization.plan.md`
- `dev/ai/plans/codex-wls-process-control-hardening.plan.md`

## Related Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/IPC/MasterControlServer.php`
- `app/code/Weline/Server/IPC/ControlMessage.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
