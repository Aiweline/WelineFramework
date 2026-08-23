# Weline_Framework 模块文档中心

## 📚 文档导航

### 🚀 快速开始
- [模块开发完整指南](./3-开发/模块开发完整指南.md) - 从入门到精通
- [快速参考_常见错误和解决方案](./2-快速开始/11-快速参考_常见错误和解决方案.md) - 问题速查

### 👨‍💻 开发文档

#### 核心开发
- [模块开发完整指南](./3-开发/模块开发完整指南.md) - 模块开发全流程
- [BinQuery 文档目录](./BinQuery/) - 站外二进制 Query 网关、SDK 与 Provider 开发指南
- [SSE 可恢复后台任务架构](./3-开发/SSE可恢复后台任务架构.md) - 站内 Worker 查询、一次性 stream ticket 与可恢复任务边界
- [性能与稳定性预算](./architecture/04-performance-budget.md) - 请求 deadline、严格连接池与 SQLite busy 重试约束
- [开发日志](./开发日志.md) - 文档与框架变更记录

#### 国际化开发
- [翻译函数使用指南](./3-开发/01-翻译函数使用指南.md) - i18n 开发指南
- [占位符使用说明](./i18n-placeholder-usage.md) - 占位符契约

#### Console 命令
- [module:create 命令](./5-模块管理/module-create命令使用文档.md) - 模块脚手架入口

### 🚀 部署文档
- [服务器部署](./1-部署/服务器部署.md) - 生产环境部署指南

## 🎯 框架简介

`Weline_Framework` 是 WelineFramework 的核心模块，提供：

### 核心功能
1. **MVC架构** - 清晰的模型-视图-控制器分离
2. **模块化设计** - 支持独立模块开发、部署和维护
3. **ORM数据库** - 自定义ORM链式操作
4. **事件系统** - 观察者模式和事件驱动
5. **命令行工具** - 丰富的CLI命令支持
6. **国际化支持** - 完整的多语言系统
7. **缓存系统** - 多层次缓存支持

默认主数据库与生产验收数据库均为 PostgreSQL（`pgsql`）。SQLite 只允许
显式用于 `sandbox_db`、一次性隔离开发或可移植性回归；涉及 ORM、Schema、
事务、锁与迁移语义的正式结论必须有 PostgreSQL 证据。
`DEBUG=true` 只开启调试能力，不会自动切换数据库；只有显式 `SANDBOX`
请求或 `enableSandboxMode()` 才能选用 `sandbox_db`。`env.php` 的持久化更新
使用独立锁、同目录完整临时文件和原子替换，禁止先截断正式配置再写入。原子替换前，
临时文件必须继承现有 `env.php` 的 UID、GID 与权限位，避免以 `root` 执行安装或升级后
把 PHP-FPM 运行用户排除在配置文件读取权限之外；部署验收应同时检查 `stat` 与一次新的
PHP-FPM 请求，而不能只验证当前 CLI 进程。

## 站外 BinQuery 与站内 Worker QueryBin

这两条链路名称接近，但协议和信任边界不同：

- `/bin/query` 是站外 SDK / API Key 协议，规范位于 [BinQuery 文档目录](./BinQuery/)。
- `/{rest_frontend}/framework/query-bin` 是浏览器页面的站内二进制入口，只允许 `Weline.Api → Dedicated Worker → QueryBin` 调用。浏览器业务代码不得使用原生 `fetch`、XHR 或 axios 绕过 Worker。

站内 Worker Session 由服务端构造权威区域，`frontend` 与 `backend` 绑定互斥。Scope Token、后台 Session ID、binding digest、HMAC secret 均不得进入页面 JavaScript；页面只接收一次性的 43 字符 opaque bootstrap ID。当前时限为：bootstrap 120 秒、未绑定 Worker Session 600 秒、nonce 180 秒、stream ticket 60 秒；Scope-bound Session 使用已验证 Scope Token 的固定 1800 秒窗口，并与 Token 验签共享“签发时间最多领先 60 秒”的边界，过期时间不延长；Backend Session 最长 600 秒且还受后台证明的更早到期时间约束。共享状态恢复会重新验证这些规范时间窗，不能用持久化数据延长权限。

当 Scope provider 判定当前 HTTPS storefront 为 `allowlist/on` 时，无 bootstrap 的握手在创建 Session 前返回 401 `scope_binding_required`，不得占用未绑定 Session 容量。已配置 provider、keyring 或状态存储不可用时保留 503；只有 proof 无效、过期或已消费等客户端错误归一为 401。Provider 若抛出 `ResumableTaskAccessDeniedException`（含 backend 证明与 Session 不一致），QueryBin 映射为 401 `backend_attestation_invalid`，不得再包装成 500 `Internal server error.`。二进制输出保护不得把 stray output 原文写日志，只允许记录字节数与 SHA-256。

