# Architecture

```text
Codex / Cursor / compatible AI host
                |
                | STDIO JSON-RPC
                v
           McpServer
                |
           ToolService  ---- session readiness gate
                |
     +----------+-----------+
     |                      |
ProjectReadinessService  IntelligenceService
     |                      |
module/doc contract      ProjectIndexer / Retriever / EditService
     |                      |
     +----------> project-isolated SQLite
```

## Runtime boundary

The MCP is plain PHP 8.2-compatible code under the `LearningMcp` namespace. It uses PDO SQLite, filesystem primitives, Git read operations, and fixed child-process adapters. It does not bootstrap WelineFramework and cannot reach Weline DI or the application database.

## Project preparation

`prepare_project` resolves canonical Git identity, scans `app/code/*/*`, validates the three-document contract, refreshes the SQLite index, checks document conflicts, and records a process-memory readiness receipt. The receipt binds repository identity, revision, module inventory, document Hashes, and client session.

`needs_repair` emits deterministic create-only operations. `repair_project_docs` requires an unexpired Bundle, the same session, an unchanged snapshot, and `authorized=true`; it creates missing documents transactionally, reindexes exact paths, and removes created files on failure.

## Knowledge retrieval

`resolve_task_context` searches indexed documents and source evidence and returns `guidance-bundle.v1`: bounded task-matched fragments, source paths, Hashes, rules, and current session directives. It does not preload the framework corpus. Dynamic skill aliases call the same path.

## Freshness and editing

Before every guarded call, the service compares the readiness snapshot with current Git/module/document state and incrementally refreshes external changes. MCP-owned writes reindex inside the same operation. Compact edits are sealed, journaled, fixed-profile validated, targeted-reindexed, and rolled back safely when validation fails.

## State

- Global learning/event database: local user data directory.
- Project code/document index: `indexes/{canonical-project-hash}/project.sqlite`.
- Readiness and session directives: process memory only.
- Edit journal and locks: local user data directory; never committed to the repository.
