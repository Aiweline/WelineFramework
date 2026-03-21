# Theme Preview And Visual Editor Unification

- Started: 2026-03-21 23:35
- Status: in_progress

## Goal

Implement the unified preview/editor behavior for:

- frontend preview
- backend preview
- ThemeEditor
- PageBuilder visual editor

## Scope For This Run

- Introduce a shared preview context contract.
- Fix preview persistence across internal navigation.
- Support frontend theme + backend fallback theme composition in ThemeEditor.
- Unify internal/external link handling between ThemeEditor and PageBuilder.
- Reuse existing widget param / i18n / AI config infrastructure inside ThemeEditor where applicable.
- Add collapsible editor shell behavior for left/right panels.

## Notes

- The worktree is already dirty; avoid reverting unrelated changes.
- Several preview/runtime files are already modified or newly added in this branch, so edits must be integrated carefully.
- Existing `Weline_Widget` param UI and PageBuilder AI SSE endpoints should be reused rather than reimplemented.

## Progress Log

- 23:35 Re-read current modified state, confirmed the target files, and switched ACTIVE.md to this task.
- 23:35 Confirmed ThemeEditor already uses widget param renderer + i18n hooks, which reduces the amount of new config-form work needed.
- 01:10 Re-read workspace startup files (`SOUL.md`, `USER.md`, `memory/2026-03-21.md`, `ACTIVE.md`) and repo skill guidance before continuing implementation.
- 01:11 Reconfirmed current root causes: preview context is still single-theme/single-area, ThemeEditor still guesses internal link targets on the client, PageBuilder still uses a separate `PageBuilderVisualEditor` message contract, and frontend preview token persistence still depends on late injection paths.
- 01:13 Re-read current dirty worktree diffs in `Weline_Theme` to avoid overwriting unrelated runtime unification work already present in this branch.
- 01:15 Resumed this task in `ACTIVE.md`; next critical path is shared preview context, then ThemeEditor dual-theme state + navigation resolve, then shell-side unification.
- 01:20 Re-read workspace skill routing and repo-wide constraints; confirmed this implementation must reuse `BackendToast` / `BackendConfirm`, preserve theme metadata conventions, and run `setup:upgrade --route` if new controllers are added.
- 01:21 Corrected `dev/ai/codex/ACTIVE.md` back to this task and prepared to syntax-check the shared preview-context services before extending entry points and editor shells.

## Verification Plan

- php syntax checks for touched PHP files
- targeted browser/manual validation for preview/editor link flows
- targeted preview navigation smoke tests where auth/session allows it

## Resume Notes

- If interrupted, resume from the preview context service and ThemeEditor controller/UI wiring first; those are on the critical path for the rest of the behavior.
