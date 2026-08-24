# Project Intelligence contracts

## `project-readiness.v1`

`prepare_project(repository, client_session_id)` returns:

- `ready`: module documents and index are current; includes `readiness_id`.
- `needs_repair`: development is denied; includes missing paths and a deterministic repair Bundle.
- `blocked`: project identity, index refresh, document conflict, Git branch policy, or safety validation failed. Framework repositories with a `dev` branch require `git switch dev` before development (`GIT_BRANCH_FORBIDDEN` on `master` or other branches).

The receipt contains project ID, revision, module count, inventory Hash and document Hash. Every guarded knowledge/edit tool requires `repository`, `client_session_id`, and `readiness_id`.

## `guidance-bundle.v1`

`resolve_task_context` returns only task-matched material:

- bounded rule summaries and document fragments;
- source paths, content Hashes and index revision;
- relevant code/edit regions when requested;
- temporary session directives;
- explicit truncation and token-budget metadata;
- **`workflow_contract.v1`**: mandatory phase order, extension-point matrix, acceptance tiers;
- **`pinned_fragments`**: bounded slices from AI工程交付流程, 扩展点选型, and 文档索引.

It never returns the entire framework corpus by default. `resolve_skill` and `get_skill` translate legacy task/path fields and return the same dynamic Bundle.

## Document repair

The repair Bundle contains only missing `doc/README.md`, `doc/需求.md`, and `doc/开发日志.md` create operations. Existing files, symlinks and changed snapshots cause deterministic refusal. Repairs are authorized separately from preparation and immediately reindexed.

## Freshness

External edits are compared before the next guarded tool call. A changed required document invalidates readiness; a non-contract source/topic edit is incrementally indexed before the result is read. MCP writes reindex before returning success.

## Session directives

`set_session_directives` replaces or appends bounded temporary decisions for the current client session. Directives are redacted, credential-shaped input is rejected, and values disappear when the MCP process ends. They are never promoted into module documents or long-term experience automatically.

## Compact editing

`get_edit_bundle` returns exact indexed regions and guards. `apply_compact_edit` accepts one `edit-plan.v1`, seals it, acquires ordered per-file locks, applies fixed operations, runs server-selected validation, performs targeted reindex, and emits a bounded diff/impact receipt. Recovery uses `get_edit_status` and `rollback_edit`.
