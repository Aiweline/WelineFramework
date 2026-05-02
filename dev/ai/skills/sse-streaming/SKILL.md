---
name: sse-streaming
description: SSE（Server-Sent Events）流式响应。SSE组件=theme:sse-terminal 标签（控制台）；后端 SseWriter；`terminal.start(url, { method: 'POST', body })` 只是参数序列化便捷写法，底层订阅仍是 EventSource GET。
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

- **SSE 组件（控制台）**：`<w:theme:sse-terminal id="xxx" title="..." height="240px" events="start,progress,chunk,done,error"/>`。通过 `window.WelineSseTerminal['xxx']` 获取实例，`terminal.start(url)` 为 GET。若写成 `terminal.start(url, { method: 'POST', body: formData })`，当前组件会先把 body 序列化进 query，再用 `EventSource(url)` 发起 **GET** 订阅；因此后端必须提供 GET 路由，并从 `getGet()/getPost()` 兼容取参。`terminal.on('done', fn)` / `terminal.on('error', fn)` 接收事件。
- **POST 流式**（无控制台时）：不能用 EventSource（仅 GET）。用 `fetch` + `res.body.getReader()` + `TextDecoder`，按 `\n\n` 拆事件，解析 `event:` 与 `data:`。如果必须用原生 EventSource/`theme:sse-terminal`，请把 SSE 路由设计成 GET。
- **GET 流式**：可用 `new EventSource(url)`，或控制台不传 options 即 GET。

## 事件约定（示例）

- `start` / `progress`：进度，payload 常含 `message`
- `chunk`：AI 或逐块数据，payload 可含 `content`、`total_length`
- `done`：成功，payload 常含 `data`
- `error`：失败，payload 含 `message`

## 禁止

- 在 SSE 接口里用 `return json_encode(...)` 或非流式一次性输出
- 把 `terminal.start(url, { method: 'POST', body })` 误当成真实 HTTP POST；它只是参数编码辅助，底层仍是 EventSource GET
- 前端用 EventSource 请求只提供 POST 路由的 SSE 接口（必须改成 GET 订阅，或改用 fetch + body + getReader）
- **在 SSE 长连接循环中使用 `\usleep()` 或 `\sleep()`** — 必须用 `SchedulerSystem::yieldDelay()` 替代

## SSE 长连接循环必须使用 SchedulerSystem

在 WLS 环境下，SSE 长连接必须使用协作式调度避免阻塞 Worker：

```php
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

// ❌ 禁止：会阻塞整个 Worker
while (\time() < $deadline && $sse->isAlive()) {
    $sse->sendEvent('log', $event);
    \usleep(2000000);  // 阻塞 2 秒！
}

// ✅ 正确：挂起当前 Fiber，Worker 可处理其他请求
while (\time() < $deadline && $sse->isAlive()) {
    $sse->sendEvent('log', $event);
    SchedulerSystem::yieldDelay(2000);  // 让出控制权 2 毫秒
}
```

## SSE 控制器必须正确关闭流

SSE 控制器**必须**在流结束时调用 `$sse->complete()` 或 `$sse->close()`：

```php
// ✅ 正确：调用 complete() 关闭 SSE 流
public function getStreamSse(): void
{
    $sse = new SseWriter();
    $sse->start();
    // ... 处理逻辑 ...
    $sse->complete(['success' => true]);  // 发送完成事件并关闭连接
}

// ❌ 错误：不调用 complete() 或 close()，导致 WLS 误判响应体
public function getStreamSse(): void
{
    $sse = new SseWriter();
    $sse->start();
    // ... 处理逻辑 ...
    // 缺少 $sse->complete() 或 $sse->close()
    // WLS 可能误以为这是一个普通 HTTP 请求，响应体被忽略
}
```

**注意**：`complete()` 会发送 `done` 事件并关闭连接，客户端应该监听 `done` 事件来确认流结束。

## SSE 控制器禁止 return 响应体

SSE 控制器的返回值会被 WLS 作为普通 HTTP 响应处理。如果控制器返回了非空字符串，WLS 会尝试将其作为 HTTP 响应发送，导致协议混乱。

```php
// ❌ 错误：SSE 控制器返回非空值
public function getStreamSse(): void
{
    $sse = new SseWriter();
    $sse->start();
    // ...
    return json_encode(['result' => 'done']);  // 会导致协议混乱！
}

// ✅ 正确：SSE 控制器不返回值
public function getStreamSse(): void
{
    $sse = new SseWriter();
    $sse->start();
    // ...
    $sse->complete(['success' => true]);  // 显式关闭流
    // 不 return 任何内容
}
```
