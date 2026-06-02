# PageBuilder 块微调智能体 API

## 目标

本文档定义第三方系统调用 PageBuilder 智能体对已生成页面 block 做局部微调的 API 合同。该接口适合“保留当前页面与区块结构，只按用户指令调整某个 block”的场景，例如替换文案、调整 CTA、优化首屏结构、补充卖点或修正视觉细节。

当前稳定入口是后台 AI 建站工作台控制器：

`POST /pagebuilder/backend/ai-site-agent/post-start-patch-block`

该入口不会直接同步返回微调后的 block，而是创建 `block_partial_patch` 队列任务，并返回 `execution_token`、`queue_id`、`stream_url`。调用方应通过 SSE 或 `post-workspace-state` 读取任务状态与最新工作区结果。

## 调用前提

- 调用方必须拥有后台登录态或等价的服务端集成凭据，并具备 `GuoLaiRen_PageBuilder::ai_site_agent_api` 权限。
- `public_id` 必须指向调用方有权访问的 AI 建站工作区会话。
- 工作区需要已经进入可视编辑阶段，并且目标 `page_type` 在当前工作区页面类型内。
- 目标 block 必须已经存在于当前工作区的实时布局数据中。系统优先从工作区虚拟页面读取，也支持共享头尾组件与虚拟主题组件。
- 同一工作区同一时间存在运行中的 AI 构建类任务时，新任务可能返回冲突状态，调用方应先等待或复用返回的活跃任务信息。

## 典型流程

1. 调用 `post-workspace-state` 获取当前工作区页面、区块、`active_operation` 和可用 `page_type`。
2. 从工作区状态或预览 DOM 中确定目标 `page_type`、`block_id`，必要时传入 `component_code` 作为兜底。
3. 调用 `post-start-patch-block` 启动块微调任务。
4. 使用返回的 `stream_url` 建立 SSE 连接，或定期调用 `post-workspace-state` 轮询状态。
5. 当任务状态为 `done`，从工作区状态的 `virtual_pages_by_type`、`page_type_layouts`、`block_patch_history` 或预览页面读取更新后的 block。
6. 当任务状态为 `error/failed/fail`，展示 `message`，并可调用 `post-retry-ai-operation` 重试同一失败操作。

## 启动块微调

### 请求

`POST /pagebuilder/backend/ai-site-agent/post-start-patch-block`

请求体支持表单或 JSON，推荐 JSON。

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `public_id` | string | 是 | AI 建站工作区会话公开 ID。 |
| `page_type` | string | 是 | 页面类型，例如 `home`、`about`、`contact`，必须存在于当前工作区。 |
| `block_id` | string | 是 | 目标 block ID。若为空，后端会尝试使用 `component_code`。 |
| `component_code` | string | 否 | 组件编码或 section code，可作为 `block_id` 的兜底输入。 |
| `instruction` | string | 是 | 给智能体的微调指令。应描述具体变更，不要要求重建整页。 |
| `component_label` | string | 否 | 用于事件日志展示的组件名称。 |

### 请求示例

```bash
curl -X POST "https://example.com/backend/pagebuilder/backend/ai-site-agent/post-start-patch-block" \
  -H "Content-Type: application/json" \
  -H "Cookie: admin_session=..." \
  -d '{
    "public_id": "aisite_20260514_xxxxx",
    "page_type": "home",
    "block_id": "home-page-hero-banner",
    "component_code": "content/home-page-hero-banner",
    "component_label": "首页首屏",
    "instruction": "把首屏标题改得更强调企业级交付能力，保留原有按钮和图片结构。"
  }'
```

### 成功响应

```json
{
  "success": true,
  "message": "操作已启动",
  "execution_token": "2f7e4d...",
  "queue_id": 12345,
  "operation": "block_partial_patch",
  "stream_url": "https://example.com/backend/pagebuilder/backend/ai-site-agent/operation-sse?public_id=aisite_20260514_xxxxx&execution_token=2f7e4d...",
  "data": {
    "stage": "visual_edit",
    "active_operation": {
      "operation": "block_partial_patch",
      "status": "queued",
      "queue_id": 12345,
      "page_type": "home",
      "progress_percent": 0
    }
  },
  "queue_wait": {
    "queue_id": 12345,
    "queue_waiting_for_scheduler": true,
    "can_close_stream": true,
    "continue_other_operations": true
  }
}
```

调用方只应把该响应视为“任务已排队”。最终是否修改成功，以 SSE `block_partial_patch_applied` 或后续快照中的终态为准。

## 监听任务进度

### SSE

使用启动响应中的 `stream_url` 建立 `EventSource` 连接。该 URL 实际指向：

`GET /pagebuilder/backend/ai-site-agent/operation-sse?public_id={public_id}&execution_token={execution_token}`

常见事件：

| 事件 | 说明 |
| --- | --- |
| `progress` / `info` | 队列认领、读取当前 block、生成 patch、应用结果等进度。 |
| `ai_chunk` | 智能体流式输出片段，主要用于调试或展示生成过程。 |
| `block_partial_patch_applied` | 微调已通过校验并写回工作区 scope。 |
| `block_partial_patch_failed` | 微调失败，包含 `message`。 |
| `done` | 当前 SSE 流结束。需结合 `success`、`operation`、`state.active_operation` 判断最终状态。 |
| `error` | 参数、权限、会话或运行异常。 |

`block_partial_patch_applied` 示例：

