# S04 Skill Default and Snapshot

## Background Snapshot

Old sessions and users who do not choose skills must keep current behavior. New generations must freeze selected skill context.

## Goal

Resolve default skill selection and build `contract_context.skill_snapshots`.

## Non-goals

Do not wire queue requests yet. Do not change prompts yet.

## Touch Points

- Skill resolver
- Contract context builder
- Unit tests

## Implementation Steps

1. Return `['claude-design']` when selected skill codes are empty.
2. Resolve selected codes across builtin and custom providers.
3. Build snapshots with code, name, description, source, normalized body, and body hash.
4. Return readable errors for missing or disabled skills.
5. Add tests for empty/default, builtin, custom, and missing skill cases.

## Acceptance

- Empty selection produces a valid `claude-design` snapshot.
- Selected skill snapshots are deterministic.

## Rollback

Remove snapshot builder and use existing prompt guide logic.
