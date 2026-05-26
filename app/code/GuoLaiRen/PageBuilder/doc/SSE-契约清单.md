# PageBuilder AI 建站工作台 SSE 契约清单

> 范围：`GuoLaiRen_PageBuilder` 模块下 AI 建站会话相关的 Server-Sent Events 流。  
> 适用：前端 `view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml` 与后端
> `Controller/Backend/AiSiteAgent.php` / `Service/AiSiteAgentQueueObserverStreamService.php` /
> `Queue/AiSiteAssetQueue.php` / `Queue/AiSitePlanQueue.php` 等任何对 workspace 流发送 SSE 的入口。

## 1. 核心原则

1. **事件名集合是封闭契约**：后端发的 `sendEvent('xxx', ...)` 必须在 `AiSiteSsePayloadNormalizer::authoritativeEventNames()` 中存在；前端 `addEventListener('xxx', ...)` 同理。两边集合必须**对称**（白名单允许的差异除外），由 `AiSiteSseEventContractTest` 自动锁住。
2. **payload 字段唯一权威**：每个语义字段只有一个权威键名，所有 SSE payload 通过 `AiSiteSsePayloadNormalizer::normalize()` 统一产出。过渡期 normalizer 会自动把权威字段镜像到旧别名，老前端读取点不破坏。
3. **状态机以队列 status 为准**：`queue_status` 字段是面向前端的唯一权威，**强制小写**。任何 `Status`/`RUNNING` 这种大小写漂移都被 normalizer 拒收。

## 2. 事件名权威清单

清单同步自 `AiSiteSsePayloadNormalizer::authoritativeEventNames()`，新增/废弃事件必须先改这份代码与本文档，再改具体调用点。

| 事件名 | 语义 | 必含字段（除通用 `operation`/`message`） |
|---|---|---|
| `start` | 操作流开始 | — |
| `progress` | 阶段进度（不针对单任务） | `progress_percent`、`progress_kind` |
| `chunk` | 增量文本（AI 流片段） | `chunk` / `content` |
| `info` | 提示信息（队列状态变更、PID 领取等） | `progress_kind`、`observer_detail`(bool) |
| `warning` | 告警（重复流、可恢复异常） | — |
| `done` | 操作流终止（成功） | `state` |
| `error` | 操作流终止（失败） | `http_code`、`details` |
| `task_progress` | 任务汇总实时刷新 | `task_summary`（权威） |
| `task_completed` | 单任务完成 | `task_key`、`task_type`、`state` |
| `task_failed` | 单任务失败硬信号 | `task_key`、`failure_reason`、`error_message`、`attempt_no`、`max_attempts`、`state` |
| `page_generated` | 单页面生成完成或更新 | `page_type`、`page_id`、`page_label`、`page_completed`、`state` |
| `shared_component_generated` | 共享 header/footer 生成 | `region`(header/footer)、`state` |
| `asset_generation_started` | 单资产生成启动 | `slot_id`、`asset_manifest` |
| `asset_manifest_updated` | 资产清单更新 | `slot_id`、`asset_manifest` |
| `asset_generation_progress` | 资产生成进度 | `slot_id`、`progress_percent` |
| `asset_generation_done` | 单资产生成完成 | `slot_id`、`final_url`、`state` |
| `asset_generation_failed` | 单资产生成失败 | `slot_id`、`failure_reason` |
| `asset_generation_skipped` | 资产复用跳过生成 | `slot_id`、`state` |
| `block_partial_patch_applied` | 区块部分补丁已应用 | `page_type`、`block_id`、`state` |
| `block_partial_patch_failed` | 区块部分补丁失败 | `page_type`、`block_id`、`failure_reason` |
| `environment_ready` | 编辑环境就绪 | `state` |
| `snapshot` | workspace stream 首帧完整 state | `queue_id`、`queue_status` |
| `log` | 队列原始日志流（QueueLogStream/PlanQueue/BuildQueue） | `message` |
| `ai_chunk` | AI 流片段（visual edit 流，前端目前未直接消费） | `chunk` |

## 3. payload 权威字段

