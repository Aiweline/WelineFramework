# Security boundary

- MCP transport is local STDIO; no listening socket or HTTP service is created.
- MCP is an optional knowledge plane (index / code map / skills / domain rules). It does not provide repository write tools; coding uses host-native editors.
- All project paths are canonicalized and constrained to the selected Git repository.
- Secret-shaped files, credentials, private keys, generated output, dependencies, caches and runtime data are excluded from indexing.
- Document conflict checks reject unresolved merge markers and credential-shaped content. Temporary session notes reject credential-like values and are never persisted.
- Readiness is bound to the canonical project and client session. A revision, module inventory, or required-document Hash change invalidates the receipt.
- Document repair is deterministic, create-only, snapshot-bound, transactionally reindexed, rollback-safe, and applied automatically by `prepare_project`.
- Static repository knowledge projection is disabled at configuration load. Legacy queued projection jobs terminate with a no-write receipt.
- Analyzer input is redacted and bounded. Candidate experiences never become project rules automatically.
- Deploy plans use the public CLI (`php bin/w deploy:plan`) only. Publishing requires a separately explicit target, ref choice, and user authorization.

The retired `ai_knowledge_call_history` application table is never dropped automatically. Operators must inspect row count and retention requirements first; cleanup requires a separately reviewed application-database command or migration.
