# P03 Stage1 Block Plan Contract

## Background Snapshot

Block Plan is the frozen bridge from site/page planning to Stage2 task decomposition.

## Goal

Emit a Block Plan contract for every planned page and block.

## Non-goals

Do not generate implementation task details. Stage2 owns task-level decomposition.

## Touch Points

- `AiSiteExecutionBlueprintService`
- Existing `block_index` and `page_plans` structures
- Contract helpers

## Implementation Steps

1. Map existing page/block plan data into Block Plan contract entries.
2. Require stable page and block identifiers.
3. Mark page structure and block order as frozen after confirmation.
4. Include source links to Site Brief/Page Contract where useful.
5. Add tests for multi-page and empty-page edge cases.

## Acceptance

- Every planned block has a contract entry Stage2 can reference.

## Rollback

Remove Block Plan output and Stage2 can use old `page_plans` data.
