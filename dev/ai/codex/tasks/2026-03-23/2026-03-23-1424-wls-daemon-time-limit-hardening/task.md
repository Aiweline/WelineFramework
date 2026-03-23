# Task: wls-daemon-time-limit-hardening

- Task ID: 2026-03-23-1424-wls-daemon-time-limit-hardening
- Started: 2026-03-23 14:24
- Status: completed
- Owner: Codex
- Source: user: continue after phase5 verification work, investigate master disappearance and startup instability

## Goal

- Harden long-running WLS runtimes so Master/worker sidecars are not killed by PHP execution limits.
- Eliminate the false "Master 进程不存在" branch for foreground `server:start -frontend` instances during `server:stop`.

## Scope

- In scope:
- `LongRunningPhpRuntime` bootstrap for Master and long-lived WLS child entrypoints
- foreground Master liveness / managed-identity matching on Windows
- focused unit coverage plus one live WLS stop verification
- Out of scope:
- broader reload pipeline redesign
- unrelated WeShop / Websites dirty worktree changes

## Constraints

- worktree is dirty, so only stage WLS/task files for this slice
- keep the fix at the process-identity/runtime layer instead of letting CLI display flags leak into orchestration logic

## Related Plans

- stop-tail and runtime-hardening follow-up after `559044a0`

## Related Files

- `app/code/Weline/Server/Service/LongRunningPhpRuntime.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/bin/session_server.php`
- `app/code/Weline/Server/bin/http_redirect_worker.php`
- `app/code/Weline/Server/bin/dispatcher.php`
- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`
- `app/code/Weline/Server/Test/Unit/Service/LongRunningPhpRuntimeTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
