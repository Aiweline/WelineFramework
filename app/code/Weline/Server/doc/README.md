# WLS 文档导航

本目录记录 Weline Server（WLS）的现行架构、运行方式和历史设计。开发与排障优先阅读现行文档；带日期的修复报告和阶段方案只作为历史证据，不作为当前实现契约。

当前完成度、真实运行证据、未实现高级能力和外部环境门禁统一见 [WLS 当前能力与验收状态](WLS当前能力与验收状态.md)。其它文档出现冲突时，以该页的当前状态为准；历史 checkpoint 仍保留为历史事实。

WLS 2.0 启动统一使用 `--edge=auto|gateway|wls`。项目发布合同要求最终发行物必须在
固定平台路径携带已签名的完整 Gateway/Nginx 包；只有完成发布组装后的项目才满足这一
前置，当前源码树或仅上传 overlay artifact 不等于项目已经携带。`auto` 优先加入已受信的 `wls-edge/2` 宿主网关；若网关
尚不存在、80/443 可安全取得且发行包、平台权限和守护安装条件均满足，首个项目会在
宿主级互斥锁内建立独立网关并注册自身。包被验证并复制到宿主 A/B 槽后，网关不再依赖
引导项目的生命周期。未知 owner 占用公共端口、无权限、缺包、坏签名或建立失败时，
`auto` 以稳定的 20000–29999 loopback 高端口启动纯 WLS TLS，并明确报告这不是 80/443
的透明替代。`gateway` 必须加入或完成同一首次建立，否则非零退出；`wls` 完全绕过
网关。`--no-nginx` 是 `--edge=wls` 的兼容别名。首次自动建立不等于自动升级、修复或
接管：既有网关的 upgrade/repair/rebootstrap 仍必须由显式管理命令执行。
公开 CLI 会拒绝 `--edge=legacy` 及其他未知值；`legacy` 只用于首次识别没有 edge mode
字段的已保存 WLS 1.x 项目配置。识别后运行时可以把该兼容状态内部持久化为
`edge_mode=legacy`，确保重启前后继续等待显式提升，但新实例不能通过 CLI 主动声明。

项目 UUID、desired/certificate generation 和摘要保存在 `app/etc/wls-project.json`；
该文件随项目目录迁移，宿主只保存可重建的 UUID 路径声明、端口租约和证书快照。当前
WLS 2.0 已形成平台 Broker、`wls-edge/2` 协议鉴权、证书事务和 LKG/A-B
恢复的实现检查点。带日期的旧百万矩阵、任务号和冻结分类只作为历史证据。

当前源码的纯 WLS 直接数据面已完成 macOS、Linux 和 Windows QEMU x64 兼容环境的
三端百万请求，状态为 `LOCAL_RUNTIME_VERIFIED / GA_BLOCKED_EXTERNAL`。物理
Windows/MSVC/SCM 与冷重启、macOS system-domain 冷重启、专用 Windows 容量门禁和
公网 CA/DNS 首签仍未闭合；完成这些外部或系统级证据前不得宣称三平台生产就绪。

## 推荐阅读

1. [Weline_Server 需求](需求.md) — 当前已确认的产品边界、兼容和验收定义。
2. [Weline_Server 开发日志](开发日志.md) — 按目标版本记录当前门禁、整改和未验证项。
3. [WLS 运行时架构：现状与目标](WLS架构图.md) — 总体组件、状态权威、已确认故障、目标架构和验收门槛。
4. [WLS 启动与关闭链路图](WLS启动与关闭链路图.md) — CLI、Master、Orchestrator 和 residual cleanup 的实际时序。
5. [IPC 控制通道架构](IPC控制通道架构.md) — REGISTER、READY、lease、heartbeat、route snapshot 和控制命令。
6. [Dispatcher 分流架构设计](Dispatcher分流架构设计.md) — 数据面转发、路由快照、健康隔离和维护兜底。
7. [WLS Session/Memory 共享服务架构](WLS_Session共享服务架构.md) — 跨 Worker/实例共享状态 sidecar。
8. [WLS 模式部署指南](WLS模式部署指南.md) — WLS 2.0 edge mode、共享网关、纯 WLS 回退、启动参数和运维门禁。
9. [WLS 2.0 Gateway 使用指南](WLS-Gateway使用指南.md) — edge 模式、项目身份、宿主边界与当前实施状态。

## 按问题定位

