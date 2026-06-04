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
| `queue_status` | string | The only queue status field exposed to frontend readers. |
| `plan_json_execution_summary` | object | Derived display summary from `plan_json.pages.{page_type}.{block_key}`. |
| `progress_kind` | string | Payload category such as `queue_info`, `task_progress`, or `asset_progress`. |
| `progress_percent` | int | 0-100 display progress. |

Unsupported old aliases must not be read as queue status fallbacks. If `queue_status` is missing, fix the writer.

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
