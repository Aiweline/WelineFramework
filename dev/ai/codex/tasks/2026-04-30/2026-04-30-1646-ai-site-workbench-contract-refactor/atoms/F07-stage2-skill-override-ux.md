# F07 Stage2 Skill Override UX

## Background Snapshot

Stage2 should inherit Stage1 skills by default but may need explicit override for task decomposition.

## Goal

Display inherited skills and allow explicit Stage2 override with warning.

## Non-goals

Do not alter backend inheritance rules beyond sending selected codes.

## Touch Points

- Task plan panel template
- Skill selector component
- Task plan start payload

## Implementation Steps

1. Show Stage1 selected skills in the task stage.
2. Add an override toggle.
3. Warn that override affects task contract only.
4. Send override codes only when explicitly enabled.
5. Preserve inherited default otherwise.

## Acceptance

- Stage2 users understand whether skills are inherited or overridden.

## Implementation Status

Done on 2026-04-30:

- Added a Stage 2 skill strategy panel that shows inherited Stage 1 skills.
- Added an explicit override switch with warning copy that override only affects the Stage 2 task contract.
- Override choices reuse the existing skill registry options and validation.
- `selected_skill_codes` is sent to `postStartTaskPlan` only when the override switch is enabled; otherwise Stage 2 preserves backend inheritance.

## Rollback

Remove override UI and always inherit Stage1 skills.
