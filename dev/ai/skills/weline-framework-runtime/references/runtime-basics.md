# WLS 运行时基础

## 进程管理

- 结束进程用 `Processer::destroy()`
- Worker 退出时：destroy → 关连接 → 清 PID
- 禁止直接 kill 端口（会导致 PID 累积）

## WLS 热重载

- 代码变更后执行 `php bin/w server:reload` 即可
- 仅当 Master/Dispatcher/启动参数变更时才需要 `server:restart -r`

## 停止等待

- 全量停止 `stopAll` 阶段 2「等待排水」默认 **10s**（原 2s 易误判超时）
- 可在配置中设置 `wls.orchestrator.stop_all_drain_wait_sec`（1～300）

## StateManager

- 请求级 static 属性必须注册到 StateManager 以便在请求间重置
- 代码变更后必须 `server:reload` 确保状态重置

## WLS 长连接与阻塞函数

### 禁止直接使用的阻塞函数

| 禁止 | 必须使用 | 说明 |
|-----|---------|------|
| `\sleep()` | `SchedulerSystem::sleep()` | 秒级等待 |
| `\usleep()` | `SchedulerSystem::yieldDelay()` | 微秒级等待 |
| `\die()` / `\exit()` | `throw new \RuntimeException()` | 终止执行 |
| 裸 `exit` | 同上 | 同上 |

### 原因

在 WLS 环境下，一个 Worker 进程通过 Fiber 机制同时处理多个请求。直接调用 `\usleep()` 会阻塞整个 Worker 进程，导致所有其他 Fiber（即其他请求）无法执行。

### SchedulerSystem 方法

```php
use Weline\Framework\Runtime\SchedulerSystem;

// 替代 usleep(2000000) - 等待 2 秒
SchedulerSystem::yieldDelay(2000);  // 参数：毫秒

// 替代 sleep(5) - 等待 5 秒
SchedulerSystem::sleep(5);  // 参数：秒

// 让出控制权（不等待，立即切换到其他 Fiber）
SchedulerSystem::yield();
```

### SSE 长连接标准模板

```php
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

public function getStreamSse(): void
{
    $sse = new SseWriter();
    $sse->start();

    // ... 初始化代码 ...

    $deadline = \time() + 900;  // 15 分钟超时
    while (\time() < $deadline && $sse->isAlive()) {
        // 处理事件
        $newEvents = $this->service->listEventsAfterId($sessionId, $lastEventId, 80);
        foreach ($newEvents as $event) {
            $sse->sendEvent('log', $event);
        }

        // 发送心跳（仅在超过间隔时才发送）
        $sse->maybeHeartbeat();

        // ❌ 禁止：\usleep(2000000);
        // ✅ 正确：挂起当前 Fiber，Worker 可处理其他请求
        SchedulerSystem::yieldDelay(2000);
    }

    $sse->complete();
}
```