清单同步自 `AiSiteSsePayloadNormalizer::authoritativePayloadFields()`：

| 字段 | 类型 | 含义 | 别名（normalizer 镜像，过渡期保留） |
|---|---|---|---|
| `operation` | string | 当前操作名（plan/build/regenerate_page/...） | — |
| `message` | string | 面向用户的可读消息 | — |
| `queue_status` | string（小写） | 队列权威状态 | `status`、`job_status`、`state`、`semantic_status` |
| `task_summary` | object | 任务汇总 { total, todo, doing, done, failed, cancelled, groups[] } | `task_progress`、`build_task_summary` |
| `progress_kind` | string | payload 子类（task_progress/queue_info/page_progress/asset_progress） | — |
| `progress_percent` | int 0-100 | 整体进度百分比 | — |

**队列状态合法值（小写）**：`pending` / `queued` / `running` / `processing` / `done` / `error` / `stop` / `cancelled`

## 4. workspace event log 与 SSE 事件名的映射

后端某些事件先写到 DB（`appendWorkspaceEvent($sid, $aid, $stage, $eventType, $message, $details)`）持久化，再通过 `AiSiteAgent::forwardObservedOperationEvents` 把 DB 中的 entry mirror 成 SSE。映射表在 `AiSiteAgentQueueObserverHelperService::mapOperationEventName()`：

| 后端 workspace event_type | SSE 事件名 |
|---|---|
| `start` / `operation_started` | `start` |
| `info` / `plan_saved` / `plan_generated` / `plan_refined` / `plan_rebuilt` | `info` |
| `warning` | `warning` |
| `progress` / `operation_progress` / `chunk` / `ai_raw_chunk` / `plan_chunk` / `ai_chunk` | `progress` |
| `shared_component_generated` | `shared_component_generated` |
| `page_generated` | `page_generated` |
| `task_completed` | `task_completed` |
| `task_failed` / `build_task_failed` | `task_failed` |
| `error` / `operation_failed` | `error` |

**新增 event_type 时必须同步**：mapping 表 + `isOperationEventRelevant` 白名单 + `buildObservedOperationEventPayload` payload case。三处不齐就会让事件被静默丢弃。

## 5. 前端读取契约（主字段 + 过渡 fallback）

前端从 payload 中读取关键状态时**永远优先读主字段**，fallback 仅在权威字段缺失时使用：

```js
// task summary
function extractTaskProgressSummary(source) {
  if (source.task_summary) return source.task_summary;      // 主字段
  if (source.task_progress) return source.task_progress;    // 过渡 fallback
  if (source.build_task_summary) return source.build_task_summary; // 过渡 fallback
  ...
}

// queue status
function readQueueStatusFromPayload(operation, payload) {
  var status = String(payload.queue_status || '').trim().toLowerCase(); // 主字段
  if (!status && payload.queue_info) status = payload.queue_info.status || ...; // 过渡
  ...
}
```

**未来稳定后**（normalizer 上线 1-2 个 release）可把 `EMITTED_DEPRECATED_ALIASES = false`，同时把前端 fallback 删除，只保留主字段读取。

## 6. 新增/重命名事件流程

1. 修 `AiSiteSsePayloadNormalizer::authoritativeEventNames()` 加入新事件
2. 修本文档 §2 加事件行
3. 在后端入口加 `$this->emitNormalizedSseEvent($sse, '<name>', $payload)`（或服务级 `$sse->sendEvent` 后 normalize）
4. 在前端 `script-runtime.phtml` 加 `source.addEventListener('<name>', ...)`
5. 跑 `AiSiteSseEventContractTest` 确保前后端对称
6. 若是 workspace log mirror 派生事件，还要同步改 §4 三处后端映射

## 7. 隔离 / 例外白名单

`AiSiteSseEventContractTest` 会维护一份"已知不对称事件"白名单：

- `ai_chunk`：后端发，前端不消费（visual edit 流中预留），属于"后端单边发送"
- `done`：所有 SSE 流自动以 done 收尾，前端总监听器在 `startOperationStream` 内部，不在常规 source.addEventListener 入口

白名单必须有注释解释为什么不对称，并标注是临时还是永久。
