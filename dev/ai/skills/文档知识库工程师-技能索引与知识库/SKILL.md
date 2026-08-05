---
name: 文档知识库工程师-技能索引与知识库
description: Maintain Weline skill indexes, canonical/discovery mappings, migration catalogs, and AI-facing knowledge structure. Use when skills are added, renamed, consolidated, deprecated, or their discovery paths change; do not load for ordinary code documentation.
---

# Skill index and knowledge structure

## Ownership

- `dev/ai/skills/*/SKILL.md` is canonical skill content.
- `dev/ai/skills/_index.md` routes task signals without copying bodies.
- `.codex/skills` and the repository plugin expose thin adapters; migration/history belongs outside active discovery.

## Workflow

1. Inventory canonical names, frontmatter triggers, active discovery entries, aliases, and references.
2. Choose one canonical owner for each capability and merge/deprecate synonyms.
3. Keep exact-passphrase aliases only where the user-facing phrase is the trigger.
4. Update the routing index and synchronization allowlists together.
5. Run `php dev/ai/scripts/sync-codex-plugin-skills.php`, then its `--check` mode and `php dev/ai/scripts/check-ai-guidance.php`.
6. Record structural migration facts without making reports a runtime dependency.

Promote a historical lesson only when confirmed/reusable and route it to the narrowest owner via `文档知识库工程师-会话复盘与规则沉淀`.

