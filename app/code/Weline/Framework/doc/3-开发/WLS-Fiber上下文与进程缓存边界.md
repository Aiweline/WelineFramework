# WLS：Fiber 上下文 vs 进程/共享缓存（硬边界）

## 定案

| 层级 | 放什么 | 不放什么 |
|------|--------|----------|
| **Fiber / 请求上下文** | `Request` / `Response`、RequestContext、真正请求私有状态（`RequestLocalInterface`） | 词典/HotCache/FPC/媒体 URL 等胖缓存 |
| **进程袋** | Worker 内 L1（有上界）、`ProcessSharedInterface` 服务实例 | 按 `request_id` 开永久键 |
| **Memory Service** | 跨 Worker 权威/大块（Phrase Shared、FPC shared 等） | 请求围栏指纹 |

多 Fiber 并发时：**每个 Fiber 只有自己的上下文**；可读缓存一律进程袋或 Memory Service，禁止「每 Fiber 复制一份胖单例 + 私有袋」。

Fiber **结束/回收**（Worker 仍可能握着 terminated Fiber 引用）必须显式释放：
- `ObjectManager::clearRequestScopeForFiber`（含 Template / FiberOutputBuffer 的 Fiber WeakMap）
- `ObjectManager::sweepTerminatedFiberScopes`（reset 扫尾）
禁止只依赖 WeakMap+GC。

## ObjectManager

- `ProcessSharedInterface` → 始终写入/读取进程 `$instances`（Fiber 内也如此）。
- 未标记类 → 仍 Fiber 本地（兼容；避免把带请求可变字段的服务误共享）。
- `RequestLocalInterface` → 明确请求上下文（Http Request/Response 已实现）。

新增可跨 Fiber 共享的缓存协调器时：**必须**实现 `ProcessSharedInterface`，且请求维状态只进 `RequestContext`。

## 验收信号（MemDiag）

- `om_fiber_bucket_count`：约等于并发中的请求 Fiber 数（小）。
- `om_fiber_instances`：每桶仅上下文类，不应再含 CacheManager / HotCache / FPC coordinator 等进程共享服务。
- `om_main_instances`：进程共享服务计数随暖机上升、不随并发线性翻倍。
