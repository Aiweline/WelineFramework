# C03 Source Contracts and QA Gates

## Background Snapshot

Downstream contracts must be traceable to upstream contracts, and QA status must be explicit instead of hidden in prose.

## Goal

Add `source_contracts` and `qa_gates` helpers and validators.

## Non-goals

Do not implement actual linters or repair logic.

## Touch Points

- `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/*`
- PageBuilder unit tests

## Implementation Steps

1. Add a helper to normalize source contract references.
2. Add a helper to create pending/pass/fail/warn QA gates.
3. Validate that downstream contracts include required source references.
4. Add unit tests.

## Acceptance

- Stage2/Build style contracts can be validated for source references.
- QA gate state is represented as structured data.

## Rollback

Remove the source and QA helper classes.
