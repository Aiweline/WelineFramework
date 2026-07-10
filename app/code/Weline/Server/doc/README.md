# WLS 文档导航

本目录记录 Weline Server（WLS）的现行架构、运行方式和历史设计。开发与排障优先阅读现行文档；带日期的修复报告和阶段方案只作为历史证据，不作为当前实现契约。

## 推荐阅读

1. [WLS 运行时架构：现状与目标](WLS架构图.md) — 总体组件、状态权威、已确认故障、目标架构和验收门槛。
2. [WLS 启动与关闭链路图](WLS启动与关闭链路图.md) — CLI、Master、Orchestrator 和 residual cleanup 的实际时序。
3. [IPC 控制通道架构](IPC控制通道架构.md) — REGISTER、READY、lease、heartbeat、route snapshot 和控制命令。
4. [Dispatcher 分流架构设计](Dispatcher分流架构设计.md) — 数据面转发、路由快照、健康隔离和维护兜底。
5. [WLS Session/Memory 共享服务架构](WLS_Session共享服务架构.md) — 跨 Worker/实例共享状态 sidecar。
6. [WLS 模式部署指南](WLS模式部署指南.md) — 启动参数、生产部署和运维入口。

## 按问题定位

| 问题 | 首选文档 |
|---|---|
| Windows 启动慢、Worker 批量拉起 | [WLS 运行时架构](WLS架构图.md)、[启动与关闭链路](WLS启动与关闭链路图.md) |
| Worker 掉线、整池重载、路由为空 | [WLS 运行时架构](WLS架构图.md)、[IPC 控制通道](IPC控制通道架构.md) |
| 请求转发、TLS、Worker 故障转移 | [Dispatcher 分流架构](Dispatcher分流架构设计.md) |
| 首页预热、常驻内存、请求长尾 | [WLS 运行时架构](WLS架构图.md) |
| Session/Memory 服务异常 | [共享服务架构](WLS_Session共享服务架构.md) |
| SSE/长连接 | [SSE 无阻塞检测方法](SSE无阻塞检测方法.md)、[WLS EventBuffer SSL Worker](WLS-EventBuffer-SSL-Worker.md) |
| Worker 扩缩容 | [Worker 动态扩缩容架构](WLS-Worker动态扩缩容架构设计.md)、[用户手册](WLS-Worker扩缩容用户手册.md) |
| 多实例隔离 | [WLS 实例隔离机制](WLS实例隔离机制.md) |
| 安全与规则 | [WLS 安全与规则配置推演](WLS安全与规则配置推演.md) |
| Gateway | [WLS Gateway 使用指南](WLS-Gateway使用指南.md) |

## 状态权威速查

- Master `ServiceRegistry`：进程生命周期、槽位、代际和 READY。
- Dispatcher 的版本化 `SET_ROUTE_TABLE` 快照：数据面路由。
- SharedState registry：Session/Memory sidecar；只能由认证后的写路径修正。
- `var/server/instances/*.json`：CLI endpoint 发现，不是运行时共识。
- PID/端口索引：可重建缓存，不是存活或身份的最终事实源。

## 文档维护规则

- 源码与文档冲突时，以源码为准，并在同一任务修正文档。
- 总体架构只维护在 `WLS架构图.md`，不要再创建并行总览。
- 专项文档只描述本领域，不复制总览中的整套架构。
- 日期型 `WLS-*-YYYY-MM-DD.md` 是历史快照；新代码不得直接照搬其中的旧类名、端口公式或状态模型。
- `AI-INDEX.md` 由脚本生成，不手工编辑。
- 新增可访问入口、配置或运行命令时，同步部署文档；变更启动、READY、路由或关闭时序时，同步链路图。

## 历史材料

以下类型仅用于审计和回归取证：

- `WLS-ISSUES-*`、`WLS-FIXES-*`、`WLS-FINAL-REPORT-*`
- `WLS-HA-*`、`WLS-MASTER-*`、`WLS-SUPERVISOR-*`
- `WLS-default-startup-*`、`WLS-DISPATCHER-*`
- `wls-panel-plan/` 下的阶段计划和验收证据

历史材料中的 `DispatcherCore`、旧控制端口公式、旧 add/remove-worker 消息、固定复活延迟或“常驻请求 Fiber 池”等描述，除非已被现行源码和总览再次确认，否则均不视为当前契约。
