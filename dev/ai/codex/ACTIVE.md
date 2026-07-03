# ACTIVE.md (Deprecated)

Do not write new mutable task state here.
Use dedicated task workspaces under `dev/ai/codex/tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/` instead.
The legacy snapshot below is preserved only for backward context.

---

# Active Task

- Updated: 2026-03-23 10:24

- Status: completed

## Current Goal

Close the gap between the planned `Weline_Websites` AI site-building workbench and the currently live entrypoints:

- confirm what is already finished under `dev/ai/codex/AI工作台/`
- make the Websites AI workbench entry more human-friendly



## Latest Progress

- Completed workspace startup context per `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router`, then loaded `extension-points` and `theme-development`.
- Confirmed the planning docs under `dev/ai/codex/AI工作台/` are not fully implemented yet:
  - Epic 1 and Epic 2 are completed

- Compared current live code paths:
  - `Weline_Websites` still uses the older one-shot `SiteBuilderAgent` form + SSE flow



## Verification

- `php -l` passed for all touched PHP and `.phtml` files.
- `app/code/Weline/Websites/etc/backend/menu.xml` parses successfully after rewrite.



## Risks / Notes

- The worktree is dirty in many unrelated files; edits must remain tightly scoped.



## Next

- If we continue this initiative, the next meaningful slice is to move from entry unification into true platform-level session/workspace unification:
  - Websites-controlled session/message/event/artifact workspace

  - default provider conversation/domain/theme/draft flow completion
