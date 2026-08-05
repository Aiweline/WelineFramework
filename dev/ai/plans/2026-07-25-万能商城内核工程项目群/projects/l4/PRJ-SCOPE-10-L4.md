# PRJ-SCOPE-10 L4 执行卡

## 1. 当前判定与顺序

- 状态：`TASK-P1A-001/002/003/004/005/006、TASK-P1B-003、GATE-P1A COMPLETED / ACCEPTED / GO`。
- 既有代码只证明骨架存在；不能用任务勾选或未知 `__store` 返回 200 代替门禁。
- 严格顺序：`P1A-001 → (P1A-002 read-only || P1A-003) → P1A-004 → (P1A-006 || P1B-003) → P1A-005 → GATE-P1A`。
- 同文件串行：`RequestContext.php` 先 P1A-003 后 P1B-003；`ScopeResolver.php` 先 P1A-004 后 P1A-005；module/register/doc 只在整合窗口修改。

## 2. L4 卡

| Slice | 单一结果 | 精确路径/符号 | 进入/写锁 | 验证 | 停止条件 |
|---|---|---|---|---|---|
| `001A` | Store/Channel additive schema 与不变量成立 | `Websites/Model/Store.php::{save_before,delete_before}`；`SalesChannel.php::{save_before,delete_before}` | `WRITE_LOCK_0946` | 0 合法、缺失字段拒绝、唯一 default、mode 不可变、父子 Website 一致、tombstone | 需要物理删除已引用 Store、无法表达三库唯一约束 |
| `001B` | 默认 Store/Channel 三库并发幂等 | `Websites/Service/StoreChannelSeedService.php::{ensureDefaults,ensureDefaultsForWebsite}` | 001A；独占文件 | MySQL/PgSQL/SQLite 各重复 3 次新增为 0；同 connector/事务 | 写死单一 SQL 方言、吞冲突、跨连接 seed |
| `001C` | 既有/新 Website 在升级边界补种且失败上抛 | `Website.php::save_after`、`DefaultWebsiteService`、`Setup/Upgrade.php::setup` 或正式 lifecycle Hook | `MERGE_LOCKED` | default/0 与普通站各恰一 default/normal Store+Channel | 上游 Owner 未冻结、异常只能静默吞掉 |
| `001D` | 版本/文档与最终 schema 同批发布 | `Websites/etc/module.php`、`register.php`、Websites docs | module/doc 整合窗口 | schema hash、版本、文档一致 | 与上游版本再次漂移 |
| `002A` | 稳定只读 Catalog v1 | DTO、`StoreCatalogInterface`、`SalesChannelCatalogInterface`、对应 Service | 001A；独占文件 | website=0、disabled、default 唯一、父子一致 | DTO 泄露 Model/可变对象 |
| `002B` | QueryProvider 仅发布 v1 read operations | `Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php::{__construct,execute,getDescriptor}` | QueryProvider 文件锁 | descriptor/参数/返回 schema 可查询；无写 operation | ACL/mode-off 无法保证却要求开放写 |
| `002C` | 后台仅呈现只读嵌套目录 | `Controller/Admin/Website.php::{add,edit}`；源模板 `view/templates/Admin/Website/{index,form,table}.phtml` | 混写/UI 整合窗口 | admin 可见；无权限隐藏/403；Browser console 无 error | 需先暴露未鉴权写入口 |
| `002D` | 写 CRUD 激活由对象 ACL 卡负责 | `TASK-P1B-004-ACL` | 不在 P1a 提前实现 | 写菜单/operation 保持 off | 任一匿名/无 Scope 权限写入口可达 |
| `003A` | ScopeIdentity 严格、不可变、0 与缺失可区分 | `Framework/Runtime/ScopeIdentity.php::{fromArray,global,website,store,channel,canonicalKey}` | `WRITE_LOCK_0946` | 缺字段拒绝；0 roundtrip；code/mode canonical | 缺失被强转 0、Global 与 zero-site 混淆 |
| `003B` | RequestContext 只安装一次 frozen identity | `RequestContext.php::{installScopeIdentity,scopeIdentity,resetWelineVars}` | `MERGE_LOCKED`；HIGH impact | 二次安装冲突；旧 getter 只读 canonical；reset 清空 | 现有 caller 必须二次改 Scope、上游 Owner 未冻结 |
| `003C` | ScopeContext 仅兼容读取 | `ScopeContext.php` facade/setter | 003B 后串行 | freeze 后 setter 拒绝或 deprecated | 业务仍可绕过 identity 改三段 |
| `004A` | 可信 Host/URI/path/default 唯一解析，显式冲突拒绝 | `ScopeResolver.php::{resolve,resolveStore,resolveChannel}` | 003；独占文件 | unknown/disabled/cross-site 参数非 2xx；缺失才 default | 未签名 query/Cookie 可决定 Scope |
| `004B` | Website 命中后一次 install/freeze | `DetectWebsite.php::processSite` | `MERGE_LOCKED`；LOW graph risk但请求关键路径 | response `scope_meta` 与服务 Context 一致 | catch-all 后继续请求、identity 被丢弃 |
| `004C` | locale/currency 与 Store/Channel URL 共用 canonical parser | `StripCurrencyLocalePrefix.php::execute`、`UrlProcessor::{normalize,processWithEvents}`、`Http/Url.php` | URL 文件锁 | 单段/双段任意顺序同 Scope，输出单一 canonical URL | 新解析器平行猜测、后台 key 丢失 |
| `006A` | 所有缓存键使用同一 frozen Scope+版本 | `KeyBuilder::{storefrontDimensions,environmentContext,buildRouterScopedCacheKey}` | 003 后；CRITICAL impact；`MERGE_LOCKED` | store_mode/config/catalog/price/theme version 均入键 | 任一 caller 无法获得同一 identity；此时暂停 FPC |
| `006B` | 登录用户整页 bypass，匿名 FPC 完整隔离 | `FullPageCacheCoordinator::{canServeCachedResponse,canBuildCachedResponse,canPublishResponse,buildCurrentFpcVariant,variantSuffixWithoutSchema,fpcNamespaceFingerprint}` | FPC 上游锁冻结 | 登录永不命中/发布；匿名 A/B 不串 | 默认 private full-page cache 仍启用 |
| `B003A-D` | request/Fiber/异常/SSE reset 失败不继续复用 Worker | `RequestContext` cleanup；`ModuleRequestResetterRegistry::resetRequest`；`StateManager`；`WlsRuntime::{handle,reset}` | 003 后；Runtime 上游锁 | A/B/A、异常、SSE、peer Fiber mismatch=0；reset failure quarantine | resetter 异常被吞、Context leave 前仍有 Scope |
| `005A` | Token v1 固定 1800s、kid/keyring、结构化结果 | `ScopeTokenService::{issue,verifyCandidate,verify,shouldRenew,renew}`；`ScopeTokenKeyring` | 004 后；独占文件 | valid/expired/tampered/unknown-kid/service-unavailable 分类；过期不可原地续签 | 请求期临时造 key、无法轮换或区分错误 |
| `005B` | claims 与可信请求/Store 事实重验 | `ScopeResolver` 页面导航解析；`ScopeTokenService::{verifyCandidate,verify}` → `FrontendWorkerScopeProvider::{verifyToken,restoreBinding,resolveTrustedScope,installTrustedScope}` → Store/SalesChannel Catalog | 005A；ScopeResolver/Provider 串行 | host/audience/store_mode/context_version/目录生命周期冲突拒绝 | 只验 HMAC 不复核 Store 事实 |
| `005C-D` | Header/Cookie/QueryBin 自动携带、同站受控细化与 response meta | `FrontendWorkerScopeBootstrapResponseService::decorate`；`FrontendWorkerSessionService::{createScopeBootstrap,createSessionFromScopeBootstrap}`；`FrontendWorkerScopeBinding`；`FrontendWorkerScopeProvider::restoreBinding`；`QueryBin::postIndex`；`RequestContext::replaceScopeIdentityForTrustedWorker` | QueryBin/RequestContext 文件锁 | 无效/过期 token 非 2xx；失败不消费 bootstrap；Cookie flags；同 Website test Store 可细化、跨 Website 409；`scope_meta` 固定 12 字段 | QueryBin 绕过 Scope、把篡改/过期当原地续签或允许跨 Website 改写 |

