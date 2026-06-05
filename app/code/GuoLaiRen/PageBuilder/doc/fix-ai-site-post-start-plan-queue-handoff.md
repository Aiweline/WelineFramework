# Fix: AI Site Queue Handoff Latency

## Problem

`post-start-plan` and `post-confirm-plan` are queue handoff endpoints. They should persist the requested scope, create or reuse the PageBuilder AI queue row, and return the queue/SSE metadata needed by the browser.

Some start-plan response branches were still hydrating the full workspace state through `buildWorkspaceState(..., true)`. On large AI site sessions this can resend plan content, page state, assets, events, and queue summaries before the HTTP response is returned. Online this makes the start request look blocked even though the AI work is supposed to run in the background queue.

The confirm-plan endpoint has the same contract when it confirms a plan and starts the build queue. It may synchronously validate and persist the confirmed `plan_json`, but it must not wake or wait for the build queue from the controller request. Confirm-only responses should also avoid full workspace hydration because they are used as a lightweight prerequisite before selected block generation.

## Fix

- Confirmation and reuse responses now use a compact start-plan state patch.
- Running plan reuse responses now reuse the queue-oriented state returned by `startOperation`.
- Existing active queue guard responses now return `buildQueuedOperationState(...)` directly instead of wrapping a full workspace state.
- PageBuilder queue handoff calls pass `wake_scheduler => false` when creating, resetting, or taking over queue rows, so controller requests do not start queue workers.
- Repeated plan or build starts now replace the previous same-slot queue run: reuse the existing `queue_slot` row, terminate any live old pid through `queue.takeover`, then reset the row to `pending` with the latest payload.
- `post-confirm-plan` build start uses the shared `startOperation('build')` handoff, and confirm-only responses return a compact confirm payload instead of rebuilding full workspace state.
- Scheduler-aware integration assertions allow the system scheduler to move the queue from `pending` to `running` before the test reads it, while still asserting that the controller did not self-dispatch the queue.

## Contract

Start endpoints such as `post-start-plan` and `post-confirm-plan` should not call full workspace hydration as a fallback for queue start responses. Full workspace state is still available through the workspace state/polling endpoints; queue start responses should carry only operation status, `execution_token`, `stream_url`, `queue_id`, and `queue_wait` data.

Queue creation and reset must remain a pure handoff. The query-provider default is allowed to wake the scheduler for generic queue use, but PageBuilder start endpoints must opt out and leave dispatch to the system scheduler.

For plan and build starts, the session has one durable queue slot per stage. A fresh click should not create a competing queue while an older same-slot worker is still alive; it should take over that row, kill the old pid when present, clear stale runtime state, and leave the row pending for the scheduler.
