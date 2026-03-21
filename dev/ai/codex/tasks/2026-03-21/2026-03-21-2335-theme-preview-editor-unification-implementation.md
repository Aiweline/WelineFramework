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

## Verification Plan

- php syntax checks for touched PHP files
- targeted browser/manual validation for preview/editor link flows
- targeted preview navigation smoke tests where auth/session allows it

## Resume Notes

- If interrupted, resume from the preview context service and ThemeEditor controller/UI wiring first; those are on the critical path for the rest of the behavior.
