# API · Weline_AiAcquisition

## 1. BinQuery Provider

Provider：`ai_acquisition`（后台会话 + ACL）。页面用 `Weline.Api.resource('ai_acquisition')`。

| Operation | 模式 | 说明 |
|---|---|---|
| `leadList` | read | 筛选：`keyword,continent,country,status,source_platform,min_score,website_id,page,page_size` |
| `leadGet` | read | `id` |
| `leadSave` | write | `payload`；新建/按 dedupe 合并 |
| `leadDelete` | write | `id` |
| `leadBulkStatus` | write | `ids[], status` |
| `leadBulkDelete` | write | `ids[]` |
| `leadStats` | read | 总数/状态分布/洲分布/qualified |
| `promptList` | read | 内置默认 + DB 档案 |
| `promptSave` | write | `payload{id,code,name,body,is_default}` |
| `promptDelete` | write | `id` |
| `modelList` | read | AI 模块可用文本模型与价格 |
| `modelQuote` | read | `model_code,input_tokens,output_tokens,iterations` |
| `runCreate` | write | `payload{model_code,prompt_profile_id,target_count,max_iterations,context}` → `ticket` |
| `runList` | read | `status,model_code,website_id,keyword,page,page_size` |
| `runStatus` | read | `id` |
| `runCancel` | write | `id` |
| `agentInfo` | read | 智能体注册信息与工具 |

## 2. 浏览器桥 REST（前端区，ticket 鉴权）

基址：`/ai_acquisition/frontend/bridge`

| 方法/路径 | 入参 | 返回 |
|---|---|---|
| `GET /context?ticket=` | ticket | run、提示词、模型、估算、网站 |
| `GET /stream?ticket=&last_event_id=` | ticket | `text/event-stream`；`event: bridge` / `done` |
| `POST /event` | `{ticket,type,payload}` | `{success:true}` |
| `POST /step` | `{ticket,messages,tools,params}` | `{assistant,tool_calls,usage,cost}` |
| `POST /lead` | `{ticket,lead}` | `{success,lead,created}` |
| `POST /complete` | `{ticket,status,stats}` | `{success}` |

错误统一 `{success:false,message}`；ticket 无效返回 4xx，不回显 ticket。

## 3. SSE 事件

```
event: bridge
id: 42
data: {"type":"run.cancel","payload":{}}

event: done
data: {"ok":true}
```

浏览器用 `Last-Event-ID` 重连；服务端 `pull()` 拉到即标记 delivered，`ack` 后 consumed。

## 4. 成本口径

- 模型价格：`ai_model.token_price_input` / `token_price_output`，单位 CNY / 1000 token。
- 单轮：`prompt_tokens * input_price / 1000 + completion_tokens * output_price / 1000`。
- 运行：累加各轮，`round(..., 6)`；`priced=false` 表示模型未配置价格（仍记录 token，成本为 0）。
- 估算：默认 8 轮、每轮 4000 输入 / 1200 输出，给 min/typical/max 三档。

## 5. 安全

- 后台 QueryProvider：`frontend=true, backend=true, auth=backend, backend_acl` 指向菜单 ACL。
- 浏览器桥：ticket 2h、单次运行、无后台 Cookie；不信任前端传入的任意后端 URL。
- 不采集：登录后页面、付费墙内容、验证码、非公开个人数据。


## 2026-10-23 补充：三种模型模式与本地 step

`runCreate` payload 新增：

```json
{
  "model_mode": "ai_module|local_computer|browser_local",
  "model_code": "仅 ai_module 必填",
  "local_endpoint": "http://127.0.0.1:11434",
  "local_model": "qwen2.5:3b",
  "browser_model": "chrome-builtin"
}
```

`GET /ai_acquisition/frontend/bridge/context` 返回 `model_mode`、`local_endpoint`、`local_model`、`browser_model`。  
本地/浏览器模式不调用服务端模型；扩展在本地推理后调用：

```json
POST /ai_acquisition/frontend/bridge/step
{
  "ticket": "...",
  "local_result": {
    "assistant": "...",
    "tool_calls": [],
    "usage": {"prompt_tokens": 0, "completion_tokens": 0}
  }
}
```

服务端只校验运行模式、记录 token（成本 0）并写 `step` 事件；不会接受本地模式调用 AI 模块模型。