## 3. 截至 2026-07-24 的执行进度

- `001A/001B/001C/001D` 已完成真实生产补丁、三库并发/幂等/回滚矩阵、真实 `Upgrade::setup()` 边界、聚焦回归、隔离编译、当前源码专用 WLS/Browser、读前读后 hash 和精确清理；独立终局复核结论为 `GO`，组合 `TASK-P1A-001` 状态为 `completed / accepted`。没有用勾选代替实施。
- `001` 最终证据：18 tests / 119 assertions；SQLite 3.53.3、PostgreSQL 16.14、MySQL 8.4.10 均完成确定性双进程 winner `1/1` + loser `0/0`、错误唯一约束不误吞、故障全回滚和连接亲和 fail-closed；隔离 compile 为 83 modules / 46 QueryProviders / 0 deferred。专用 WLS `ai-test-p1a001-20260724-0632-7f3c` / 9668 的4/4 Worker READY，Browser 显示 7 个站点均有唯一只读 default Store+Channel，console=0，三表摘要不变；标签、实例、端口与 PID 均已清理。
- `001` 运行移交：同实例直连匿名前台探针因 `scope_token_issue_unavailable` 返回 503；Store/Channel Scope 已在此前解析成功，失败属于 P1A005/运行配置的 Token 签发 fail-closed。该项不阻断 001A-D，但 `GATE-P1A` 必须显式复核，不得继续记录为“全实例 error=0”。
- `002A/002B/002C/002D` 已完成真实 Catalog/Query/后台只读目录实施、严格回归、隔离与当前主目录编译、专用 WLS/Browser、PostgreSQL 只读守恒和精确清理；独立代码与运行证据两路终局复核均为 `GO`，组合 `TASK-P1A-002` 状态为 `completed / accepted`。Store/Channel 写 Controller、菜单与 Query mutation 继续关闭，对象 ACL/IDOR 写验收仍归 `TASK-P1B-004-ACL`。
- `002` 最终证据：`--fail-on-risky 61 tests / 745 assertions`；compile `83 modules / 46 QueryProviders / 0 deferred`；两条 Catalog operation 均为 v1/read、10/9 精确字段；4/4 Worker READY 后 Browser 验证 7 行目录、零号站、GET 搜索/清除、编辑/新增和三条写路由 404，console=0；Store/Channel/Website/Catalog-generation 在任务窗口前后三次摘要一致；专用实例、9672/19737/19738/35979、PID 与临时目录均已清理。
- `003A/003B/003C` 已完成真实实现、定向回归、专用 WLS、Browser 与清理验收；组合 `TASK-P1A-003` 状态为 `completed / accepted`。
- `003A` 最终证据：78 tests / 316 assertions；4/4 Worker READY；首页 12/12 HTTP 200；Browser 可见零号站、默认 Store/Channel；console 与成功实例日志无错误。
- `004A/004B/004C` 已完成真实实现、独立复核、定向回归、专用 WLS、Browser 与清理验收；组合 `TASK-P1A-004` 状态为 `completed / accepted`。没有用勾选替代实施。
- `004` 最终证据：108 tests / 497 assertions；20 个生产 PHP 文件语法通过；4/4 HTTP Worker READY；零号站/普通站 Store URL 与两种本地化顺序均 200；冲突 409、非法编码 400；12/12 交替请求 Scope/FPC 变体不串；Browser 可见页面与 409 提示且 console 无错误；临时 Store/Channel 与 WLS 均已清理。
- `TEST-P1A-04` 的 canonical writer 固定输出 `currency -> locale`，兼容两种输入顺序和省略组合；Store URL 前缀被消费为 Router 余量，余量/时区跨 Context 快照保持且冻结后异值改写被拒绝。
- `006A/006B` 已完成真实实现、聚焦回归、专用 WLS、应用层交替/并发 FPC、真实登录 Session、定向 generation 失效、Browser 和精确清理验收；组合 `TASK-P1A-006` 状态为 `completed / accepted`，没有用任务勾选代替实施。
- `006` 最终证据：实施期合并 155 tests / 749 assertions，交付前聚焦复跑 80 tests / 818 assertions；4/4 Worker READY；A/B 各 8 次交替与各 8 次并发全部 200 + 应用 FPC HIT 且 Scope code/Host/variant 不串；登录连续两次 MISS/no variant，匿名仍 HIT；A config 0→1 只改变 A variant；Browser 两页可见且 console error/warn=0；2/3/3 与 Session、专属进程、19736-19738 TCP/UDP 均已清理。
- 文档 `file://` Browser 导航被产品 URL 策略拒绝，已按 `BLOCKED_ENVIRONMENT` 留证且未绕过；文档内容与差异检查通过，待人工按绝对路径打开补视觉证据。
- `B003A-D` 已完成真实 Runtime/WLS 实施、canonical 回归、专用 WLS 并发/异常/SSE/fault quarantine/reload、Browser 与精确清理验收；`TASK-P1B-003` 状态为 `completed / accepted`，没有用任务勾选代替实施。
- `B003` 最终证据：交付前 affected matrix 121 tests / 606 assertions，六组核心 32 tests / 150 assertions；A/B 同 Worker 各 100 次并发全部 200、各 100 个 request ID、missing=0、mismatch=0；SSE `1/10/1` 同时另一站 100/100 mismatch=0；直连 fault 返回 500 + `Connection: close` 并替换 Worker；rolling reload 后四步序列仍全 200/mismatch=0；Browser A/B 页面可见且 console error/warn=0；fixture、Session、fault 控制、专属进程与 19736-19738 TCP/UDP 均已清理，9501 未触碰。
- 路由验收期间触发的全局 ACL 局部刷新副作用已完整恢复，最终 definitions/grants/missing/dangling=`872/872/0/0`；无关 PostgreSQL SMTP schema 差异保持原状并留给 owning task。
- `005A/005B/005C-D` 已完成真实实现、聚焦回归、隔离编译、专用 HTTPS/WLS、QueryBin、Browser 与精确清理验收；`TASK-P1A-005` 状态为 `completed / accepted`。唯一 Scope 冻结例外已固定：仅 `rest_frontend` QueryBin 可在服务端 execution binding digest 完全一致、Host/Catalog/rollout 已复核且 Website ID/code 相同时，把 Host 默认 Store 细化为受信 Store/Channel；跨 Website、非 frontend、binding 不一致或非法 ID 均在改写前 409，其他请求权威与 locale/currency/timezone 不变。
- `005` 最终证据：10 个聚焦 PHPUnit 类 71 tests / 413 assertions；隔离 compile 83 modules / 46 QueryProviders / 0 deferred；TLS 1.3 + HTTP/2；缺 proof/篡改/过期/错误 audience/Host/旧或不兼容 context version/重放/跨 Host Session 均得到精确 401/409，且失败不消费原 bootstrap；Browser A/B 返回各自精确 Scope、console error/warn=0；两轮 fixture、Worker state、实例、配置与 19737/19738/19740 均已清理，9501 未触碰。
- 当前 `TASK-P1A-001/002/003/004/005/006` 与 `TASK-P1B-003` 均已关闭为 `completed / accepted`；此前主计划 `TEST-P1A-02` 的循环依赖已按真实边界落地：P1A 验服务层不变量、只读 Catalog/UI 与写入口关闭，写 CRUD ACL/IDOR 由 P1B-004 的 `TEST-ACL-01/TEST-SEC-04` 验收。
- `GATE-P1A` 已于 2026-07-24 以当前源码组合证据签署 `GO`：关闭 P1A-001 移交的 keyring/503 风险；`off` 与 `allowlist`+临时 keyring 双阶段、Token/QueryBin 负向矩阵、FPC A/B 隔离、Browser 与精确清理均通过。证据任务：`dev/ai/codex/tasks/2026-07-24/2026-07-24-0815-gate-p1a-final-acceptance/`。
- 下一步：按台账进入 `PRJ-ASYNC-11`（P1B Envelope/ACL/Notify/EAV/MIG）就绪评估；主计划 `scope-kernel` 仍覆盖完整 P1，在 P1B/C/D 完成前保持 pending。

## 4. 当前强制回归

- `B003A-D` 当前基线已全绿：交付前 affected matrix `121 tests / 606 assertions`，六组核心 canonical runner `32 tests / 150 assertions`；后续修改 Runtime/WLS/Fiber 边界必须至少复跑这两层矩阵，不得退回旧 `46/47` 基线。
- `TEST-P1A-01..05`、`TEST-SEC-01..03/08`、`TEST-WLS-02/03/05` 必须有可复制入口、数据前后摘要、日志和清理证据。
- 本轮用户已明确授权测试工作；新增或修改测试仍须严格对应当前任务契约，覆盖不了的故障窗不得虚报通过，应记录为 Gate 证据缺口。
- 专用 WLS 只能使用唯一 `ai-test-*` 与 9502+；自动验证后停止，人工验收保留时必须交付 URL、实例、端口、状态、停止命令。
