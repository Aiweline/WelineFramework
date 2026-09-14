# Operations

MCP is a knowledge plane (index / code map / skills / domain hard rules). Engineering sessions MUST prepare_project and obey hard_constraints when MCP is attached; coding uses host-native editors.

## Requirements

- PHP 8.2 or newer
- `pdo_sqlite`, `json`, `mbstring`, and `openssl`
- Git for canonical project identity and revision checks

No WLS process, Composer install, Node runtime, Redis, PostgreSQL, or application bootstrap is required for STDIO operation.

## Start and diagnose

```bash
php bin/learningctl doctor
php bin/learning-mcp
```

The default local data directory is `~/.learning-mcp`; override it with `LEARNING_MCP_DATA_DIR`. A YAML/JSON config can be supplied with `--config` or `LEARNING_MCP_CONFIG`. Never point the data directory into the repository.

## Single-project isolation

Cursor registration (`ensure-cursor-mcp.php`) injects:

- `LEARNING_MCP_BOUND_REPOSITORY` = the framework checkout that owns this MCP package (`cwd`)
- `LEARNING_MCP_DATA_DIR` = `~/.learning-mcp/projects/<sha256(canonical_repository)>` (never inside the repo)

Effects:

- `prepare_project` / `index_project` / sidecar refresh refuse every other repository (`PROJECT_SCOPE_VIOLATION`).
- Index GC only maintains the bound generation; with `index.gc.purge_unbound: true` (default) other generations under that project's `indexes/` are quarantined.
- Each attached project gets its own STDIO process plus an isolated data directory (SQLite index, sidecar socket, journals). Projects do not share disks.

The first ensure for a project may move that project's legacy generation out of the shared `~/.learning-mcp/indexes` into the new data dir (one-shot marker `.migrated-from-shared-v1`). Override with env vars, or clear both for unbound CLI/tests.

## Client registration

Codex project configuration:

```toml
[mcp_servers.weline_project_intelligence]
command = "php"
args = ["app/code/Weline/Ai/Mcp/bin/learning-mcp"]
required = true
```

Cursor reads `.cursor/mcp.json`; Claude Code uses project `.mcp.json`; VS Code 1.106+ uses `.vscode/mcp.json`. Step 0 for every agent task:

```bash
php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php
```

That script probes local STDIO health, probes host attachment, and returns `host_mcp_install` steps for the **current agent session** to execute. Bootstrap scripts do **not** write host MCP config files or install Git hooks for registration refresh. It never switches branches or performs another Git mutation; a non-`dev` framework checkout is reported for the workspace owner to resolve explicitly. Host start time is derived from the monotonic-style `ps etime` duration instead of parsing timezone-free `ps lstart`, preventing an old process from being reported as a future/current generation. It only touches user `mcp.json` when registration changed or the host CLI is not ready (avoiding per-session approval resets). For Codex, it also compares the generated plugin's `.weline-generation.json` with the current source; a stale/missing marker or duplicate `mcpServers` declaration triggers the existing single-plugin reinstall once, while a matching marker is a no-op. That cache refresh is non-blocking: it does not restart the shared app-server or claim that the current chat catalog has reattached. It does **not** write `~/.cursor/permissions.json`; non-empty `mcpAllowlist` there can lock Cursor away from **Run Everything**. Agents must not send operators to Settings first. Continue with `prepare_project` only when `project-guidance-bootstrap.v1.status=ready` and the current session exposes the required Weline tools.

For IDE Agent chats, use the operator's chosen Cursor **Run Mode** (for example **Run Everything** or **Auto-review**). One-time MCP enable is handled via `cursor-agent mcp enable weline_project_intelligence`, not by editing `permissions.json`.

This server is **local STDIO with no OAuth**. Agents must **never** call Cursor `mcp_auth` for `weline_project_intelligence` — that only opens the host authorization toast and is not required for tools to work. `ensure-project-guidance.php` asserts STDIO `tools/list` includes the required index/knowledge tools. On Cursor Agent hosts (`CURSOR_AGENT` / `CURSOR_EXTENSION_HOST_ROLE`), ensure probes the Helper `mcp-process`. When that helper is older than MCP source **or** still running with no `learning-mcp` child (`orphan_no_learning_mcp_child` — IDE keeps a stale catalog while `CallDynamicTool` times out with Transport closed), ensure bounces the helper, touches user `mcp.json`, and returns `host_repair_needed` so the next Agent turn (or Developer: Reload Window) re-attaches a fresh catalog. If `mcp_stdio` lists the tools but the current chat’s `GetDynamicTools` does not, start a new Agent turn (do not call `mcp_auth`). Keep the registered PHP `command` on a stable path (for example `/opt/homebrew/bin/php`) so `mcp-approvals` fingerprints do not churn and re-prompt workspace approval.

