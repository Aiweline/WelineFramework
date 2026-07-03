# A00 Baseline

## Background Snapshot

The current branch already has user edits. Before contract work starts, record current behavior so later failures are attributable. This atom does not fix failures.

## Goal



## Non-goals

Do not edit code. Do not fix failing tests. Do not overwrite user changes.

## Touch Points

- `git status --short --branch`

- Workbench e2e command, if available

## Implementation Steps

1. Capture current git status.

3. Run the existing workbench e2e spec only if environment dependencies are available.
4. Record commands and results in this task directory.

## Acceptance

- A baseline note exists with commands, pass/fail status, and any skipped checks.

## Rollback

Delete the baseline note only; no product code should have changed.
