# F04 Skill Manager Drawer

## Background Snapshot

Skill creation should happen in the workbench without editing server files.

## Goal

Add a skill manager drawer or modal for custom skills.

## Non-goals

Do not change generation prompts in this atom.

## Touch Points

- Workbench templates
- Skill list/save/disable endpoints
- Skill multi-select refresh logic

## Implementation Steps

1. Add open/close drawer UI.
2. List builtin and custom skills with source/status.
3. Allow create/edit custom skills.
4. Allow disable custom skills and hide builtin skills from selection.
5. Refresh selector after save.

## Acceptance

- User can create a custom skill and immediately select it in requirements.

## Rollback

Remove the drawer and keep backend skill APIs unused.
