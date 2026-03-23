# Task: wls-static-cache-hit-and-reload-latency

- Task ID: 2026-03-23-0427-wls-static-cache-hit-and-reload-latency
- Started: 2026-03-23 04:27
- Status: in_progress
- Owner: Codex
- Source: User requested static asset cache hit fix and reload slowness investigation after control queue serialization

## Goal

- Make WLS static asset caching reach `X-WLS-Static-Cache: HIT` on repeated requests.
- Identify and remove the major pre-dispatch latency in `server:reload`, especially the long delay before the rolling-reload progress message appears.

## Scope

- In scope:
- Static-file fast path and in-memory cache population/lookup in WLS workers.
- `server:reload` pre-flight path, including instance enumeration, status collection, and IPC/control-plane handoff latency.
- Focused runtime validation for repeated static requests and reload command responsiveness.
- Out of scope:
- Broader WLS control-plane redesign beyond the specific latency/cache defects touched here.
- Unrelated framework/runtime modules outside `Weline_Server`.

## Constraints

- Worktree is dirty; keep edits tightly scoped and never revert unrelated user changes.
- Follow the newer engineering workflow: task workspace updates, focused verification, and small coherent commits.
- WLS must remain async-first: fix latency by removing blocking behavior rather than adding longer waits.

## Related Plans

- `dev/ai/plans/wls-async-control-plane-optimization.plan.md`

## Related Files

- `app/code/Weline/Server/Console/Server/Reload.php`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/IPC/MasterControlServer.php`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