```json
{
  "operation": "block_partial_patch",
  "page_type": "home",
  "block_id": "home-page-hero-banner",
  "component_code": "home-page-hero-banner",
  "change_summary": "Updated hero title and intro copy.",
  "changed_fields": ["html"],
  "state": {
    "stage": "visual_edit",
    "active_operation": {
      "operation": "block_partial_patch",
      "status": "done",
      "progress_percent": 100
    }
  }
}
```

## 读取工作区状态

### 请求

`POST /pagebuilder/backend/ai-site-agent/post-workspace-state`

```json
{
  "public_id": "aisite_20260514_xxxxx"
}
```

### 用途

- 启动微调前：获取当前 `page_type`、目标 block 列表、`active_operation`。
- SSE 断开后：恢复当前任务状态。
- 微调完成后：读取最新 `virtual_pages_by_type`、`page_type_layouts`、`block_patch_history`、预览页面信息。

调用方判断任务完成的核心字段是：

| 字段 | 说明 |
| --- | --- |
| `data.active_operation.operation` | 当前或最近 AI 操作，块微调为 `block_partial_patch`。 |
| `data.active_operation.status` | `queued/running/done/error/failed/fail/cancelled` 等状态。 |
| `data.active_operation.queue_id` | 对应队列 ID。 |
| `data.active_operation.progress_percent` | 进度百分比。 |
| `data.build_queue_info` | 队列快照、进程和结果摘要，存在时可用于排查。 |
| `data.scope.block_patch_history` | 最近块微调历史，包含 before/after 摘要和变更元数据。 |

## 失败重试

### 请求

`POST /pagebuilder/backend/ai-site-agent/post-retry-ai-operation`

```json
{
  "public_id": "aisite_20260514_xxxxx",
  "operation": "block_partial_patch",
  "queue_id": 12345
}
```

重试要求失败记录仍能解析出 `page_type`、`block_id` 和 `instruction`。若失败记录缺少这些字段，接口会返回 `RETRY_OPERATION_INCOMPLETE`。

## 错误码

| code | 触发场景 | 调用方处理 |
| --- | --- | --- |
| `INVALID_PARAMS` | 缺少 `public_id/page_type/block_id/instruction`，或 `page_type` 不在当前工作区。 | 修正请求参数后重试。 |
| `SESSION_NOT_FOUND` | 会话不存在、无权限或登录态无效。 | 重新鉴权或确认 `public_id`。 |
| `BLOCK_NOT_FOUND` | 当前工作区找不到目标 block。 | 先调用快照刷新 block 列表，再用真实 `block_id` 调用。 |
| `AI_SITE_OPERATION_BUSY` | 已有构建类 AI 操作运行中。 | 等待运行任务结束，或按返回的 `active_operation.stream_url` 接管监听。 |
| `BLOCK_VALIDATION_FAILED` | 智能体返回的替换 block 不符合结构要求。 | 展示错误信息，可重试或收紧 instruction。 |
| `BLOCK_RENDER_FAILED` | 替换 block 无法渲染。 | 展示错误信息，可重试或要求保留模板结构。 |
| `RETRY_OPERATION_NOT_FOUND` | 没有可重试的失败操作。 | 回到启动接口重新发起。 |
| `RETRY_OPERATION_INCOMPLETE` | 失败记录缺少重试所需参数。 | 回到启动接口重新发起，并传完整参数。 |

## 智能体输出约束

块微调智能体内部会读取当前 block，并要求 AI 返回可替换的完整 block。有效替换必须满足：

- `block_id` 必须与当前 block 一致。
- `type` 必须存在。
- `config` 必须是对象。
- `html` 必须是非空字符串。
- `field_schema` 必须是对象且结构合法。
- 不能改变其他 block 数量或顺序。
- 成功结果只写回工作区 scope；发布流程再把虚拟主题或虚拟页面内容同步到最终页面。

调用方的 `instruction` 应聚焦局部目标，例如：

- “把这个区块的标题改为更适合 B2B 客户，不要改变按钮数量。”
- “保留当前布局，只把背景图说明换成新能源行业场景。”
- “优化 CTA 文案和说明文字，不要新增区块。”

不建议在块微调接口中要求：

- 重建整站或整页。
- 批量调整多个 block。
- 删除目标 block。
- 修改导航、页脚等共享组件以外的全局结构。

## 第三方集成建议

- 把 `public_id` 作为第三方任务和 PageBuilder 工作区的主关联键。
- 启动任务后保存 `queue_id`、`execution_token`、`stream_url`，便于断线恢复和客服排查。
- 前端系统优先使用 SSE；服务端系统可使用 `post-workspace-state` 做低频轮询。
- 轮询间隔建议 3 至 5 秒，队列等待阶段可放宽到 10 秒。
- 展示结果时以快照中的最新 scope 为准，不要只依赖启动接口返回的 `data`。
- 任务失败后先展示 `message` 和 `queue_id`，再决定调用重试接口或重新启动微调。

## 与相关接口的区别

| 接口 | 操作 | 适用场景 |
| --- | --- | --- |
| `post-start-patch-block` | `block_partial_patch` | 对当前 block 做局部微调，保留 block 边界。 |
| `post-start-refine-component` | `block_regenerate` | 根据指令重生成某个组件或 section，变更范围更大。 |
| `post-start-regenerate-page` | `regenerate_page` | 重生成整个页面。 |
| `post-update-block-config` | config update | 直接保存人工配置，不调用智能体。 |
| `post-workspace-state` | state | 读取工作区状态，不触发 AI。 |
| `post-retry-ai-operation` | retry | 重试最近失败的 AI 操作。 |
