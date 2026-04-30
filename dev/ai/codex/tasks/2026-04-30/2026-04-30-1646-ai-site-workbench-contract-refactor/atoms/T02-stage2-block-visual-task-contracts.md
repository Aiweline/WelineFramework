# T02 Stage2 Block Visual Task Contracts

## Background Snapshot

Stage2 turns block plans into executable design and implementation details. It must not redefine upstream strategy.

## Goal

Emit Block Visual Contract and Block Task Contract.

## Non-goals

Do not modify Build consumption yet.

## Touch Points

- `AiSiteVirtualThemePlanService`
- Existing block task schema logic
- Contract helpers

## Implementation Steps

1. Extend Stage2 schema instructions for visual and task contracts.
2. Reference Stage1 Block Plan ids in `source_contracts`.
3. Preserve existing `virtual_theme_plan` fields.
4. Normalize task rows into contract entries.
5. Add tests with mocked Stage2 output.

## Acceptance

- Stage2 draft contains new contracts and old task plan structure.

## Implementation Status

Done in 2026-04-30 follow-up. Stage2 output now preserves the legacy task-plan fields and adds `task_plan_workbench` plus `stage2_contracts` with `block_visual_contract` and `block_task_contract`.

## Rollback

Stop writing Stage2 contract fields and keep old task plan output.