默认 Session/nonce/ticket/bootstrap 存储位于 `var/cache/frontend_worker/store.json`。实现要求目录 `0700`、文件 `0600`、锁内原子替换、8 MiB 硬上限和不安全文件 fail-closed。该存储只适合单机或受控 allowlist 验证。

显式 `wls.frontend_worker_session_store_driver=redis` 仍只用于 dev/test 的单键 snapshot-CAS 验证：CAS 冲突会重读并重新执行安全断言，故障明确 503，绝不自动回退本地文件；`system.deploy=prod` 会拒绝该驱动。多节点使用 `database` 驱动时，Session、bootstrap、nonce 与 stream ticket 以主库逐记录事务保存；MySQL/PostgreSQL 使用行锁，SQLite 使用 `BEGIN IMMEDIATE` 但不具备生产共享资格。索引只保存域分离 SHA-256，payload 使用 XChaCha20-Poly1305 加密；一次性凭据消费后保留到原 expiry 的 tombstone，容量统计包含未过期 tombstone。nonce 先锁定由 Session 哈希首位确定的 16 个容量分片之一，再锁 Session 与 nonce；每分片最多保留 32,768 个未过期 retained 行，因此全局未过期窗口硬上限为 524,288，同时继续执行每 Session 4,096 行限制。每次 nonce 写路径在同一 shard guard 下先跨 Session 删除至多 256 个该分片的过期行；单次成功写入最多增加一行，因此持续写入时会排空历史 backlog，而不会让已放弃 Session 的过期行逐轮累积。stream ticket 以实际 ASCII 密文长度统计，未过期密文总量上限为 8 MiB；其它类型的过期清理同样按固定类型、每次至多 256 行执行。授权查询始终使用数据库 epoch 校验到期时间，清理停摆不能延长权限。

`database` 配置必须预置一个 `active` 32 字节 base64url key，可同时保留 `decrypt_only` 旧 key。轮换时先让所有节点加载 old+new 并核对 `stateStoreDiagnostics()` 的 keyring version/digest，再切 active key；等待旧 key 对应凭据跨过最长 1800 秒 TTL、清理与时钟余量后才能删除旧 key。生产还必须设置 `production_rpo_zero_attested=true` 与稳定 `production_topology_id`；这只是 fail-closed 准入声明，不能替代同步提交/RPO0 与真实切主重放验证，框架也不能自动判断复制拓扑是否为异步。WLS 对所有显式非 `local` 驱动在首次 READY、ACK 超时重发、普通 TCP 自动重连和 Supervisor 自动重连的每次 READY 发送前执行非可降级检查；检查覆盖驱动策略、真实列与索引、20 个容量 guard、credential 表的加密 insert→read→consume→delete 事务回环和 keyring。门禁失败不会发送 READY，自动重连会关闭刚建立的控制连接等待后续恢复重试。缺失 guard 会先尝试幂等补齐；无法补齐、schema/index 不完整、凭据表写路径不可用、缺 key、未知 key、密文损坏或数据库异常均返回 503，不得回退 local/Redis。提交确认结果不确定时客户端不得自动重放 consume，应刷新页面取得新的 proof/ticket。

实现入口：[QueryBin 控制器](../Controller/Api/QueryBin.php)、[站内 Gateway](../Service/Query/FrontendQueryGateway.php)、[Worker Session 服务](../Service/Query/FrontendWorkerSessionService.php)。

## Storefront 导航 Scope 与 URL 本地化

- Website/Domain 注册表使用 `var/runtime/website-parser-sites.version` 作为跨
  Worker 版本事实。WLS 请求至多 1 秒复核一次版本；版本变化时同时清空
  `Url` parser 与 `DetectWebsite` 进程缓存，使刚创建的新 Host 不再等待旧
  300 秒 TTL。该文件只记录版本，不承载站点业务数据。
