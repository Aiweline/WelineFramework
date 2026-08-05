# WelineFramework skills

Canonical, task-specific instructions for WelineFramework work.

## Design rules

- `_index.md` routes; it does not copy skill instructions.
- Frontmatter contains only `name` and a precise `description` covering capability and trigger conditions.
- `SKILL.md` contains the non-obvious workflow, boundaries, validation, and output contract.
- Cross-task rules stay in `dev/ai/global-constraints.md`; specialist skills do not repeat them.
- Detailed examples and variant material go in one-level `references/` files.
- Deprecated names stay in `ROLE_SKILL_BINDING.md`, not as active discoverable skill directories.

## Loading

`AI-ENTRY.md` → `global-constraints.md` → `_index.md` → selected skill(s) → owning module docs → targeted source/evidence.

Do not load every skill or require an arbitrary number. Add a skill only when it contributes a separate domain workflow.

## Maintenance

- Keep directory and frontmatter names aligned.
- Update `_index.md` only when routing changes.
- Keep Codex/plugin adapters short and point them back to this canonical directory.
- Validate frontmatter, referenced paths, duplicate boilerplate, and adapter drift after changes.

```bash
php dev/ai/scripts/sync-codex-plugin-skills.php
php dev/ai/scripts/sync-codex-plugin-skills.php --check
php dev/ai/scripts/check-ai-guidance.php
```

Supporting maps:

- `ROLE_SKILL_BINDING.md`: legacy name → current owner
- `TEAM_WORKFLOW.md`: collaboration boundaries
- `MIGRATION_REPORT.md`: historical migration status
