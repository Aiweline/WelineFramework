# C02 Permission and Frozen Fields

## Background Snapshot

After human confirmation, downstream stages must not silently rewrite page structure, brand direction, design system, or block boundaries.

## Goal

Add permission matrix and frozen/mutable field validation.

## Non-goals

Do not integrate with Stage1 or Stage2 prompts yet.

## Touch Points




## Implementation Steps

1. Define default permission matrix presets for Stage1, Stage2, Build, QA, and Repair.
2. Add a validator that compares previous and next contract arrays.
3. Return clear errors for attempted frozen field changes.
4. Add tests for allowed and forbidden patches.

## Acceptance

- Frozen fields cannot be changed without validator failure.
- Mutable fields can be changed when permission allows.

## Rollback

Remove the validator and matrix presets.
