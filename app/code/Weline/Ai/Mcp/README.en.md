# Weline Project Intelligence MCP 0.13.0

This is the dependency-free local project-intelligence MCP embedded in `Weline_Ai`. It runs on PHP 8.2+ and SQLite over STDIO and does not depend on WLS, Weline DI, an application database, or a network service.

Long-term knowledge comes only from `Weline_Ai/doc`, `Weline_Framework/doc`, and each module `doc` directory. Every knowledge unit must provide `README.md`, `需求.md`, and `开发日志.md`; derived indexes and hashes live only in project-isolated SQLite. Repository skill projection is retired. `resolve_skill` and `get_skill` are dynamic aliases for task guidance.

Required flow:

1. Start `bin/learning-mcp`.
2. Call `prepare_project` with the repository and a unique `client_session_id`.
3. Continue only when `project-readiness.v1.status` is `ready`.
4. If documents are missing, review the deterministic repair bundle and call `repair_project_docs` only after explicit authorization.
5. Call `resolve_task_context` and pass the returned `readiness_id` to every guarded tool.
6. Store temporary user decisions with `set_session_directives`; they remain process-memory only.

Run `php bin/learningctl doctor`, `php tests/run.php --quick`, and `php tests/project-readiness.php` for local verification. See [project contracts](docs/PROJECT-INTELLIGENCE.md), [operations](docs/OPERATIONS.md), and [security](docs/SECURITY.md).

`app/code/Weline/Ai/Mcp` is the sole source from 0.13.0 onward. The standalone repository is a release snapshot and is frozen after 0.13.0. Licensed under Apache-2.0.
