# RP01 Repair Planner Executor

## Background Snapshot

Repair must never bypass permission rules. It can only patch fields explicitly allowed by the contract matrix.

## Goal

Add v1 repair planner and executor for allowed-field patches.

## Non-goals

Do not build an AI-driven repair agent yet.

## Touch Points

- QA findings
- Contract permission validator
- Build output or contract patch service

## Implementation Steps

1. Convert QA findings into patch candidates.
2. Validate each patch against permission matrix and mutable fields.
3. Apply only allowed patches.
4. Re-run QA after repair.
5. Add tests for allowed and blocked patch attempts.

## Acceptance

- Repair cannot change frozen fields.
- Repair result includes a new QA state.

## Rollback

Disable repair execution and keep QA report only.
