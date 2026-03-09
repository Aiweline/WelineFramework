# Weline_Server 验证测试与问题即修复 - 任务进度

> 计划：[plan.md](./plan.md)
> 最后更新：2026-03-09

## 状态说明

- [ ] 未开始
- [-] 进行中
- [x] 已完成

## 阶段任务清单

### 阶段 0：门禁检查

- [x] 执行 `setup:upgrade` 并确认升级成功（已修复 plugin.xml 单拦截器解析 + Proxy union type）
- [x] 执行 `server:status` 并确认核心进程运行
- [x] 验证 HTTP 主入口连通性（使用 `--no-ssl` 实例 8443 可验证；HTTPS 9981 在 Windows 下仍 TLS reset）

### 阶段 0.5：阻塞修复（问题即修复）

- [x] 修复 `PluginXmlReader` 单拦截器解析（`{_attribute,_value}` 标准化为 `[one]`）
- [x] 修复 `Proxy/Generator::extractParameterType` 对 `ReflectionUnionType` 支持（`string|array` 等）
- [ ] TLS reset：Windows 环境限制，可改用 `--no-ssl` HTTP 实例验证功能

### 阶段 1：控制面验证

- [x] 验证 `start` / `stop`（verify_http：stop 可行，start 成功）
- [x] 验证 `restart` / `reload`（reload 已发送，restart 正常）
- [x] 验证 `maintenance-enable` / `maintenance-disable`（已修复 parseInstanceName，default 实例 status 正常；verify_http 仍偶发超时）

### 阶段 2：状态 DTO 验证

- [x] 验证 orchestrator 运行快照（maintenance status 已输出 maintenance_mode/rolling_restart/services）
- [ ] 验证 worker health detail 字段（需后台登录调用 API）

### 阶段 3：Telemetry 与 Host 分组验证

- [x] 造流量并验证 global 聚合（`verify_http` 实例桶数据持续增长，`request_count/error_count` 可见）
- [x] 造多 Host 请求并验证 host 分组聚合（已写入 `alpha.local/beta.local/gamma.local` 分组）

### 阶段 4：周期落库验证

- [x] 验证 flush 后历史数据可查询（等待窗口后触发 `server:maintenance status`，新桶可查）
- [x] 验证幂等 upsert 与失败重试（新增 `InMemoryMetricsAggregatorTest::testFlushRetryQueueCanRecover` 覆盖失败→重试恢复）

### 阶段 5：压测链路验证

- [-] 验证压测触发接口（CLI `server:benchmark -p 10001 -c 30 -n 1200 --path /_wls/health` 成功，QPS≈2560）
- [-] 验证列表/详情查询与数据落库（服务层单测已覆盖 `list/detail/runAndStore`，后台 API/UI 闭环待登录态）

### 阶段 6：UI 交互验证

- [ ] 管理页控制按钮链路验证
- [ ] 监控页流量与压测展示验证
- [ ] 提示组件规范验证（BackendConfirm/AdminToast）

## 修复任务（执行中动态追加）

- [ ] 若发现接口失败：直接修复并记录修复点与回归结果
- [ ] 若发现状态不一致：直接修复并补充最小回归
- [ ] 若发现 UI 交互问题：直接修复并验证提示文案

## 2026-03-07 最新验证记录（增量）

