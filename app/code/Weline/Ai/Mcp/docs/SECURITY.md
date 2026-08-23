# Security boundary

- MCP transport is local STDIO; no listening socket or HTTP service is created.
- All project paths are canonicalized and constrained to the selected Git repository.
- Secret-shaped files, credentials, private keys, generated output, dependencies, caches and runtime data are excluded from indexing and editing.
- Document conflict checks reject unresolved merge markers and credential-shaped content. Session directives reject credential-like values and are never persisted.
- Readiness is bound to the canonical project and client session. A revision, module inventory, or required-document Hash change invalidates the receipt.
- Document repair is deterministic, create-only, explicitly authorized, snapshot-bound, transactionally reindexed, and rollback-safe.
- Static repository knowledge projection is disabled at configuration load. Legacy queued projection jobs terminate with a no-write receipt.
- Compact editing accepts structured operations only, uses per-file kernel locks, validates guards and Hashes, journals preimages, runs fixed validation profiles, and restores safe preimages on failure.
- Analyzer input is redacted and bounded. Candidate experiences never become project rules automatically.
- `set_session_directives` is for temporary product decisions, not secrets or durable policy.
- Deploy integration may produce read-only plans through public CLI only. It cannot publish without a separately explicit target, ref choice, and user authorization.

The retired `ai_knowledge_call_history` application table is never dropped automatically. Operators must inspect row count and retention requirements first; cleanup requires a separately reviewed application-database command or migration.