| 问题 | 首选文档 |
|---|---|
| Windows 启动慢、Worker 批量拉起 | [WLS 运行时架构](WLS架构图.md)、[启动与关闭链路](WLS启动与关闭链路图.md) |
| Worker 掉线、整池重载、路由为空 | [WLS 运行时架构](WLS架构图.md)、[IPC 控制通道](IPC控制通道架构.md) |
| 请求转发、Worker 故障转移 | [Dispatcher 分流架构](Dispatcher分流架构设计.md) |
| TLS 1.3、H2/H1、H3 与 Session 恢复门禁 | [WLS 模式部署指南](WLS模式部署指南.md#5-https--ssl)、[WLS 运行时架构](WLS架构图.md#301-当前-http-协议与连接复用) |
| 项目托管 Nginx、纯 WLS 回退、trusted loopback | [WLS 模式部署指南](WLS模式部署指南.md#13-本项目托管-nginx多项目互不干扰)、[域名接入](WLS模式部署指南.md#4-域名接入) |
| 多项目共享 80/443、edge mode、项目 UUID 与降级 | [WLS 2.0 Gateway 使用指南](WLS-Gateway使用指南.md) |
| 首页预热、常驻内存、请求长尾 | [WLS 运行时架构](WLS架构图.md) |
| Session/Memory 服务异常 | [共享服务架构](WLS_Session共享服务架构.md) |
| SSE/长连接 | [SSE 无阻塞检测方法](SSE无阻塞检测方法.md) |
| Worker 扩缩容 | [Worker 动态扩缩容架构](WLS-Worker动态扩缩容架构设计.md)、[用户手册](WLS-Worker扩缩容用户手册.md) |
| 多实例隔离 | [WLS 实例隔离机制](WLS实例隔离机制.md) |
| 安全与规则 | [WLS 安全与规则配置推演](WLS安全与规则配置推演.md) |

## 状态权威速查

> 本节列出运行事实源，不代表全部能力已经验收。完成度与未完成项以 [WLS 当前能力与验收状态](WLS当前能力与验收状态.md) 为准。

- Master `ServiceRegistry`：进程生命周期、槽位、代际和 READY。
- Dispatcher 的版本化 `SET_ROUTE_TABLE` 快照：数据面路由。
- Worker Fiber 恢复/捕获：`WorkerFiberContextTracker` 必须把目标
  `Fiber` 显式传给 `restoreForFiber()` 与 capture callback；不得退回
  无参上下文切换，否则请求级上下文会在 tick 热路径失配。
- 宿主网关模式以 host Gateway Controller 的 epoch、配置 generation、路由租约与
  Nginx 数据面探针为宿主派生事实；项目的域名、证书源、UUID 和 generation 始终以
  项目文件为事实源。纯 WLS 以 Master endpoint、TLS/HTTP policy 和 Worker READY
  为运行事实，不复用网关的协议结论。legacy 项目托管 Nginx 在显式 promote 前保持
  原状；WLS 2.0 项目发行包内的锁定 Nginx 只作为首次建立宿主网关的受信来源，运行中
  的共享 Nginx 位于宿主 A/B 槽；项目也可显式或降级为纯 WLS。
- SharedState registry：Session/Memory sidecar；只能由认证后的写路径修正。
- `var/server/instances/*.json`：CLI endpoint 发现，不是运行时共识。
- PID/端口索引：可重建缓存，不是存活或身份的最终事实源。

## 文档维护规则

- 源码与文档冲突时，以源码为准，并在同一任务修正文档。
- 总体架构只维护在 `WLS架构图.md`，不要再创建并行总览。
- 专项文档只描述本领域，不复制总览中的整套架构。
- 日期型 `WLS-*-YYYY-MM-DD.md` 是历史快照；新代码不得直接照搬其中的旧类名、端口公式或状态模型。
- MCP SQLite 索引由运行时生成，不在仓库内手工维护；任务上下文通过 `resolve_task_context` 查询。
- 新增可访问入口、配置或运行命令时，同步部署文档；变更启动、READY、路由或关闭时序时，同步链路图。

## 历史材料

以下类型仅用于审计和回归取证：

- `WLS-ISSUES-*`、`WLS-FIXES-*`、`WLS-FINAL-REPORT-*`
- `WLS-HA-*`、`WLS-MASTER-*`、`WLS-SUPERVISOR-*`
- `WLS-default-startup-*`、`WLS-DISPATCHER-*`
- `WLS-EventBuffer-SSL-Worker.md`（EventBuffer TLS Worker 已退役；当前纯 WLS 使用 Stream TLS，本文件仍只供历史取证）
- `wls-panel-plan/` 下的阶段计划和验收证据

历史材料中的 `DispatcherCore`、旧控制端口公式、旧 add/remove-worker 消息、固定复活延迟或“常驻请求 Fiber 池”等描述，除非已被现行源码和总览再次确认，否则均不视为当前契约。
