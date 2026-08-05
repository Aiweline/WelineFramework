# AI-ENTRY.md

This file is the single navigation entry for WelineFramework AI work. It contains no domain-rule mirror.

## Load order

1. `AGENTS.md` for repository identity and local boundaries.
2. `dev/ai/global-constraints.md` for cross-task safety, authorization, validation, and delivery rules.
3. `dev/ai/skills/_index.md` to select the smallest useful skill set.
4. For module work, `dev/ai/diagrams/08-module-docs-index.txt` and the owning module's `doc/AI-INDEX.md`.
5. Targeted source, configuration, existing tests, and runtime evidence needed for the actual task.

`dev/ai/AI-RULES-PACK.md` is a compatibility/packaging map, not an additional mandatory rule layer.

## Authority

For normative instructions, use this fixed priority:

1. System, developer, and current user instructions.
2. `AGENTS.md` and `dev/ai/global-constraints.md`.
3. The selected specialist skill and owning module documentation.
4. Historical plans, reports, migration notes, and archives.

Current behavior is a factual question, not a lower-priority rule: targeted source, configuration, tests, and runtime evidence determine what the implementation actually does. Lower rule layers may specialize an upper rule but cannot weaken its safety or authorization boundary. When a normative document and current implementation disagree, investigate both, correct the stale owner, and record the evidence.

## Context maps

`dev/ai/codex/SOUL.md`, `dev/ai/codex/USER.md`, and `dev/ai/codex/MEMORY.md` provide project context. They are not rule authorities and should be updated when they become stale.

## Navigation

- Global rules: `dev/ai/global-constraints.md`
- Skills: `dev/ai/skills/_index.md`
- Architecture diagrams: `dev/ai/diagrams/00-INDEX.txt`
- Module docs: `dev/ai/diagrams/08-module-docs-index.txt`
- Planning detail: `dev/ai/skills/planning/SKILL.md`
- Testing detail: `dev/ai/skills/testing/SKILL.md`
- Agent roster: `dev/ai/agent/README.md`
- Task records: `dev/ai/codex/tasks/`
