# Theme 运行时缓存失效契约

`ThemeRuntimeCacheCleaner` 负责主题切换、布局发布和后台模式变更后的定向失效。
请求链路不得因为其他 WLS 实例、失联 IPC 或持久化实例清单而阻塞数秒。

## 失效范围

- FPM/CLI 没有 `WLS_INSTANCE` / `WLS_INSTANCE_NAME` 时，先清理当前进程和本地缓存，再由控制面在一个总 deadline 内并发通知正在运行的 WLS 实例。
- WLS Worker 存在当前实例名时，只清理该实例的 Shared State，并向该实例发送 cache epoch。
- 全实例广播只能读取持久化 endpoint 并并发尝试；禁止在请求内对每个历史实例串行执行端口/进程探测。
- Router 持久池通过 `Framework\Cache\CacheManager::pool('router')->clear()` 失效，不得实例化不存在的 RouterCache Factory。

## Deadline

- WLS 请求 Fiber 内的 cache-clear IPC 等待上限为 `50ms`。
- FPM/Web 请求总等待上限为 `250ms`。
- CLI 控制面默认上限为 `2s`，用于可观测的显式命令。
- IPC 失败不得回滚已完成的本地失效；新 cache epoch 由后续控制面重试收敛。

## 性能回归

2026-07-12 的无 WLS 实例基线暴露了错误的全实例广播：后台模式同步超过 `40s`。
改为 endpoint 快读、总 deadline 并发与本地定向失效后，217 个历史实例文件的全局广播筛选为 `40.627ms`，
后台模式同步为 `57.536ms`，`ThemeRuntimeCacheCleaner` 本地阶段为 `59.542ms`，且所有步骤成功。

上线前还必须在独立 WLS 实例内验证：广播命中正确 instance，请求时延不超过预算，所有 Worker 的 FPC/Static L1 按 epoch 收敛。
