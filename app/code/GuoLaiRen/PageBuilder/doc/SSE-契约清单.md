# PageBuilder AI SSE Contract

## Event Names

All PageBuilder AI SSE events must be emitted through `AiSiteSsePayloadNormalizer` and use the event names returned by `AiSiteSsePayloadNormalizer::authoritativeEventNames()`.

Important event names:

- `start`
- `progress`
- `chunk`
- `info`
- `warning`
- `done`
- `error`
- `plan_json_block_completed`
- `plan_json_block_failed`
- `task_progress`
- `task_completed`
- `task_failed`
- `page_generated`
- `shared_component_generated`
- `asset_generation_started`
- `asset_manifest_updated`
- `asset_generation_progress`
- `asset_generation_done`
- `asset_generation_failed`
- `asset_generation_skipped`
- `block_partial_patch_applied`
- `block_partial_patch_failed`
- `environment_ready`
- `state`
- `plan_state`
- `log`
- `ai_chunk`

## Canonical Payload Fields

| Field | Type | Contract |
| --- | --- | --- |
| `operation` | string | Workspace operation such as `plan`, `build`, `regenerate_page`, `publish`, or `image_asset`. |
| `message` | string | Human-readable status text. |
| `queue_status` | string | The only queue status field exposed to frontend readers. For plan/build progress loops it is reserved for the final queue check, not progress calculation. |
| `plan_json_execution_summary` | object | Derived display summary from `plan_json.pages.{page_type}.{block_key}`. For plan/build SSE progress this is the authoritative progress source. |
| `stage1_page_progress` | object | Plan-stage page concurrency and remaining-count snapshot derived from `plan_json.pages`. |
| `page_block_progress` | object/list | Build-stage page/block progress snapshot derived from `plan_json.pages.{page_type}.{block_key}`. |
| `queue_final_check` | object | Final one-time queue verification result after plan/build `plan_json` progress is terminal. |
| `progress_kind` | string | Payload category such as `plan_json_progress`, `task_progress`, `queue_final_check`, `queue_info`, or `asset_progress`. |
| `progress_percent` | int | 0-100 display progress. |

Unsupported old aliases must not be read as queue status fallbacks. If `queue_status` is missing, fix the writer.

## Plan/Build Progress Source

For `plan` and `build` observer streams, SSE progress must scan `plan_json` state only:

- `plan` progress is derived from `plan_json.pages` and emits `progress_kind: plan_json_progress` with `stage1_page_progress`.
- `build` progress is derived from `plan_json.pages.{page_type}.{block_key}` and emits `progress_kind: task_progress` with `page_block_progress`.
- The SSE progress loop must not poll queue rows for progress, process text, result deltas, PID, or queue panel payloads.
- When the `plan_json` scan reaches a terminal state, SSE performs one final queue status verification and reports it as `progress_kind: queue_final_check`.
- Final queue verification can fail the response if the queue row is missing or reports a failure status, but it must not be used as the progress source.

## Queue Status Values

Allowed values are:

```text
pending queued running processing done error stop cancelled canceled stale timeout connection_lost
```

## Frontend Reader Contract

```js
function readQueueStatusFromPayload(payload) {
  return String(payload.queue_status || '').trim().toLowerCase();
}
```

Do not add alias branches that read old queue-status names before or after `queue_status`.
