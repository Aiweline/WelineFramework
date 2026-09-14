# Architecture

MCP is an optional knowledge plane: project index, code map, skills, and domain hard-rule delivery. Coding uses host-native editors.

```text
Codex / Cursor / compatible AI host
                |
                | STDIO JSON-RPC
                v
           McpServer
                |
           ToolService
                |
     +----------+-----------+
     |                      |
ProjectReadinessService  IntelligenceService
     |                      |
module/doc contract      ProjectIndexer / Retriever
     |                      |
     +----------> project-isolated SQLite
```

## Runtime boundary

The MCP is plain PHP 8.2-compatible code under the `LearningMcp` namespace. It uses PDO SQLite, filesystem primitives, Git read operations, and fixed child-process adapters. It does not bootstrap WelineFramework and cannot reach Weline DI or the application database.

## Project preparation

`prepare_project` resolves canonical Git identity, scans `app/code/*/*`, validates the three-document contract, refreshes the SQLite index, checks document conflicts, and records a process-memory readiness receipt. The receipt binds repository identity, revision, module inventory, document Hashes, and client session.

`needs_repair` emits deterministic create-only operations. `prepare_project` applies them automatically and returns `ready` with repair metadata. `repair_project_docs` requires an unexpired Bundle, the same session, and an unchanged snapshot; it creates missing documents transactionally, reindexes exact paths, and removes created files on failure.

## Knowledge retrieval

`resolve_task_context` searches indexed documents and source evidence and returns `guidance-bundle.v1`: bounded task-matched fragments, source paths, Hashes, rules, and temporary session notes. It does not preload the framework corpus. Dynamic skill aliases call the same path.

## Freshness

Before every guarded call, the service compares the readiness snapshot with current Git/module/document state and incrementally refreshes external changes. Host-native edits are picked up on the next readiness/index refresh.

## State

- Global learning/event database: local user data directory.
- Project code/document index: `indexes/{canonical-project-hash}/project.sqlite`.
- Both SQLite connections enable a 30-second native busy timeout before WAL work, so concurrent STDIO processes wait without replaying application transactions.
- Readiness and temporary session notes: process memory only.
