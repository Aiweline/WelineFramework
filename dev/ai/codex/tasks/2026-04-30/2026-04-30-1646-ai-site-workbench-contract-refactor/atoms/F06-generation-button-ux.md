# F06 Generation Button UX

## Background Snapshot

The workbench should make the difference between saving requirements and starting generation obvious.

## Goal

Collapse duplicate generation logic into one primary action per stage.

## Non-goals

Do not redesign the full page layout.

## Touch Points

- Stage plan script bindings
- Task plan panel script bindings
- Queue state module

## Implementation Steps

1. Inventory current generate/regenerate/retry buttons.
2. Keep one primary action per stage and demote duplicates.
3. Disable the button during saving or running queue jobs.
4. Show a concise summary of selected skills before generation.
5. Preserve retry behavior for failed queue jobs.

## Acceptance

- Users have a single clear action to start each generation stage.

## Implementation Status

Done on 2026-04-30:

- Stage 1 primary action now surfaces the selected-skill summary and shared queue state next to the generation button.
- Stage 1 and Stage 2 generation entrypoints set shared queue state to `saving`, `queued`, `completed`, or `failed` as requests progress.
- Primary generation/build controls now also respect the shared queue UI busy states to reduce duplicate starts.
- Retry buttons remain separate and visible only through the existing retryable-failure flow.

## Rollback

Restore previous button bindings.
