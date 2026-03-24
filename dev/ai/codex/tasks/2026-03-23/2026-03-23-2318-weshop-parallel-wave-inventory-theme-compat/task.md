# Task: weshop-parallel-wave-inventory-theme-compat

- Task ID: 2026-03-23-2318-weshop-parallel-wave-inventory-theme-compat
- Started: 2026-03-23 23:18
- Status: in_progress
- Owner: Codex
- Source: 继续提交后完成剩余的，并行操作

## Goal

- Continue the WeShop international-commerce completion wave with one commit-sized backend/module slice plus the missing theme-compatibility warning foundation, without touching `WeShop_Theme` or `Weline_Theme` source.
- Keep the `default` theme as the compatibility baseline while making editor save/publish flows surface missing hook or slot warnings through both editor feedback and `w_msg()`.

## Scope

- In scope:
- `WeShop_Inventory` backend hardening: thin admin controllers, route/menu metadata, validation/service extraction where needed, and focused unit coverage.
- WeShop-side theme compatibility runtime: detect missing required hook/slot hosts for enabled WeShop modules, warn on editor save/publish, and keep the implementation outside theme-module source.
- A second bounded backend module slice in parallel if it can stay on a disjoint write-set.
- Out of scope:
- Editing `WeShop_Theme` or `Weline_Theme` module source.
- Unrelated dirty worktree changes outside the owned WeShop files and this task workspace.
- Repo-wide schema/runtime blockers that already fail `setup:upgrade` globally (`BTREE` / legacy SQLite adapter drift).

## Constraints

- Follow task-workspace rules instead of writing mutable state back into `dev/ai/codex/ACTIVE.md`.
- Use `apply_patch` for manual edits.
- Respect existing dirty changes and never revert unrelated work.
- Keep frontend compatibility aligned with hook/slot injection and `w_query()` patterns.
- Treat live runtime notes from the user as authoritative: later smoke checks should prefer port `9982` when a live server is available.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-2314-weshop-module-wave-phase-3/`

## Related Files

- `app/code/WeShop/Inventory/**`
- `app/code/WeShop/Base/**`
- `app/design/WeShop/default/**`
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php` (read-only integration target)
- `app/code/Weline/Theme/Service/ThemeLayoutService.php` (read-only integration target)

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
