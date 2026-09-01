# Operations

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

Cursor uses `.cursor/mcp.json` and `~/.cursor/mcp.json`. Step 0 for every agent task:

```bash
php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php
```

That script auto-repairs MCP registration/approval (via `ensure-cursor-mcp.php`), probes local STDIO health, and checks Git branch inputs. It never switches branches or performs another Git mutation; a non-`dev` framework checkout is reported for the workspace owner to resolve explicitly. Host start time is derived from the monotonic-style `ps etime` duration instead of parsing timezone-free `ps lstart`, preventing an old process from being reported as a future/current generation. It only touches user `mcp.json` when registration changed or the host CLI is not ready (avoiding per-session approval resets). Refreshing the Hook-only Codex plugin artifact is non-blocking while the attached STDIO MCP and host runtime are healthy/current; a reload remains mandatory for a stale runtime generation or an actual MCP registration change. It does **not** write `~/.cursor/permissions.json`; non-empty `mcpAllowlist` there can lock Cursor away from **Run Everything**. Agents must not send operators to Settings first. Continue with `prepare_project` only when `project-guidance-bootstrap.v1.status=ready`.

For IDE Agent chats, use the operator's chosen Cursor **Run Mode** (for example **Run Everything** or **Auto-review**). One-time MCP enable is handled via `cursor-agent mcp enable weline_project_intelligence`, not by editing `permissions.json`.

This server is **local STDIO with no OAuth**. Agents must **never** call Cursor `mcp_auth` for `weline_project_intelligence` — that only opens the host authorization toast and is not required for tools to work. `ensure-project-guidance.php` now asserts STDIO `tools/list` includes the required plan/edit pair (`submit_task_plan`, `get_task_plan`, …). When the Cursor Helper `mcp-process` is older than MCP source or required tools are missing, ensure bounces that process and returns `host_repair_needed` so the next Agent turn re-attaches a fresh catalog. If `mcp_stdio` lists the tools but the current chat’s `GetDynamicTools` does not, treat it as `HOST_MCP_SESSION_CATALOG_STALE`: start a new Agent turn (do not call `mcp_auth`). Keep the registered PHP `command` on a stable path (for example `/opt/homebrew/bin/php`) so `mcp-approvals` fingerprints do not churn and re-prompt workspace approval.

## Verification

```bash
php tests/run.php
php tests/project-readiness.php
php tests/git-worktree-safety.php
php tests/module-version-bump-gate.php
find src bin scripts tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check bin/learning-mcp.js
```

Use `php scripts/install.php --dry-run` to inspect installer output without changing host registration. STDIO smoke tests must keep stdout valid JSON-RPC; diagnostics go to stderr.

## Index maintenance

The next guarded request automatically observes external source or document changes. Manual full rebuild is normally unnecessary. Project indexes are caches and may be deleted only after stopping MCP processes; the next `prepare_project` recreates them. Back up the global learning DB and edit journals only when their audit history is required.

## Upgrade and retirement

0.12.x repository projection settings are accepted for configuration compatibility but are migrated at runtime to disabled and reported by `runtimeMigrations`. From 0.13.0 onward, update the embedded `Weline_Ai/Mcp` source; the standalone repository is a frozen distribution snapshot.
