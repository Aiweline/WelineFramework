# T01 Stage2 Confirmed Contract Input

## Background Snapshot

Stage2 should only run after Stage1 is confirmed. It must consume confirmed Stage1 contracts, not mutable draft data.

## Goal

Make Stage2 load confirmed Stage1 contract input.

## Non-goals

Do not implement Stage2 output contracts yet.

## Touch Points

- `AiSiteTaskPlanQueue`
- `AiSiteVirtualThemePlanService`
- Confirmed plan/session scope reads

## Implementation Steps

1. Locate confirmed Stage1 plan storage.
2. Prefer confirmed contracts when present.
3. Fall back to compatibility adapter for old confirmed plans.
4. Fail clearly when no confirmed plan exists.
5. Add tests for present, missing, and legacy cases.

## Acceptance

- Stage2 cannot run against an unconfirmed Stage1 draft.

## Implementation Status

Done in 2026-04-30 follow-up. `AiSiteVirtualThemePlanService` now resolves confirmed Stage1 source contracts from `plan_workbench.confirmed.contracts`, falls back only for confirmed legacy plan data, and rejects unconfirmed Stage1 draft input.

## Rollback

Return Stage2 to existing confirmed plan reads.
