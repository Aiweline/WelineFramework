# Fix: AI Site Queue Handoff Latency

## Problem

`post-start-plan` and `post-confirm-plan` are queue handoff endpoints. They should persist the requested scope, create or reuse the PageBuilder AI queue row, and return the queue/SSE metadata needed by the browser.

Some start-plan response branches were still hydrating the full workspace state through `buildWorkspaceState(..., true)`. On large AI site sessions this can resend plan content, page state, assets, events, and queue summaries before the HTTP response is returned. Online this makes the start request look blocked even though the AI work is supposed to run in the background queue.

The confirm-plan endpoint has the same contract when it confirms a plan and starts the build queue. It may synchronously validate and persist the confirmed `plan_json`, but it must not wake or wait for the build queue from the controller request. Confirm-only responses should also avoid full workspace hydration because they are used as a lightweight prerequisite before selected block generation.

## Fix

- Confirmation and reuse responses now use a compact start-plan state patch.
- Running plan reuse responses now reuse the queue-oriented state returned by `startOperation`.
- Existing active queue guard responses now return `buildQueuedOperationState(...)` directly instead of wrapping a full workspace state.
- Queue handoff calls no longer use the removed `wake_scheduler` parameter; the Queue query provider persists rows and dispatches queue events only, leaving execution to the queue scheduler.
- Repeated plan or build starts observe the previous same-slot queue run when it is still `pending`, `queued`, `running`, or `processing`; the controller returns the existing queue metadata instead of taking over or killing the old pid.
- `post-confirm-plan` build start uses the shared `startOperation('build')` handoff, and confirm-only responses return a compact confirm payload instead of rebuilding full workspace state.
- Scheduler-aware integration assertions allow the system scheduler to move the queue from `pending` to `running` before the test reads it, while still asserting that the controller did not self-dispatch the queue.

## Contract

Start endpoints such as `post-start-plan` and `post-confirm-plan` should not call full workspace hydration as a fallback for queue start responses. Full workspace state is still available through the workspace state/polling endpoints; queue start responses should carry only operation status, `execution_token`, `stream_url`, `queue_id`, and `queue_wait` data.

Queue creation and reset must remain a pure handoff. The query provider must not use request parameters such as `wake_scheduler`, `dispatch`, or `auto_dispatch` to synchronously wake queue execution; dispatch belongs to the queue scheduler.

For plan and build starts, the session has one durable queue slot per stage. A fresh click should not create a competing queue while an older same-slot queue is still active; it should return the existing queue metadata. Only when no active same-slot queue exists should the controller create or reset a pending row for the scheduler.
