# F02 Autosave Consolidation

## Background Snapshot

Autosave behavior should be consistent for requirement fields and skill selection. Users need clear saved/saving/failed feedback.

## Goal

Unify requirement autosave into one debounced controller.

## Non-goals

Do not alter backend scope API beyond selected skills.

## Touch Points

- Workbench frontend scripts
- Existing scope save endpoint
- Needs form state module

## Implementation Steps

1. Route all requirement field changes through one debounced save function.
2. Show consistent save state.
3. Avoid duplicate concurrent saves for the same payload.
4. Preserve existing manual save behavior if present.
5. Add e2e or DOM-level smoke check.

## Acceptance

- Editing any requirement field or skill selection triggers the same save flow.

## Rollback

Restore previous per-field autosave handlers.
