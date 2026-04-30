# T03 Stage2 Frozen Source Validation

## Background Snapshot

Stage2 must not change Stage1 frozen fields such as page list, design direction, or block order.

## Goal

Validate Stage2 output against Stage1 source contracts and frozen fields.

## Non-goals

Do not build repair logic.

## Touch Points

- `AiSiteVirtualThemePlanService`
- Contract validators
- Stage2 tests

## Implementation Steps

1. Compare Stage2 source references to confirmed Stage1 ids.
2. Detect attempts to change frozen values.
3. Return readable queue errors or repairable validation messages.
4. Add tests for valid and invalid Stage2 outputs.

## Acceptance

- Stage2 cannot silently rewrite confirmed Stage1 structure.

## Implementation Status

Done in 2026-04-30 follow-up. Stage2 validation now checks generated `page_tasks` against confirmed Stage1 page and block contracts, rejecting unconfirmed page types and block rewrites with readable runtime errors.

## Rollback

Remove validation calls and trust existing Stage2 output.
