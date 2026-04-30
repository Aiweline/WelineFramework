# S03 Skill Normalize and Hash

## Background Snapshot

Skill content may change over time. Contracts need stable skill snapshots for reproducibility.

## Goal

Normalize skill body text and compute a stable hash.

## Non-goals

Do not decide skill selection UI. Do not store full snapshots yet.

## Touch Points

- Skill registry/provider services
- Unit tests

## Implementation Steps

1. Normalize line endings and trim empty edges.
2. Collapse dangerous excessive blank lines only if it does not alter meaning.
3. Enforce body length limits with readable errors.
4. Compute a stable body hash.
5. Add tests for equivalent line endings.

## Acceptance

- Same semantic body text gives stable hash.
- Empty or oversized body returns a clear validation error.

## Rollback

Remove normalizer and hash code.
