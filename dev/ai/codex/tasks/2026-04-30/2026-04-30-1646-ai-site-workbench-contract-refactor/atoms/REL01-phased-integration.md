# REL01 Phased Integration

## Background Snapshot

Atomic implementation reduces merge risk, but integration still needs ordering.

## Goal

Integrate completed atoms in dependency order.

## Non-goals

Do not batch unrelated risky frontend and backend rewrites in one commit.

## Touch Points

- Atomic task index
- Git status
- Test commands

## Implementation Steps

1. Integrate contract core before service prompt changes.
2. Integrate skill core before frontend skill UI.
3. Integrate queue propagation before Stage1/Stage2 contract output.
4. Integrate frontend e2e after API and UI are both present.
5. Record each integration result.

## Acceptance

- Each phase can be tested and reverted independently.

## Rollback

Revert only the latest phase, not the whole refactor.
