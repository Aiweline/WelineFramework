# T04 Stage2 No History Tests

## Background Snapshot

Stage2 batch generation previously needed disabled conversation history to avoid huge repeated context.

## Goal

Protect the no-history behavior with tests while adding contract inputs.

## Non-goals

Do not change AiService global history behavior.

## Touch Points

- `AiSiteVirtualThemePlanService`
- Stage2 batch params
- Unit tests

## Implementation Steps

1. Locate current no-history flags in Stage2 calls.
2. Ensure new contract context does not reattach conversation history.
3. Add regression tests or parameter assertions.
4. Keep queue logs clear for context-size failures.

## Acceptance

- Contract refactor does not reintroduce massive conversation-history payloads.

## Rollback

Remove only the new tests if they are too coupled, but keep runtime no-history flags.

## Implementation Status

Status: done on 2026-04-30.

Evidence:

- `AiSiteVirtualThemePlanServiceTest` already asserts no-history/no-persist params for Stage2 stream, JSON fallback, heartbeat stream, refine, and rebuild fallback paths.
- `AiSiteVirtualThemePlanService` keeps `disable_conversation_history` and `disable_conversation_persist` on Stage2 batch and prompt request params.
- Full target suite passed after Build contract changes: 206 tests, 2477 assertions.
