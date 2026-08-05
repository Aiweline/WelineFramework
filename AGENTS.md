# AGENTS.md

## Repository identity

- `/Users/weline/Project/Official/框架` is the canonical macOS repository for WelineFramework core.
- Durable changes under `app/code/Weline/**` belong here first. Site repositories receive them only through an explicitly requested update/release workflow.
- If a site-repository task temporarily changes `app/code/Weline/**`, merge that task's verified delta back into this canonical repository. Never overwrite either side wholesale.

## Start here

1. Read `AI-ENTRY.md`.
2. Follow `dev/ai/global-constraints.md` for repository-wide rules.
3. Use `dev/ai/skills/_index.md` to select only the skills needed by the task.
4. For module work, read the owning module's `doc/AI-INDEX.md`, then inspect the targeted source and existing verification surface.

`dev/ai/codex/SOUL.md`, `dev/ai/codex/USER.md`, and `dev/ai/codex/MEMORY.md` are context maps, not higher-priority rules.

## Local boundaries

- Preserve unrelated dirty working-tree changes and keep edits task-scoped.
- Local Git policy (hard): only branches `dev` and `master`; code changes only on `dev`; no `git worktree` and no other local branches. Full text: `dev/ai/global-constraints.md` §7.
- Core-to-site distribution is never implied by a core edit; use only the explicitly requested release or synchronization workflow.
- Keep domain constraints, validation rules, authorization, and delivery requirements in `dev/ai/global-constraints.md` or the owning skill instead of mirroring them here.

## Resources

- Entry and authority: `AI-ENTRY.md`
- Global constraints: `dev/ai/global-constraints.md`
- Skill routing: `dev/ai/skills/_index.md`
- Module documentation index: `dev/ai/diagrams/08-module-docs-index.txt`
- Task records: `dev/ai/codex/tasks/`

<!-- gitnexus:start -->
## Optional GitNexus compatibility

When GitNexus is intentionally selected under `dev/ai/global-constraints.md`, read the matching `.claude/skills/gitnexus/*/SKILL.md`. Its generated context is not a second rule authority.
<!-- gitnexus:end -->
