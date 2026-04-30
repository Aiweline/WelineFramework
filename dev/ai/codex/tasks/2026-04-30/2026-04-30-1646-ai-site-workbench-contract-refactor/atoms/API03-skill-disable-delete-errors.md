# API03 Skill Disable Delete Errors

## Background Snapshot

Skills may be referenced by historical contracts. Hard deletion can make history unreadable.

## Goal

Add disable/delete behavior and stable error responses.

## Non-goals

Do not physically delete historical snapshots.

## Touch Points

- Skill API handlers
- Skill repository
- Contract snapshot readers later

## Implementation Steps

1. Implement disable/toggle for custom skills.
2. Treat builtin delete as hide/disable for selection only.
3. Prevent hard delete if historical usage is detectable.
4. Return field-level errors for invalid operations.
5. Add tests for disabled and protected skills.

## Acceptance

- Disabled skills disappear from selectable options but remain displayable in history.

## Rollback

Remove disable/delete endpoints and keep list/save only.
