# Weline 整仓模块全景 — 架构理解报告 v2（机制感知版）

- **产出方式**: `explore` → `system-modeler` + `graphviz`；v2 相对 v1 的核心变化：**先学透框架装配机制，再按机制重新判定依赖边语义**
- **证据通道（5 类）**: `etc/module.php` requires/optional/provides · `etc/event.xml`(72 模块) · `extends/module/<Target>/`(54 模块 122 处) · `view/hooks/*.phtml`(18 模块 43 文件) · `Framework/Register|Extends|Event|Hook|Registry` 源码
- **生成日期**: 2026-10-05 · 再生成: `php 架构可视化/generate-module-dot-v2.php`（需先按 evidence.md 刷新 /tmp 两个 JSON）

---

## 1. 框架装配机制（本版新增，全部有源码证据）

模块间集成走**四条通道 + 一道在场门禁**：

1. **requires → 拓扑排序**：`Register.php:893-906` 明确 etc/module.php requires 是排序权威（与 register.php、env dependencies 合并后交 `Sort::dependenciesSort` DFS）。**optional 不参与排序**——它的职责由第 2 条承担。
2. **optional → Composer suggest 对账**：`Architecture/ComposerMetadataValidator.php:79` 强制每个 optional 必须在 composer.json `suggest` 中登记。optional 是"软能力声明"，不是运行期开关。
3. **provides → DI 权威绑定**：`ObjectManager.php:146-205,761` ——接口实例化只认 ServiceProviderRegistry（编译自各模块 provides），注释原文 *"etc/module.php provides is authoritative"*；Factory 仅是第三方迁移桥。**可选能力**用 `RuntimeProviderResolver::resolve()` null-safe 解析（未配置=功能关闭，配置坏=可恢复错误，两态在 `resolveDetailed` 区分）。
4. **extends → 契约化装配**：模块根下 `extends/module/<目标模块>/<扩展点>/Xxx.php`，扩展点由各模块 `extends.php` 规约声明（接口+路径+required/multiple），`ExtendsScanner` 扫描、`CompletenessChecker` 校验契约完整性。Framework 自身开放 10 个扩展点（Cache/Query/Session/Fpc/Schema/Csp…），业务模块也开放：Ai 4、Seo 9、Search 2、Widget 1。
5. **event.xml → 观察者总线**：`<observer instance=... delivery=sync|async retry=standard coalesce=latest timeout=...>`，支持异步投递与合并策略；事件带 `data_contract` 元数据。
6. **在场门禁**：`RegistryModulePresence::isActivePresent()`（status + 源码目录 + register.php 三条件）被 EventRegistry(×6)、ExtendsRegistry、HookRegistry、PluginRegistry 消费——**目标模块缺席时该装配整体跳过**，这就是跨层集成不需要硬 requires 也安全的原因。

## 2. 请求主链（同 v1，高置信）

`index.php → pub/index.php → app/bootstrap.php → App::runWithRuntime() → WlsRuntime|FpmRuntime → RequestPipeline → Router\Core → Controller/View + FPC`

## 3. 分层全景（扇入不变，边语义修正）

L0 Framework(扇入78) → L1 Backend(63)/SystemConfig(37)/I18n(24)/Websites(22)/Theme(19)/Admin(17)/Queue/Acl/Cron → L2 Frontend/Eav/Catalog→Product/Search/Widget/Taglib/内容族 → L3 Customer→Cart→Checkout→Payment/Order→营销/B2B/Vendor/Dropship→Ai 族。节点角标：`#N`=provides 接口数、`hN`=hooks 文件数、`evN/exN`=事件订阅/extends 装配数。

## 4. 耦合判定 v2（修正 v1 误判）

**收回的判定**：v1 把"平台层 requires 业务模块"当倒挂缺陷。v2 确认那是**设计内显式装配**：`Widget→Ai`（extends/module/Weline_Ai/ 标准布局）、`SessionManager→Customer`（hook 模板经 RuntimeProviderResolver 解析 Customer 注册的 provider）。requires 在此是集成登记，去掉反而让 registry 与真实装配脱节。

**修正后的问题清单**：

| # | 发现 | 置信 | 建议 |
|---|---|---|---|
| 1 | **幽灵 requires**：`UrlManager→ModuleManager` 全模块零代码引用（grep 0 命中） | high | 从 requires + register.php 删除 |
| 2 | **弱倒挂**：`I18n→Captcha`（仅 LanguageSupportRequestService 一处接口注入） | high | 降 optional 或改 RuntimeProviderResolver |
| 3 | **接口归属错位**：`Taglib→ModuleManager`（17 处但全走 ModuleCatalogInterface） | high | 接口下沉 Framework/Contract，属中期重构 |
| 4 | **58 对未声明机制边**：A 模块 extends/订阅 B 模块，但 module.php 既不 requires 也不 optional B。大户：SiteSetupAssistant←13、Widget←11 | high（存在性）/ medium（是否算缺陷） | 这是**机制故意允许的松耦合**（在场门禁兜底），但违反"声明即文档"纪律的话，可按 ComposerMetadataValidator 模式加一条静态检查：机制边必须至少出现在 optional 中 |
| 5 | 交易链扇出（Payment 11/Checkout 9/Order 8） | 降级为观察项 | Checkout→Cart/Order/Payment 等均有类级引用证据（如 CheckoutQueryProvider use Weline\Cart），是正常编排者角色，非过度耦合 |
| 6 | 零循环依赖（Tarjan SCC 全图 DAG） | high | 底线健康 |

## 5. 视图清单与阅读顺序

1. `weline-assembly-mechanisms.mmd` — 先读这张：四条装配通道+门禁如何工作（Mermaid，Markdown 原生可嵌）
2. `weline-core-overview.dot|svg` — 61 枢纽模块·边按机制着色（绿=event 紫=extends 蓝=纯类引用 灰点=optional）
3. `weline-module-dependencies-full.dot|svg` — 120 模块全量 + 58 条黄线未声明机制边（治理候选一眼可见）
4. 在线查看：`cd 架构可视化 && php -S 127.0.0.1:8931` → http://127.0.0.1:8931/

## 6. 假设与边界

- 分层 rank 仍是扇入归纳（medium），非官方定义；`Weline_Marketing_L2` 为生成器占位残留已忽略。
- hook 宿主归属未逐边解析（钩子名→宿主模板需要全模板扫描），图中以 hN 计数呈现。
- provides 消费方向（谁注入了谁的接口）未画边——需要全仓构造参数扫描，留作 flow 视图。
- optional 不参与排序意味着：**optional 目标的加载顺序无保证**，若某集成实际依赖时序，须走 event/hook 而非直接调用——本次未发现违例，但未全量验证。
