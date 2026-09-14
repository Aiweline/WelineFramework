# Weline Project Intelligence MCP

Local, dependency-free project-intelligence MCP shipped inside `Weline_Ai` (PHP 8.2+ / SQLite / STDIO).

## Role (2026-09-13)

MCP is an **optional knowledge plane**:

- Skill/doc index (`resolve_skill` / `get_skill`)
- Code map + knowledge search (`resolve_task_context` / `search_project_knowledge`)
- Domain hard-rule delivery (`prepare_project.agent_guidance.hard_constraints`)

**Coding uses host-native editors.** MCP is not a mandatory write-code path and does not require a full tool catalog / ready gate before development.

## Optional retrieval

1. Optionally start `bin/learning-mcp` (or `ensure-project-guidance.php`).
2. Optionally call `prepare_project` to refresh the index and hard constraints.
3. Optionally call `resolve_task_context` / `get_skill` for docs and skills.
4. **Edit with host-native tools**; obey domain rules (Theme / Taglib / Payment / e2e, etc.).

See [PROJECT-INTELLIGENCE.md](docs/PROJECT-INTELLIGENCE.md) and [OPERATIONS.md](docs/OPERATIONS.md).
