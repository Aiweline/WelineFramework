# C04 Legacy Contract Adapter

## Background Snapshot

Existing sessions may only have `execution_blueprint` or `virtual_theme_plan`. The refactor must not make those sessions unusable.

## Goal

Add an adapter that exposes old artifacts as temporary v1 contract arrays.

## Non-goals

Do not rewrite stored old session data. Do not require migrations.

## Touch Points

- Contract service namespace
- `AiSiteBuildTaskService` later callers
- Unit tests

## Implementation Steps

1. Inspect old Stage1 and Stage2 array shapes.
2. Map old plan fields to Site Brief, Page Contract, and Block Plan views.
3. Map old task plan fields to Block Task Contract views.
4. Mark adapter output as compatibility status in `contract_meta`.
5. Add tests with minimal old fixtures.

## Acceptance

- Old fixture arrays produce valid temporary contract arrays.
- No database writes are required.

## Rollback

Remove the adapter and callers fall back to old direct reads.
