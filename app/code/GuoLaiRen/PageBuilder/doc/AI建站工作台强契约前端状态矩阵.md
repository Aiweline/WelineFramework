# AI建站工作台强契约前端状态矩阵

## 输入字段

本次前端状态只消费 PageBuilder AI workbench 已确认的工作台快照和 SSE 字段，不新增后端流程。核心输入包括 `active_operation.operation/status/message/progress_percent/queue_id/retry_allowed/failure_mode/queue_waiting_for_scheduler/can_close_stream`，以及 `plan_queue_info`、`build_queue_info` 下的 `snapshot.status/job_status/queue_status/message/error/progress_percent/retry_allowed/failure_mode`。

发布阶段同时读取 `can_publish`、`workspace_status`、`publish_status`、`latest_build_failed`、`publish_blocked_by_latest_ai_failure`、`publish_blocked_reason`、`retryable_ai_failure_count`、`retryable_ai_failures`。这些字段只决定按钮启停、阻断提示和重试入口显示，不直接触发新的取消或重试接口。

## 状态矩阵

| Contract 状态 | 归一化 UI 状态 | 用户提示 | 按钮规则 |
| --- | --- | --- | --- |
| `done`、`success`、`complete`、`completed`、`finished` | success / done | 显示“已完成”“已确认”“可发布”或“已发布” | 解锁下一阶段；发布仍需 `can_publish=true` 且通过发布检查 |
| `error`、`failed`、`fail` | error / failed | 显示失败原因和重试提示 | 锁定同阶段主按钮；如有 retryable 字段则显示重试失败项 |
| `pending` | pending | 显示等待系统调度 | 主启动按钮保持锁定，允许观察或收起日志 |
| `queued` | queued | 显示已进入后台队列 | 主启动按钮保持锁定，发布按钮锁定 |
| `running`、`processing`、`in_progress` | running | 显示生成中或构建中 | 主启动按钮保持锁定，发布按钮锁定 |
| `cancelled`、`canceled`、`stop`、`stopped` | cancelled | 显示队列已取消，不再自动推进 | 不当作成功；如有 retryable 字段则显示重试失败项 |
| `stale`、`expired`、`outdated` | stale | 显示状态过期并要求以最新快照为准 | 不自动推进；等待快照刷新或重试入口 |
| `partial_retry_required` 或 `retry_allowed=true` | retryable | 显示需重试 | 显示现有重试失败项入口；发布按钮锁定 |
| `timeout`、`timed_out`、`connection_timeout` | timeout | 显示长时间未收到队列终态 | 切换为快照轮询兜底，发布按钮锁定 |
| `connection_lost`、`sse_interrupted`、`disconnected`、`interrupted` | connection_lost | 显示 SSE 连接中断并切换快照轮询 | 不标记业务失败；通过快照确认终态 |

## 按钮矩阵

单阶段 BuildPlan 使用计划队列状态禁用生成/确认按钮，只有终态成功并存在方案草稿时才开放确认。构建阶段在 `build_plan_confirmed=true` 后开放，若 `build_queue_info` 或 `active_operation` 处于 pending、queued、running、timeout、connection_lost，则保持构建和发布入口锁定。

发布按钮同时绑定四类合同字段：`can_publish`、运行中的 AI 队列、最新 AI 构建失败、retryable 失败项。任一条件不满足时，`pb-ai-start-publish` 写入 `data-pb-contract-*` 属性并显示 `pb-ai-publish-blocking-alert`，用于浏览器/E2E 直接断言。

## SSE 样例

```text
event: progress
data: {"operation":"build","queue_status":"running","progress_kind":"queue_info","queue_info":{"snapshot":{"status":"running"}}}

event: error
data: {"operation":"plan","status":"timeout","message":"长时间未收到队列终态"}
```

当前 `operation-sse` 断连且没有服务端错误 payload 时，前端不会把业务状态改成失败，而是渲染 `connection_lost` 并启动 workspace snapshot 轮询，等待真实终态。
