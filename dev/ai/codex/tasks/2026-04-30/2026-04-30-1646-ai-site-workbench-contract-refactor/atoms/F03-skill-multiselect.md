# F03 Skill Multiselect

## Background Snapshot

Users need to select skills at the requirement position before generation starts.

## Goal

Add a skill multi-select UI with chips to the requirements panel.

## Non-goals

Do not build create/edit skill UI in this atom.

## Touch Points

- Plan card requirement template
- Skill list API
- Needs form state module

## Implementation Steps

1. Load selectable skills from the skill list endpoint.
2. Render selected skills as chips.
3. Persist selected codes into needs form state.
4. Block generation when selected skill is disabled or missing.
5. Keep empty selection equivalent to default skill behavior.

## Acceptance

- User can select multiple skills and the selected codes enter the generation payload.

## Rollback

Hide the selector and fall back to default skill behavior.