- [x] 修复 `server:status` 假在线：`ServiceInfo::isRunning()` 改为 PID 优先校验，`guessState()` 改为仅无 PID 时才端口回退
- [x] 修复滚动重启后维护模式残留：`finishRollingRestart()` 调整为先清 rolling 标志再禁用 maintenance
- [x] 修复滚动重启/重载“假成功”：补充 `READY` 超时失败判定，失败即返回错误而非继续报完成
- [x] 修复 `server:maintenance status` 统计结构解析错误（services 快照按 role 分组）
- [x] 修复 `cache:clear` 仅通知 default 的缺陷：IPC 重载改为广播所有运行实例
- [x] 稳定性回归：`start/rolling/ipc-status/cache-clear/stop` 闭环通过（verify_http）
- [x] 修复 `http:req` 并发模式下 `-H/--header` 单字符串参数触发 `TypeError`（已归一化为数组）
- [x] 回归 `http:req /_wls/health -P=10001 --http -H='Host: alpha.local' -C -t=5`：并发请求可正常执行
- [x] 遥测链路结论更新：写入实例以 `verify_http` 为主；此前按 `default` 查询导致误判为“未入库”
- [x] 新增单测 `Test/Unit/Telemetry/InMemoryMetricsAggregatorTest.php`：覆盖 host 聚合与失败重试队列恢复
- [x] 新增单测 `Test/Unit/Benchmark/ServerBenchmarkServiceTest.php`：覆盖 benchmark `list/detail/runAndStore` 服务链路
- [ ] 后台登录态缺失：`http:req -b` 访问 `backend/server/server-monitor/*` 返回“未找到有效后台登录 Session”

## 2026-03-07 生命周期适配计划执行同步（增量）

> 对齐任务：`wls_lifecycle_deep_plan`（不改原计划文件，仅同步执行态）

- [x] `wls-subprocess-unified-control`
  - 子进程能力契约下沉到 Provider：`requiresStartupReadyBarrier/supportsDrain/supportsShutdown/supportsReload/isCriticalRole`
  - Orchestrator 按能力而非角色硬编码执行 DRAIN/SHUTDOWN/RELOAD
  - 服务元数据补充 `control_capabilities`，用于统一控制面展示/决策
- [x] `wls-reset-gap-close`
  - 补齐 `ThemeData::resetRequestState()`
  - 补齐 `PreviewTokenService::resetRequestState()`
  - 补齐 `Session::resetRequestState()`（flush 队列请求级清理）
  - 在 `StateManager::registerFrameworkResets()` 注册上述重置回调
- [x] `wls-session-entry-unify`
  - 运行态模板入口迁移到 `SessionFactory`（backend/frontend header）
  - 迁移清单落地：`session-entry-migration-checklist.md`
- [x] `wls-widget-event-hardening`
  - `Widget` 渲染链路统一 `catch (\Throwable)`，并通过 `finally` 做递归状态回卷
  - `EventsManager::resetRequestState()` 增加模块状态缓存边界清理
  - `StateManager` 新增 `widget_taglib_cache` 回调，确保 Widget 缓存仅请求内有效
- [-] `wls-lifecycle-regression`
  - 已完成：`frontend` 200、`backend` 302、会话 Cookie 连续请求稳定（未重复重置）
  - 待补：登录态下的 DataTable/事件回写/主题切换多用户并发回归
- [x] `wls-plan-task-sync`
  - 本节为执行态同步结果，后续增量回归将继续在本文件追加

## 2026-03-09 请求时延优化（增量）

- [x] `wls-session-deferred-persist`
  - 已完成：`WLS Session` 改为请求末尾统一 `save + writeClose`，避免单请求多次 `set/delete` 触发多次 RPC
- [x] `wls-cache-native-batch-ops`
  - 已完成：`exists/touch/mget/mset` 改为协议原生命令，去掉 `get()+set()` 与逐 key RPC
- [x] `wls-runtime-log-switches`
  - 已完成：增加 `server.performance` 配置入口，控制性能头、慢请求日志、请求/错误日志
- [-] `wls-latency-regression`
  - 已验证：`SessionTest`、`SessionStoreTest` 定向单测通过
  - 已验证：HTTP 级 `/_wls/health`、首页 `/`、后台未登录 `302` 最小回归通过
  - 观测：`/_wls/health` 基本在 `3-4ms`，首页压测均值约 `28ms`，后台未登录 `302` 热身后约 `3-5ms`
  - 备注：当前 `env.php` 中 `session.wls_managed=false`，本地前端实例未走 WLS Session Server，Session 延迟写优化需切回托管模式后才能在 HTTP 链路中体现
- [ ] `wls-linux-direct-followup`
  - 备注：直连模式后置；需先设计 Dispatcher 能力下放 Worker 的方案
