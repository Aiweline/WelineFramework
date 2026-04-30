# F05 Queue State Module

## Background Snapshot

Plan, task, and build stages share queue states but currently duplicate UI branches.

## Goal

Extract shared queue state display logic.

## Non-goals

Do not add new execution paths.

## Touch Points

- Workbench frontend scripts
- Existing SSE terminal/status UI

## Implementation Steps

1. Define states: idle, saving, queued, waiting, running, failed, completed.
2. Normalize plan/task/build queue response handling.
3. Keep existing SSE subscriptions.
4. Display queue id and retry guidance consistently.
5. Add e2e smoke coverage where possible.

## Acceptance

- All stages render queue status through one helper.

## Implementation Status

Done on 2026-04-30:

- Added a shared frontend queue UI normalizer for `plan`, `task_plan`, and `build`.
- Normalized queue UI states to `idle`, `saving`, `queued`, `waiting`, `running`, `failed`, and `completed`.
- Kept backend queue ownership unchanged: frontend only renders status, disables unsafe duplicate actions, and preserves existing SSE/operation runner paths.
- Status summaries show stage label, queue id when available, scheduler-wait guidance, and retry guidance for failed states.

## Rollback

Return status rendering to existing per-stage branches.
