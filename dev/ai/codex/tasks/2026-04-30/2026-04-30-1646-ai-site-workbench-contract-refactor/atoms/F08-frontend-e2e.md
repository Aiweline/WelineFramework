# F08 Frontend E2E

## Background Snapshot

Skill UX and queue boundaries need browser-level coverage because regressions are mostly interaction/state issues.

## Goal

Add or update e2e coverage for skill management and generation start payload.

## Non-goals

Do not depend on live AI responses.

## Touch Points


- Skill API mocks or test fixtures
- Queue start request assertions

## Implementation Steps

1. Protect any existing user edits to the e2e file.
2. Add coverage for loading skills.
3. Add coverage for creating/selecting a custom skill.
4. Assert selected skill codes are sent when starting plan generation.
5. Assert frontend does not call direct AI execution endpoints.

## Acceptance

- E2E catches missing skill selection propagation without live AI.

## Rollback

Remove the new e2e cases only.
