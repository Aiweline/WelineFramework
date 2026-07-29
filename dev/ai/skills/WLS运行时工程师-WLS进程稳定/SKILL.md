---
name: WLS运行时工程师-WLS进程稳定
description: Diagnose and control WLS worker, dispatcher, master, reload/restart, cleanup, and long-running process stability. Use for server lifecycle or orchestration failures; use the Session/SSE skill for request-state/streaming internals and ordinary module skills for application logic.
---

# WLS process stability

## Lifecycle decision

- Normal worker-loaded code: reload the dedicated instance.
- Master/startup/configuration lifecycle: restart the dedicated instance.
- No matching live instance: use bootstrap/collector/static evidence and report the runtime gap; a no-op reload is not proof.

## Workflow

1. Identify worker/dispatcher/master ownership, live instance status, logs, and whether the change is reloadable.
2. Trace lifecycle state before killing or restarting anything.
3. Implement the smallest owning-process correction; avoid blocking calls and process-global request state.
4. Validate on a unique `ai-test-*` instance at an available port `>=9502`.
5. Recheck behavior/status after the correct reload/restart and stop the instance after automated validation, or explicitly hand it off for manual acceptance.
6. Report instance, port, lifecycle command, result, cleanup, and residual process risk.

Do not touch port `9501`, kill unknown processes blindly, reuse instance names, cache user/request-dependent hot-path data without all dimensions, or leave an unmanaged instance running.
