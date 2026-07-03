# P04 Stage1 Sanitization and Tests

## Background Snapshot



## Goal

Harden Stage1 contract sanitation and add regression tests.

## Non-goals

Do not tune model selection. Do not expand frontend UI.

## Touch Points

- `AiSiteExecutionBlueprintService`
- Stage1 unit tests

## Implementation Steps

1. Reject outputs that read like prompt examples, meta-instructions, or generic advice.
2. Validate required contract keys before saving draft.
3. Keep error messages readable in queue logs.
4. Add tests for valid contracts and prompt-like invalid output.

## Acceptance

- Invalid prompt-like Stage1 output does not become a confirmed draft.

## Rollback

Disable new sanitation checks and keep existing output handling.
