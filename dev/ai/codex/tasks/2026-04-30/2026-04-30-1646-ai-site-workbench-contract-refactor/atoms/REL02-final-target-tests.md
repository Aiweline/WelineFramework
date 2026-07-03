# REL02 Final Target Tests

## Background Snapshot

The final refactor is only acceptable if old sessions still work and the new skill/contract path works.

## Goal

Run final target tests and record results.

## Non-goals

Do not hide failing tests. Do not claim live AI verification unless it actually ran.

## Touch Points


- Queue/service tests
- Workbench e2e tests
- Task record

## Implementation Steps

1. Run contract and skill unit tests.
2. Run Stage1/Stage2 service tests.
3. Run queue propagation tests.
4. Run frontend e2e for skill selection.
5. Record pass/fail/skipped status.

## Acceptance

- Final record states exactly what passed, failed, or was skipped.

## Rollback

Use phase records to identify the smallest revert point.
