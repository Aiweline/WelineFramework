# Weline 架构风险与优化评审（v3 · 2026-10-05）

- **产出方式**: `explore` → `risk-quality-reviewer`（主）+ `system-modeler`/`dependency-impact-analyzer` 结论复用；运行拓扑部分由 `deployment-topology-analyzer` 调查补充
- **输入**: ① v2 模块全景报告五通道证据**全量重扫校验**；② 框架自带门禁 `architecture:check` 实跑结果（120 模块 / 8,008 PHP 文件 / 16,241 条跨模块引用）；③ Framework 内核泄漏逐文件核实
- **前作**: `weline-system-model.architecture-understanding.md`（v2）——本报告修正其一处方法学缺陷并新增三类发现

---

## 一、v2 结论时效性校验（今日重扫）

| v2 结论 | 今日复测 | 判定 |
|---|---|---|
| requires 图零循环（Tarjan DAG） | 声明图 cycles=0 | **仍成立** |
| 幽灵 requires：UrlManager→ModuleManager | UrlManager 全目录 grep `Weline\ModuleManager\` = 0 命中 | **已删除（2026-10-06，见§八）** |
| 弱倒挂 I18n→Captcha | 未复测细节 | 维持原判 |
| 接口归属错位 Taglib→ModuleManager | 未复测细节 | 维持原判 |
| 未声明机制边 58 对 | 同口径复算 ≈76 对（含新增 extends/module/Framework/* 计数差异），大户不变：Framework←70、Widget←32、SystemConfig←24 | **规模被低估，且见下文"假阴性"警示** |
| optional↔suggest 对账 | Dropship 7 个 optional 全部缺 suggest 登记 | **原判"Validator 盲区"错误，已撤销**：`composer.missing_suggest` 实测命中 60 条、`composer.missing_require` 44 条，检查真实生效；Dropship 漏网只因它没有 composer.json，而该事实已由 `composer.missing` 覆盖（见§八） |

**已撤销的疑点**：`extends/module/Weline_X` 双前缀目录名曾被怀疑破坏装配——经核 `ExtendsRegistry::organizeRegistryData`（ExtendsRegistry.php:254）的模块名正则接受该形式，且 v2 生成器按同规则归一化，不构成缺陷。

## 二、内置架构门禁实跑：规则在场但全量红

`php bin/m architecture:check` 当前 **clean=false**，即门禁不可用作 CI 阻断。各类违规：

| 规则 | 数量 | 解读 |
|---|---|---|
| `dependency.internal_api` | **1342** | 跨模块引用未走 `<Target>\Api\*` 契约面。大户：Checkout/Query/CheckoutQueryProvider(40)、Maintenance/MaintenanceStaticGenerator(34)、Theme/Controller/Router.php(31)。**这是真实代码里的常态，说明 Api 边界规则从未执行过** |
| `dependency.undeclared` | **672**（198 对） | 类级引用未声明 requires/optional。top：Product→Storage(80)、Smtp→Theme(23)、Checkout→B2B(21)、I18n→Websites(18)。v2 的"58 对未声明机制边"只统计了 event/extends 通道，**类引用通道漏计一个数量级** |
| `dependency.actual_cycle` | **336 个环** | **v2 "零循环"结论仅对声明图成立；实际类引用图是强连通巨环**，热点：SystemConfig(242)、Product(239)、I18n(232)、Websites/Seo/Search(221)。所有在场模块互相可达 |
| `dependency.framework_reverse` | **102** | Framework 引用业务模块符号，违反自家 `CORE_MODULE` 规则。集中：Http/StaticErrorPagePublisher(14)、Runtime/MemDiag(11)、WlsRuntime(10)、StorefrontRenderContextInstaller(9)、App/State(7)、Setup/Upgrade(4) |
| `runtime.blocking_wait` | 38 | 请求可达代码调用原生阻塞等待；Server/Edge/Gateway 系列占大头（HostGatewayPackageManager.php 行号到 19,414 —— **超大单文件本身即维护风险**） |
| `composer.*` | 132 | 模块 composer 元数据缺失/不一致 |

### framework_reverse 细分（决定修复成本）

- **软引用（class_exists/method_exists 守卫）**：App/State.php:180,679,748、DateTime/Timezone.php:30、Cache/Clear.php:135、WarmCacheOnServerStart.php:81 —— 在场门禁式写法，违规属"语义分层"而非崩溃风险；可加 allowlist 或下沉为 provider 契约。
- **硬引用（无守卫直接符号）**：MemDiag.php:215-248（ReflectionProperty 指向 Product 服务类）、ScheduleWindowUtcMigrator、Module/Console/Model/Rebuild —— Product 缺席时这些诊断/迁移路径会抛 fatal（部分被 try/catch 吞掉，属"靠异常兜底的耦合"）。**建议优先收口：改为 extends 扩展点或 RuntimeProviderResolver**。

## 三、优化建议（按 影响×可行性 排序）

1. **把 architecture:check 变成棘轮（ratchet）门禁**：不要求一次清零——按当前 counts 建 baseline，CI 只阻断"新增违规"。成本最低、防止继续恶化。
2. **优先清两类真风险**：
   - `framework_reverse` 硬引用 102 处中的 MemDiag/Rebuild/Migrator 路径（Kernel 卸载业务模块即碎）；
   - `dependency.undeclared` top 对（Product→Storage 80 处）补声明或改 Api 注入。
3. **internal_api 1342 处做存量豁免清单 + 新代码强制**：存量重构不现实，但 Theme/Controller/Router.php、CheckoutQueryProvider 这类高频触碰文件值得就近整改。
4. **实际引用环**：短期接受（事件/钩子机制本来就是环的合法载体），但要防"编译期环导致 opcache/生成顺序问题"——在 provides 消费方向上补一张图（v2 遗留项）再评估是否需要拆环。
5. **（撤销）"修 Validator 盲区"**：复核证明 optional↔suggest 对账并未形同虚设——`composer.missing_suggest` 命中 60 条。无 composer.json 的 25 个模块由 `composer.missing` 单独报告，属真实缺口而非检测缺陷。遗留的是**决策问题**不是代码问题：见§八的 lock/vendor 漂移与未注册包风险。
6. **（撤销）** extends 目录双前缀经核实为 registry 合法命名，无需整改。
7. **超大服务文件拆分**：GatewayHostManager(≥7364 行)、HostGatewayPackageManager(≥19359 行)、GatewayPlatformServiceInstaller(≥9451 行)——单文件行数即爆炸半径，blocking_wait 也集中于此。

## 四、运行拓扑与失败面（deployment-topology-analyzer 调查，全部代码证据）

**进程模型（current-state, 高置信）**：nginx（框架托管 ManagedNginxService，`--no-nginx` 时 WLS 直接公网监听）→ **自研 WLS Master/Worker 常驻进程组**（无 php-fpm；FpmRuntime 仅传统 SAPI 适配）。Worker = `Server/bin/worker.php` 长驻 PHP 进程，数量按 CPU 核数 auto 策略（RuntimeStrategyResolver.php:152-220），单 worker 内存上限 `wls.worker_memory_limit`=256M（ServiceContext.php:257），Fiber 并发准入 max_active=12（Server/etc/env.php:85-91）。侧车：dispatcher / gateway / **session_server** / **SharedMemoryService（内存态权威存储）**。

**数据面**：
- FPC 三层：Worker 进程内 L1（4MB，FullPageCacheCoordinator.php:76-85）→ **WLS 共享内存 sidecar（权威载荷）** → file 兜底（var/cache/）。Redis/MySQL 驱动均标注"开发中"（app/etc/env.php:54-63）。
- Session：WLS 下劫持为 session_server 独立进程共享存储（env.php:201-204，SessionFactory.php:74-128）。
- 队列：**DB 表 broker**（weline_queue，Queue/Model/Queue.php:37）+ cron 消费（Queue/Cron/Queue.php:22）。

**失败面清单**：

| # | 风险 | 证据 | 置信 |
|---|---|---|---|
| R1 | **SharedMemoryService + session_server 是全站数据面单点**：FPC 权威载荷与 session 都依赖单机 TCP 侧车；redis/mysql 替代路径未完成。**注意：DB 表队列不是 FPC/session 的降级路径**（env.php 的 `queue` 仅是缓存命名空间 ttl:172 与 worker 资源限额:287，Framework/Cache 无 Queue 消费代码） | env.php:54-63 注释、WlsMemoryAdapter.php:17、MemoryStateFacade.php:71 | high |
| R2 | 世代切换窗口靠 soft-serve 上一代 receipt 兜底（FullPageCacheCoordinator.php:1129,:2026；Router/Core.php:1716-1746），但**非首页前缀 URL 仍走 STALE 严格路径**——与前缀 URL 永久 STALE 锁死的历史事故一致 | Core.php:1582 传输层世代严格 | medium |
| R3 | 构建锁等待超时 → **503 meta-refresh 等待页**（Core.php:2042-2076）：高并发 rebuild 风暴的可见形态；防线是 single_flight 锁池 hijack 豁免（cc3d10dba）+ file 锁兜底（:5366-5374） | Core.php、FullPageCacheCoordinator.php:489 | high |
| R4 | blocking_wait 38 处集中在 Server/Edge/Gateway 网关管理链（见第二节表），且这些文件体量巨大（HostGatewayPackageManager ≥19k 行）——运维链路的可测性/可维护性最差段 | arch-report.json | high |
| R5 | MemDiag 硬引用 Product 内部类做反射诊断（MemDiag.php:215-248）：内核观测工具与业务模块耦合，属 framework_reverse 硬引用典型 | 第二节 | high |

**发布链**：setup:upgrade 编译期产物（generated/ ServiceProviderRegistry、pub/static、complicate/tpl 重编译）+ server:reload 刷 opcache；wire_generation 盖章与不健康 sidecar 按 cmdline 证明轮换已在 fb77af235 收口（近期提交链 e2ad04b88/fb77af235/4d94ba296 显示 FPC/server 失败面正在主动治理中）。

## 五、证据与再生成

```bash
# 五通道重扫（本次脚本口径）: /tmp/refresh5.php -> /tmp/mech-refreshed.json
# 门禁实跑: cd 仓库根 && php bin/m architecture:check --json > /tmp/arch-report.json
# 幽灵复核: grep -r 'Weline\\ModuleManager\\' app/code/Weline/UrlManager --include='*.php'  (0 命中)
```

## 六、视图交付

- `weline-risk-debt.dot` — 风险/技术债关系图（Graphviz，risk-quality-reviewer + graphviz；节点大小≈违规量级，红色=运行失败面 R1-R5，紫色=结构债务 B1-B4，黄边=机制兜底缓解）
- 阅读顺序：先 weline-assembly-mechanisms.mmd（机制）→ weline-core-overview.dot（结构）→ weline-risk-debt.dot（本评审新增）
- 渲染产物 `*.svg` 不入库，用 `dot -Tsvg <file>.dot -o <file>.svg` 现场重建。

## 七、不确定项

- `architecture:check` 的红是"从未启用"还是"曾绿后退"：git log 近 12 提交全是 FPC/server 修复，未见门禁相关提交；需要问 owner 该命令的预期定位。
- R2 非首页前缀 URL 的 STALE 严格路径是否有自动化回归覆盖（soak/e2e 在 dev worktree 有历史脚本，未逐一验证当前有效性）。

## 八、落地记录（2026-10-06，"按建议处理"）

### 8.1 棘轮门禁已落地（建议 1）

改动文件：`Framework/Architecture/Finding.php`（+`fingerprint()`）、`Report.php`（+`toBaseline()` / `diffAgainstBaseline()`）、`Console/Console/Architecture/Check.php`（+`--baseline` / `--update-baseline`）、新增 `Test/Unit/Architecture/ArchitectureRatchetBaselineTest.php` 与 `architecture-baseline.json`（2630 条指纹）。Framework `2.5.222`→`2.5.223`。

CI 用法：`php bin/m architecture:check --baseline app/code/Weline/Framework/Test/Unit/Architecture/architecture-baseline.json`（有基线外新增即非零退出）。

反证过程（两轮）：
1. **漏报验证**：向 `UrlManager/Model/UrlRewrite.php` 注入对未声明模块 `Weline\Seo\Model\RatchetFalsificationProbe` 的引用 → 门禁 FAIL，命中 `dependency.undeclared` + `dependency.internal_api` + `dependency.actual_cycle` 三条新增；移除后 PASS(exit=0)。
   - 第一轮曾用 `\Weline\Framework\Probe\*` 作探针，**门禁无反应**——原因是分析器对 `$target === CORE_MODULE` 的两条判定都有豁免（Framework 允许被任意模块直接引用），所以 Framework 方向的探针不可能产生 finding。这是探针选型无效，不是检测器缺陷；换非 Framework 业务模块探针后即命中。
2. **误报验证**：改完后 `composer.version_mismatch` 因我的版本 bump 而指纹变化被拦下（真实后果，非误报）；对齐 `Framework/composer.json` 的 `version` 为 `2.5.223` 后通过，基线收紧至 2630。

`ArchitectureAnalyzer.php` 在诊断期间被改过三处（module_resource 合成删除、`$declarationExempt` 引入等），已全部还原为 HEAD 语义——门禁规则集不因诊断而变动。

### 8.2 建议 5 撤销：ComposerMetadataValidator 没有盲区

原判据（"optional 对账形同虚设"）被实测否证：`composer.missing_suggest` 命中 **60** 条、`composer.missing_require` **44** 条，检查真实生效。`composer.missing` 分支虽 `continue` 短路后续字段校验，但短路前已发出 finding，Dropship 缺 composer.json 这一事实本身已被报告。

遗留的是**决策问题**而非代码问题：
- Dropship 的 7 个 optional 要落 suggest，必须先给它建 composer.json；
- 更关键的是 **lock/vendor 漂移**：`composer.lock` 注册 42 个 `weline/*` 包，`vendor/weline/` 却有 44 个目录（含 `module-captcha`、`module-shipping`、`session-manager` 等 lock 中不存在的项）；同时 `weline/module-search`、`module-order`、`module-dropship` 在 `app/code/Weline/` 下有源码与合法包名却**未进 lock**。
- 因此**不建议机械补写 require/suggest**：把未注册的包写进依赖会让 `composer install/update` 解析失败。需先决定这些模块是纳入 Composer 托管还是显式排除在包化范围外。

### 8.3 建议 7 之一：幽灵 requires 已删（UrlManager→ModuleManager）

四处声明位同步删除：`etc/module.php`（requires）、`register.php`（dependencies）、`composer.json`（`weline/module-module-manager`）、`doc/功能现状.md`。UrlManager `1.0.10`→`1.0.11`。

安全性论证（为何删依赖不会打乱装配顺序）：
- 代码面：UrlManager 全目录对 ModuleManager 零类引用；模块身份解析走 Framework 契约 `Weline\Framework\Module\ModuleIdentityProviderInterface`（`Plugin/ModuleUpgradeExecuteAfterPlugin.php`、`Controller/Backend/Url.php`）。
- 降级面：两处消费点都是 `instanceof` 守卫 + 缺失时 `w_log_warning` 跳过路由同步，不会抛错。
- 顺序面：`generated/framework/modules.php` 拓扑序里 ModuleManager idx=11 < UrlManager idx=76，且该 provider 由 ModuleManager `provides` 发布并仍在场——即使不再声明，装配顺序仍成立。
- 运行时注册表已刷新验证：`app/etc/modules.php` 中 UrlManager `dependencies=[Weline_Admin]`、`version/setup_version=1.0.11`（该文件 gitignore，属本机状态）。
- 门禁口径不变：`architecture:check` 统计的是代码引用而非声明，删除幽灵边前后各规则计数完全一致（total 2631→2630 仅来自 8.1 的 version 对齐）。

### 8.3b 基线有效性警示（并发会话）

基线是在**本次改动后的干净工作树**上生成的（2630 条指纹），生成后已复跑确认 PASS。但收尾阶段另一会话新增了未跟踪文件 `app/code/Weline/Payment/Service/PaymentStorefrontLandingUrlService.php`（09:41 写入），使门禁当场报出 **6 条 `[新增]`**，全部指向该文件的 `Weline\Websites\Model\WebsiteDomain` 内部 API 引用。

这既是一次意外的**现场漏报反证**（棘轮确实能拦下别人新写的违规），也说明：**不得为消除这 6 条而执行 `--update-baseline`**——那等于把他人未收口的改动永久合法化。正确做法是由该会话自行改走 `Weline_Websites\Api\*` 或补声明后再收紧基线。

处置：给棘轮模式加 `--ignore-file <a,b>`（路径子串排除，仅影响门禁判定、不改基线内容，输出里显式报告"已排除 N 条"）。已反证其窄性——排除 Payment 那个文件后，再注入自己的探针仍被拦下（exit=1，3 条新增），移除探针后 exit=0。CI 用法：

```bash
php bin/m architecture:check \
  --baseline app/code/Weline/Framework/Test/Unit/Architecture/architecture-baseline.json \
  --ignore-file Payment/Service/PaymentStorefrontLandingUrlService.php
```

待该会话收口后应去掉 `--ignore-file` 并重新收紧基线。

### 8.3c 附带发现：setup:upgrade 收尾阶段成本异常

`php bin/m setup:upgrade -m Weline_UrlManager` 的 UrlManager 相关增量工作（模块注册表、事件/Hook/命令/Taglib）在 **14 秒内全部完成**并已落盘（`app/etc/modules.php` 已刷新）；但进程随后进入 `SetupUpgradeAfter` 观察者链（404 静态页发布 46 个快照、PHTML 重生成等全局收尾），**单模块升级也要跑完整全局收尾**，17 分钟仍未结束且无任何进度输出（stdout 被缓冲）。

本次以 SIGTERM 终止：站点健康（HTTP 200、`env.system.maintenance=false`），无残留 flock 持有者，锁文件保留 pid 供下次自动回收。**风险**：观察者 22/23（`setup_upgrade_purge_layout_entities`）被中断，布局实体清理可能不完整——建议下次跑完整全量 upgrade 或在 e2e 验收时覆盖。这是可优化点（按 `-m` 作用域跳过无关全局收尾），属运行拓扑/性能面而非本次架构门禁范围。

### 8.4 尚未执行（需授权或更大范围）

- 建议 2：`framework_reverse` 硬引用收口（MemDiag/Rebuild/Migrator）——要改 Kernel 与业务模块的装配方向。
- 建议 3：`internal_api` 1348 处存量豁免清单——量大，需定策略。
- 建议 4：provides 消费方向补图后再评估拆环。
- 建议 7 余下：GatewayHostManager / HostGatewayPackageManager / GatewayPlatformServiceInstaller 超大文件拆分。
