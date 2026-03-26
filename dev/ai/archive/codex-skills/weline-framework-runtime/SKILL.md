---
name: weline-framework-runtime
description: Use for WelineFramework runtime and WLS work: worker, dispatcher, reload/restart, maintenance mode, process orchestration, state reset, session server, memory service, and runtime debugging. Trigger when the task mentions WLS, Worker, Dispatcher, `server:start`, `server:reload`, `server:restart`, maintenance, Session Server, StateManager, or process lifecycle issues.
---

# Weline Framework Runtime

Use this skill for runtime/process work in `E:\WelineFramework\DEV-workspace`.

## Read in this order

1. [`references/runtime-basics.md`](references/runtime-basics.md)
2. [`references/session-runtime.md`](references/session-runtime.md) if Session/Auth is involved
3. Repo references when needed:
   `dev/ai/skills/runtime-and-process/SKILL.md`
   `dev/ai/skills/session-development/SKILL.md`
   `dev/ai/rules/wls-state-management.mdc`

## Defaults

- For business-code changes, prefer `php bin/w server:reload`
- Use restart only when changing startup parameters, Master/Dispatcher flow, or other reload-insufficient runtime pieces
- Treat request-level static state as suspicious under WLS and verify reset behavior
- When reviewing WLS issues, inspect worker, dispatcher, orchestrator, maintenance, and health-check paths together
