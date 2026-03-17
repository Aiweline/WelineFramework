---
name: sse-streaming
description: SSE（Server-Sent Events）流式响应。SSE组件=theme:sse-terminal 标签（控制台）；后端 SseWriter；POST 流式用 terminal.start(url, { method: 'POST', body })。SSE。
globs:
  - "**/Controller/**/*.php"
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
alwaysApply: false
---

# sse-streaming（极简版）

## 何时使用

- 实现或修改 **SSE**、**流式响应**、**Server-Sent Events**
- **SSE 组件**：指 **标签** `<w:theme:sse-terminal>`，即 **控制台**（Weline\Theme\Taglib\SseTerminal），用于在页面上展示 SSE 流式输出
- 使用 **EventSource**、**text/event-stream**
- 后端流式输出进度/块（如 AI 生成、长任务）

## 后端（PHP）

- 使用框架 `\Weline\Framework\Http\Sse\SseWriter`：
  - `$sse = new SseWriter();` → `$sse->start();` 再发事件，最后 `$sse->close();`
  - `$sse->sendEvent('事件名', $data)`：自动 JSON 编码，格式为 `event: 事件名\ndata: {...}\n\n`
  - `$sse->sendData($data)`：无事件名，仅 data
  - `$sse->sendComment('heartbeat')` / `sendHeartbeat()`：保活
- 流式 AI：用 `AiService::generateStream($prompt, function(string $chunk) use ($sse) { ... }, ...)`，在回调里 `$sse->sendEvent('chunk', ['content' => $chunk, ...]);`，并 `return $sse->isAlive();` 以便客户端断开时停止。
- 长任务前：`@set_time_limit(0);`、`@ignore_user_abort(true);`

## 前端（JS）

- **SSE 组件（控制台）**：`<w:theme:sse-terminal id="xxx" title="..." height="240px" events="start,progress,chunk,done,error"/>`。通过 `window.WelineSseTerminal['xxx']` 获取实例，`terminal.start(url)` 为 GET；**POST 流式**用 `terminal.start(url, { method: 'POST', body: formData })`，控制台会解析 event-stream 并显示 start/progress/chunk/done/error。`terminal.on('done', fn)` / `terminal.on('error', fn)` 接收事件。
- **POST 流式**（无控制台时）：不能用 EventSource（仅 GET）。用 `fetch` + `res.body.getReader()` + `TextDecoder`，按 `\n\n` 拆事件，解析 `event:` 与 `data:`。
- **GET 流式**：可用 `new EventSource(url)`，或控制台不传 options 即 GET。

## 事件约定（示例）

- `start` / `progress`：进度，payload 常含 `message`
- `chunk`：AI 或逐块数据，payload 可含 `content`、`total_length`
- `done`：成功，payload 常含 `data`
- `error`：失败，payload 含 `message`

## 禁止

- 在 SSE 接口里用 `return json_encode(...)` 或非流式一次性输出
- 前端用 EventSource 请求 POST 流式接口（必须用 fetch + body + getReader）
