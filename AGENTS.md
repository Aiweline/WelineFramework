# AGENTS.md

## Repository identity

- `/Users/weline/Project/Official/框架` is the canonical macOS repository for WelineFramework core.
- Durable changes under `app/code/Weline/**` belong here.
- Distribution to consuming sites is never automatic; use only an explicitly requested 「分项」, 「回灌」, deployment, or named release workflow.
- There is no standing dual-repo merge/align workflow with retired site peers (including QiPai).

## Start here

1. Read `AI-ENTRY.md`.
2. Follow `dev/ai/global-constraints.md` for repository-wide rules.
3. Use `dev/ai/skills/_index.md` to select only the skills needed by the task.
4. For module work, read the owning module's `doc/AI-INDEX.md`, then inspect the targeted source and existing verification surface.
5. **WebUI 验收（强制）**：产品/UI 功能必须在真实 WLS + Cursor 内置 Browser 按用户路径测通后才能结案；详见 `global-constraints.md` §5 / §10.1 与 `.cursor/rules/real-device-acceptance.mdc`。禁止仅用 CLI/`curl`/单测冒充验收。

`dev/ai/codex/SOUL.md`, `dev/ai/codex/USER.md`, and `dev/ai/codex/MEMORY.md` are context maps, not higher-priority rules.

## Local boundaries

- Preserve unrelated dirty working-tree changes and keep edits task-scoped.
- Local Git policy (hard): only branches `dev` and `master`; code changes only on `dev`; no `git worktree` and no other local branches. Full text: `dev/ai/global-constraints.md` §7.
- Core-to-site distribution is never implied by a core edit; use only the explicitly requested release workflow.
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
