# Implementation status — 0.13.0

Implemented:

- session-bound `prepare_project` readiness gate;
- 3-document module contract and explicit deterministic repair;
- incremental SQLite indexing and next-call external edit visibility;
- task-bounded `guidance-bundle.v1` with sources and Hashes;
- in-memory session directives with secret rejection;
- dynamic `resolve_skill` / `get_skill` compatibility aliases;
- compact edit seal/apply/validate/reindex/rollback lifecycle;
- PHP 8.2-compatible dependency-free STDIO runtime;
- forced retirement of repository skill/index projection;
- Codex and Cursor adapters for the same MCP command.

Release acceptance still requires the embedded-source test suite, installer dry-run, STDIO smoke, framework runtime upgrade, client fresh-clone checks, standalone snapshot Hash verification, and dual-remote tag verification.
