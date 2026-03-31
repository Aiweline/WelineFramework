---
name: runtime-and-process
description: 进程与 WLS。Processer::destroy 结束进程、Worker 优雅退出；server:start/stop/reload/restart、热重载。代码变更默认 reload。
globs:
  - "**/Process/**/*.php"
  - "**/Processer.php"
  - "**/Worker*.php"
alwaysApply: false
---

# runtime-and-process（极简版·进程+WLS）

## 何时使用

- 进程、Worker、fork、daemon、Processer；WLS、server:start、reload、restart、热重载

## 1) 进程管理

- 结束用 Processer::destroy()；Worker 退出时 destroy、关连接、清 PID；禁止直接 kill 端口导致 pid 累积

## 2) WLS

- 代码变更后 `php bin/w server:reload` 即可；仅 Master/Dispatcher/启动参数变更时 `server:restart -r`
- 全量停止 `stopAll` 阶段 2「等待排水」默认 **10s**（原 2s 易误判超时）；可在配置中设置 `wls.orchestrator.stop_all_drain_wait_sec`（1～300）

## 3) WLS 长连接与阻塞函数

### 核心原则

在 WLS 环境下，**禁止直接使用以下阻塞函数**，会阻塞整个 Worker 进程：

| 禁止 | 必须使用 |
|-----|---------|
| `\sleep()` | `SchedulerSystem::sleep()` |
| `\usleep()` | `SchedulerSystem::yieldDelay()` |
| `\die()` / `\exit()` | `throw new \RuntimeException()` |

### 原因

```
Worker 进程模型：
┌─────────────────────────────────────────┐
│ Worker 进程                              │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐ │
│  │Fiber 1  │  │Fiber 2  │  │Fiber 3  │ │
│  │(SSE请求)│  │(普通HTTP)│  │(SSE请求)│ │
│  └────┬────┘  └────┬────┘  └────┬────┘ │
│       │              │              │    │
│  \usleep() 会阻塞整个进程，导致其他    │
│  Fiber 无法执行                          │
└─────────────────────────────────────────┘

正确做法：使用 SchedulerSystem 让出控制权
┌─────────────────────────────────────────┐
│ Worker 进程                              │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐ │
│  │Fiber 1  │  │Fiber 2  │  │Fiber 3  │ │
│  │(SSE请求)│  │(普通HTTP)│  │(SSE请求)│ │
│  │yield()  │  │ 执行✓   │  │yield()  │ │
│  └────┬────┘  └────┬────┘  └────┬────┘ │
│       │              │              │    │
│  SchedulerSystem 协调 Fiber 执行顺序      │
└─────────────────────────────────────────┘
```

### SSE 长连接标准写法

```php
use Weline\Framework\Runtime\SchedulerSystem;

$sse->sendEvent('start', ['message' => '开始处理']);

$deadline = \time() + 900;
while (\time() < $deadline && $sse->isAlive()) {
    $events = $this->service->listEventsAfterId($sessionId, $lastEventId);
    foreach ($events as $event) {
        $sse->sendEvent('log', $event);
    }
    $sse->maybeHeartbeat();
    // ❌ 禁止：\usleep(2000000);
    // ✅ 正确：
    SchedulerSystem::yieldDelay(2000);
}

$sse->complete();
```

## 最小示例

```bash
php bin/w server:reload
php bin/w server:restart -r
php bin/w server:start
```

## 禁止

- 笼统说「重启」不区分 reload/restart；业务代码变更让用户 restart（应 reload）；直接 kill 端口
- 在 WLS 上下文中使用 `\usleep()`、`\sleep()`、`\die()`、`\exit()` 等阻塞函数
