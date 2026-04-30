# [ID] Title

## Background Snapshot

The AI site workbench flow is requirements -> plan queue -> Stage1 draft -> human confirmation -> task queue -> Stage2 draft -> human confirmation -> build queue. AI execution must stay in queue workers. SSE only displays logs and status.

## Goal

Describe the one deliverable for this atom.

## Non-goals

List adjacent work that must not be done in this atom.

## Touch Points

- List the likely files or services.

## Implementation Steps

1. Inspect the named source files.
2. Make the smallest change for this atom.
3. Add targeted tests if the atom touches behavior.
4. Do not change unrelated files or user-modified files.

## Acceptance

- State the observable result.

## Rollback

Remove this atom's new service/field/endpoint and callers should fall back to the previous behavior.
