# S05 Skill Disable and Conflict Rules

## Background Snapshot

Users can create skills, but custom skills must not hijack builtin skill codes or break historical contracts.

## Goal

Add validation for code conflicts, disabled skills, and historical display behavior.

## Non-goals

Do not implement frontend controls.

## Touch Points

- Skill repository
- Skill resolver
- Skill API later callers

## Implementation Steps

1. Reject custom skill codes that collide with builtin codes.
2. Reject duplicate custom codes.
3. Exclude disabled skills from new generation selection.
4. Allow historical snapshots to render even if current skill is disabled.
5. Add tests.

## Acceptance

- Disabled skills cannot be selected for new queue jobs.
- Historical snapshots do not disappear.

## Rollback

Disable the custom conflict checks and fall back to simple lookup.
