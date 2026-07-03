# A01 Protect Existing User Changes

## Background Snapshot



## Goal

Document dirty files and mark them as protected for all later workers.

## Non-goals

Do not inspect secrets. Do not revert. Do not stage or commit.

## Touch Points

- `git status --short`
- This task directory notes

## Implementation Steps

1. Capture dirty file list.
2. Add a protected-files note under this task directory.
3. Tell implementation workers to avoid those files unless their atom explicitly owns them.

## Acceptance

- Protected file list is visible in the task directory.
- Later worker prompts reference the protection rule.

## Rollback

Remove the note if it becomes obsolete after user confirmation.