- `App` 在 storefront FPC 查找前通过 `StorefrontScopeInstallerInterface` 安装一次完整 Website/Store/Channel `ScopeIdentity`，并使用 `StorefrontNavigationScope.routePath` 把已命中的 Store URL 前缀消费成 Router 余量。普通请求内的已冻结身份不得被不同值二次改写。唯一例外是 `rest_frontend` QueryBin：API 路由最初只能按 Host 冻结默认 Store；在 Host、Token、Catalog 与 rollout 已复核，且服务端构造的 execution binding digest 完全一致时，`RequestContext::replaceScopeIdentityForTrustedWorker()` 可把它细化为同 Website 的受信 Store/Channel，并清空 storefront 路由余量。该操作不改变 request authority、method、URI、locale、currency 或 timezone；跨 Website、非 frontend、非权威或 binding 不一致仍返回 409。
- `App\State::resolveLocalizationFromPathSegments()` 是 URL 前缀单一解析契约：可选 area 之后允许单独 currency、单独 locale、`currency/locale` 或 `locale/currency`；canonical 始终输出 `currency -> locale`。后台 area key 必须位于第一段，不得硬编码具体 key。
- `App\State::getLang()` 在路径 / Cookie / env 候选之上必须再过 `isAllowedLanguageCode()`（站点 WebsiteLanguage 优先）。站点未启用的残留语言码（例如 Cookie 里的 `ar_*`）一律回落到 `resolveWebsiteDefaultLanguage()`，避免无前缀 URL 上语言切换器显示幽灵短码且无法切回默认语言。
- `RequestContext` 按请求/Fiber 保存 Scope、路由余量、locale、currency 和 timezone；路由余量与时区位于可跨 Context 快照重建保留的请求隔离区，冻结后只允许同值幂等写入。站点时区不修改 PHP 进程全局 timezone。
- `QueryBin` 成功响应的 `scope_meta` 严格为 `scope_kind`、`website_id`、`website_code`、`store_id`、`store_code`、`store_mode`、`channel_id`、`channel_code`、`locale`、`currency`、`timezone`、`context_version`。该投影不包含 Token、签名、bootstrap ID、指纹、Worker secret 或路由余量。

站点命中与 Store/Channel 选择细则见 [Weline_Websites 请求 Scope 文档](../../Websites/doc/store-saleschannel-scope.md)。

### 架构特点
- **依赖注入** - 自动依赖解析和注入
- **观察者模式** - 灵活的事件监听机制
- **插件系统** - 支持功能扩展
- **模板引擎** - 强大的视图渲染能力

## 日志配置与通道路由

- `log.min_level`、`log.channels.*.min_level/enabled` 与
  `log.module_levels` 必须由 `LoggerFactory` 传给每个运行时 logger 的
  `LogFilter`；测试或运行时显式配置不能被 Env 中的旧全局配置覆盖。
- `app` 写入日志根目录的级别文件；`cron/sql/auth/payment/api/wls/session`
  写入各自子目录；`exception/php_error` 保持根目录；其他通道写入
  `other/`。调用方和测试不得假定所有通道都在根目录。

## 📖 推荐阅读顺序

### 新手入门
1. [模块开发完整指南](./3-开发/模块开发完整指南.md)
2. [快速参考_常见错误和解决方案](./2-快速开始/11-快速参考_常见错误和解决方案.md)
3. [国际化开发](./3-开发/01-翻译函数使用指南.md)

### 进阶开发
1. [Console 命令开发](./5-模块管理/module-create命令使用文档.md)
2. [事件系统](./3-开发/服务器事件系统.md)
3. [ORM开发](../../../../../docs/WelineFramework模型开发最佳实践.md)

### 生产部署
1. [服务器部署](./1-部署/服务器部署.md)
2. [部署文档](../../../../../docs/部署文档.md)

## 📝 文档贡献

如需添加或更新文档，请遵循：

1. **文档分类**: 按功能模块分类存放
2. **命名规范**: 使用描述性名称
3. **格式标准**: Markdown 格式，包含目录
4. **代码示例**: 提供完整可运行的示例

## 🔗 相关链接

- [框架开发文档](../../../../../docs/dev/开发文档.md)
- [模型开发最佳实践](../../../../../docs/WelineFramework模型开发最佳实践.md)
- [事件调试功能](../../../../../docs/事件调试功能使用指南.md)
- [常见问题修复](../../../../../docs/常见问题修复指南.md)

## 📅 更新记录

- **2025-10-26**: 创建文档中心，规范化文档结构
- **2026-06-17**: 新增 BinQuery 网关、SDK 与协议文档入口
- **2026-07-22**: 区分站外 BinQuery 与站内 Worker QueryBin，并记录 Session 安全边界
- **2026-07-23**: 增加逐记录数据库 Worker credential store、密钥轮换与生产 RPO0 准入边界
- **2026-07-23**: 增加 Storefront 导航 Scope 一次冻结、路由余量、URL 本地化顺序与安全 `scope_meta` 契约
- **2026-07-28**: 明确日志级别配置必须注入 LogFilter，并记录通道路由契约
- **2025-01**: 完善国际化文档
- **2025-01**: 更新Console命令文档

---

**维护者**: WelineFramework Core Team  
**最后更新**: 2025-10-26
