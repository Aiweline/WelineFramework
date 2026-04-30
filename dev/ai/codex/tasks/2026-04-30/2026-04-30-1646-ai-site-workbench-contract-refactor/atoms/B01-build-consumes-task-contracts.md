# B01 Build Consumes Task Contracts

## Background Snapshot

Build currently consumes confirmed task plan structures. New sessions should build from confirmed Block Task Contracts.

## Goal

Make Build prefer Block Task Contract input.

## Non-goals

Do not remove old task plan support.

## Touch Points

- `AiSiteBuildTaskService`
- Confirmed task plan/session reads
- Contract helpers

## Implementation Steps

1. Detect confirmed Stage2 contract data.
2. Map Block Task Contract entries to build blueprint tasks.
3. Preserve existing task plan path as fallback.
4. Add tests with contract input.

## Acceptance

- Build can generate build tasks from new contracts.

## Rollback

Disable contract input branch and use old task plan branch.

## Implementation Status

Status: done on 2026-04-30.

Implemented:

- `AiSiteBuildTaskService` now resolves confirmed Stage2 contract sets and prefers `block_task_contract.payload` when building the Build blueprint.
- The Build blueprint records `contract_source`, `block_task_contract_id`, upstream Stage2 refs, and Stage1 source refs for traceability.
- Regression coverage verifies contract input wins over legacy confirmed task-plan fields.
