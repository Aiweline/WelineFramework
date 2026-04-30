# C01 Contract Types and Meta

## Background Snapshot

Generated AI artifacts need a stable envelope before Stage1, Stage2, Build, QA, and Repair can share data safely.

## Goal

Add contract type constants and a `contract_meta` builder.

## Non-goals

Do not change prompts. Do not change queue execution. Do not migrate old data.

## Touch Points

- `app/code/GuoLaiRen/PageBuilder/Service/AI/Contract/*`
- PageBuilder unit tests

## Implementation Steps

1. Create a contract type/status constant service.
2. Create a meta builder that returns id, version, stage, status, creator, adapter type, and timestamp.
3. Keep generated ids deterministic enough for tests or injectable.
4. Add pure unit tests.

## Acceptance

- Tests can build meta for every v1 contract type.
- No existing PageBuilder generation behavior changes.

## Rollback

Remove the new contract service and tests.
