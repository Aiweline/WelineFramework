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

That script auto-repairs MCP registration/approval (via `ensure-cursor-mcp.php`), probes local STDIO health, and checks Git branch inputs. It only touches user `mcp.json` when registration changed or the host CLI is not ready (avoiding per-session approval resets). It does **not** write `~/.cursor/permissions.json`; non-empty `mcpAllowlist` there can lock Cursor away from **Run Everything**. Agents must not send operators to Settings first. Continue with `prepare_project` only when `project-guidance-bootstrap.v1.status=ready`.

For IDE Agent chats, use the operator's chosen Cursor **Run Mode** (for example **Run Everything** or **Auto-review**). One-time MCP enable is handled via `cursor-agent mcp enable weline_project_intelligence`, not by editing `permissions.json`.

This server is **local STDIO with no OAuth**. Agents must **never** call Cursor `mcp_auth` for `weline_project_intelligence` — that only opens the host authorization toast and is not required for tools to work. If tools are missing in the current chat after `status=ready`, start a new Agent turn or stop with `HOST_MCP_NOT_ATTACHED`. Keep the registered PHP `command` on a stable path (for example `/opt/homebrew/bin/php`) so `mcp-approvals` fingerprints do not churn and re-prompt workspace approval.

## Verification

```bash
php tests/run.php
php tests/project-readiness.php
find src bin scripts tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
node --check bin/learning-mcp.js
```

Use `php scripts/install.php --dry-run` to inspect installer output without changing host registration. STDIO smoke tests must keep stdout valid JSON-RPC; diagnostics go to stderr.

## Index maintenance

The next guarded request automatically observes external source or document changes. Manual full rebuild is normally unnecessary. Project indexes are caches and may be deleted only after stopping MCP processes; the next `prepare_project` recreates them. Back up the global learning DB and edit journals only when their audit history is required.

## Upgrade and retirement

0.12.x repository projection settings are accepted for configuration compatibility but are migrated at runtime to disabled and reported by `runtimeMigrations`. From 0.13.0 onward, update the embedded `Weline_Ai/Mcp` source; the standalone repository is a frozen distribution snapshot.