## Verification

```bash
php tests/run.php
php tests/project-readiness.php
php tests/git-worktree-safety.php
find src bin scripts tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check bin/weline-mcp.js
```

Use `php scripts/install.php --dry-run` to inspect installer output without changing host registration. STDIO smoke tests must keep stdout valid JSON-RPC; diagnostics go to stderr.

## Index maintenance

Project indexes are caches and may be deleted only after stopping MCP processes; the next `prepare_project` recreates them. Back up the global learning DB only when its audit history is required.

Interactive requests use three cost tiers:

1. A recent persisted index with `phase=idle`, `freshness=current`, and a `last_completed_at` inside `index.refresh_interval` is reused. A new MCP process no longer repeats a full discovery just because its in-memory cache is empty.
2. A request with explicit `path`/`paths` performs a bounded content-hash refresh for those targets. Use this mode for a focused investigation; it preserves same-size/same-mtime change detection.
3. Revision zero, an expired/partial index, or an explicit forced refresh performs incremental discovery. This is the cold/maintenance path and can be expensive on a large project; it must not be confused with an STDIO connection failure.

When the SessionStart Hook has indexing enabled, it reads only `project_index_status` for the response and schedules the incremental refresh after the Hook response through the sidecar or a detached worker. The Hook must not wait for a project-wide SQLite rebuild; if `pcntl` is unavailable, use the reported fallback and let the first guarded MCP read perform the required local refresh.

For a large repository, use an interactive profile with `index.refresh_interval: 10m`–`15m`, `index.auto_refresh: true`, `index.sidecar_enabled: true`, and `index.include_tests: false`; keep a shorter interval only when the freshness requirement justifies charging every new process for a repository scan. This is a local configuration trade-off, not a reason to edit host registration or disable the symbol/vector indexes.

Relation resolution follows the same boundary: incremental refreshes collect symbol names from deleted/changed files and resolve only those names through the relation target index. A full refresh may still rebuild the complete lookup and resolve all currently-unresolved relations. The index result exposes `last_index.relation_resolution` with `mode`, `target_names`, and `resolved` so a slow run can be attributed to relation writes instead of guessed as transport failure.

This is a freshness window, not a claim of real-time filesystem watching. The sidecar is an optional post-tool accelerator. Check `health.project_intelligence.index_sidecar.socket_present` before treating it as active; when it is absent, use explicit target paths for interactive work and allow the periodic refresh to repair the cache. Do not disable relation or sparse-vector data merely to hide a slow cold refresh: both are used for symbol impact and semantic ranking. First measure `project_index_status.state.last_index.duration_ms`, changed-file count, and database size.

When a scan reports a problem, classify it before changing code:

- `Transport closed`/missing tools: run `ensure-project-guidance.php`; it repairs a stale Codex plugin cache and proves the local STDIO process independently. If the current Codex app-server still has no `learning-mcp` child after the exact plugin reinstall, the remaining fault is its already-loaded transport/catalog; use the host's MCP-only reconnect when available, and do not restart the shared app-server from the bootstrap script. Never use `mcp_auth` for this local STDIO server.
- Long `prepare_project`: inspect the persisted index state and last index counters. A long write with changed files is an index-storage performance issue, not a protocol issue.
- Large relation update: inspect `last_index.relation_resolution`. `scoped` with a small target-name set is the interactive path; `full` or a large resolved count is maintenance-scale work and should be scheduled away from coding sessions.
- Unresolved relations or low-ranked candidates: treat them as retrieval evidence, not defects. Validate the concrete source/sink and expected behavior with indexed exact regions before opening a host-native code fix.
- Large SQLite files: retain them while the index is useful; offline rebuild/VACUUM is a maintenance operation requiring stopped MCP processes and a backup, not an interactive workaround.

The normal remediation loop is: reproduce with a bounded path, add a failing regression case, implement the smallest fix with host-native editors, reindex that path, compare counts/query results, then run a real MCP probe. A scan finding without a reproducible defect, owner, and acceptance criterion remains a documented observation rather than an automatic change.

## Upgrade and retirement

0.12.x repository projection settings are accepted for configuration compatibility but are migrated at runtime to disabled and reported by `runtimeMigrations`. From 0.13.0 onward, update the embedded `Weline_Ai/Mcp` source; the standalone repository is a frozen distribution snapshot.
