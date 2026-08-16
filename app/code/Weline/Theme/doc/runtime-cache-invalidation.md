# Theme 运行时缓存失效契约

`ThemeRuntimeCacheCleaner` 负责主题切换、布局发布和后台模式变更后的定向失效。
请求链路不得因为其他 WLS 实例、失联 IPC 或持久化实例清单而阻塞数秒。

## 失效范围

- FPM/CLI 没有 `WLS_INSTANCE` / `WLS_INSTANCE_NAME` 时，先清理当前进程和本地缓存，再由控制面在一个总 deadline 内并发通知正在运行的 WLS 实例。
- WLS Worker 存在当前实例名时，只清理该实例的 Shared State，并向该实例发送 cache epoch。
- 全实例广播只能读取持久化 endpoint 并并发尝试；禁止在请求内对每个历史实例串行执行端口/进程探测。
- Router 持久池通过 `Framework\Cache\CacheManager::pool('router')->clear()` 失效，不得实例化不存在的 RouterCache Factory。

## Chrome partial 输出缓存

- 后台/前台 chrome partial 的渲染结果只保存在当前 Worker 的有界 LRU 中；请求热路径不得为每个 head、topbar、sidebar 等 partial 调用 theme_runtime SharedState。
- 是否缓存以模板 @meta.cache 为权威；显式 mode=off 不得被类型默认值重新启用。
- cache key 必须绑定模板文件状态、area/type/option、主题与页面上下文；需要用户或角色隔离的 partial 在身份无法解析时直接绕过缓存，禁止落入共享的 unknown bucket。
- ProcessCacheResetter 的 hard reset 清空本进程 chrome LRU；Worker 启动预热只填充安全的 guest/公共上下文，登录用户/角色上下文在首次真实请求时填充。

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

2026-08-16 的后台性能修复已在独立 WLS 实例验证：Worker/Watchdog 3/3 READY，Worker 被精确终止后约 3 秒恢复；默认实例重启后的公开请求 20 次平均 TTFB 63ms、P95 148ms。登录态 Dashboard 的最终浏览器数字仍应以实际用户会话刷新后的 WelinePanel 为准。
