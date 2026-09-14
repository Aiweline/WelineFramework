# Project Intelligence contracts

MCP is an optional knowledge plane: project index, code map, skills, and domain hard-rule delivery. Coding uses host-native editors.

## `project-readiness.v1`

`prepare_project(repository, client_session_id)` returns:

- `ready`: module documents and index are current; includes `readiness_id`. Missing required documents are auto-repaired during preparation.
- `needs_repair`: retained only for compatibility receipts; current servers auto-repair and return `ready` with a `repair` section instead.
- `blocked`: project identity, index refresh, document conflict, Git branch policy, or safety validation failed. Framework repositories with a `dev` branch require `git switch dev` before development (`GIT_BRANCH_FORBIDDEN` on `master` or other branches).

The receipt contains project ID, revision, module count, inventory Hash and document Hash. Every guarded knowledge/index tool requires `repository`, `client_session_id`, and `readiness_id`.

## `guidance-bundle.v1`

`resolve_task_context` returns only task-matched material:

- bounded rule summaries and document fragments;
- source paths, content Hashes and index revision;
- temporary session notes (process memory);
- explicit truncation and token-budget metadata;
- **`workflow_contract.v1`**: mandatory phase order, extension-point matrix, acceptance tiers;
- **`pinned_fragments`**: bounded slices from AI工程交付流程, 扩展点选型, and 文档索引.

It never returns the entire framework corpus by default. `resolve_skill` and `get_skill` translate legacy task/path fields and return the same dynamic Bundle.

## Document repair

The repair Bundle contains only missing `doc/README.md`, `doc/需求.md`, and `doc/开发日志.md` create operations. Existing files, symlinks and changed snapshots cause deterministic refusal. `prepare_project` applies the bundle automatically; `repair_project_docs` remains for manual replay of the same bundle.

## Freshness

External edits are compared before the next guarded tool call. A changed required document invalidates readiness; a non-contract source/topic edit is incrementally indexed before the result is read.

## Tool surface

Nine index/knowledge tools: `prepare_project`, `repair_project_docs`, `project_index_status`, `resolve_task_context`, `search_project_knowledge`, `get_indexed_document`, `resolve_skill`, `get_skill`, `health`.
