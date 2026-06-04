# AI Site Workspace Frontend State Contract

## Inputs

Frontend status readers consume only canonical workspace/SSE fields:

- `active_operation.operation`
- `active_operation.queue_status`
- `active_operation.message`
- `active_operation.progress_percent`
- `active_operation.queue_id`
- `active_operation.retry_allowed`
- `active_operation.failure_mode`
- `active_operation.queue_waiting_for_scheduler`
- `active_operation.can_close_stream`
- `plan_queue_info.queue_status`
- `build_queue_info.queue_status`

Do not read legacy queue-status aliases. If `queue_status` is missing, fix the backend writer or SSE normalizer.

## Status Matrix

| Canonical value | UI state | Button rule |
| --- | --- | --- |
| `pending` | waiting for scheduler | keep current operation locked |
| `queued` | queued | keep current operation locked |
| `running` / `processing` | running | keep current operation locked and publish locked |
| `done` / `complete` / `completed` | success | unlock next stage when plan_json gates pass |
| `error` / `failed` / `fail` | failed | lock same-stage action unless retry is explicitly allowed |
| `cancelled` / `canceled` / `stop` / `stopped` | cancelled | do not treat as success |
| `stale` / `expired` / `outdated` | stale | wait for fresh workspace state |
| `timeout` / `timed_out` / `connection_timeout` | timeout | switch to workspace polling |
| `connection_lost` / `sse_interrupted` / `disconnected` | interrupted | do not mark business failure |

## SSE Examples

```text
event: progress
data: {"operation":"build","queue_status":"running","progress_kind":"queue_info","queue_info":{"queue_status":"running"}}

event: error
data: {"operation":"plan","queue_status":"timeout","message":"Queue terminal status was not received."}
```
