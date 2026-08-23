# Weline_Websites 模块文档

## 开发前先读

先完成 `prepare_project` 并调用 `resolve_task_context`，再按返回来源阅读：

1. `app/code/Weline/Websites/doc/default-website-and-request-detection.md`
2. `app/code/Weline/Websites/doc/store-saleschannel-scope.md`（Store/渠道/三段 Scope，商城内核 P1a）
3. `app/code/Weline/Websites/doc/WebsiteData类使用文档.md`
4. 涉及主题目标、建站工作台时，让同一 Guidance Bundle 同时检索 Theme 文档

## 模块定位

`Weline_Websites` 不只是“网站 CRUD 模块”。它同时承担：

- 网站主数据：网站、域名、语言、货币、时区、scope。
- 店铺与渠道：Website 之下的 Store（normal|dev|test）与 SalesChannel，Scope 三段主键的后两段。
- Store 商品复制：后台向导归 Websites 所有，业务动作只通过 Product 自有
  `product_copy` QueryProvider 执行；页面 ACL 是
  `Weline_Websites::store_copy_wizard`，不得在 Websites 内部直接引用
  Product Service/Model。
- 请求命中：Website 按“精确 Host > 单层 `www.` 别名”和最长完整路径边界选择，再由 `ScopeResolver` 以可信 Origin 一次解析并冻结 Store/Channel 三段和路由余量。
- Scope Worker 绑定：签发可轮换 keyring 保护的 Scope Token，按 `off|shadow|allowlist|on` 渐进切流，并在 WLS/FPM/FPC 最终响应面生成一次性页面 bootstrap。
- Scope 维护门禁：持久化 Website/Store/Channel 维护状态与 generation，使用现有 Scope Token keyring 签发只读、可撤销、跨 Worker 一致的预览令牌；详见 `scope-maintenance-preview.md`。
- 默认网站兜底：维护系统安装默认站点 `website_id=0 / code=default`。
- 域名注册与编排：注册商、DNS、证书、生命周期、域名池。
- AI 建站工作台支撑：Provider、草稿、产物、事件流与主题来源注册。

## 核心约定

- 系统默认网站固定是 `website_id=0`、`code=default`。这是有效站点，不是空值、未选择或异常 ID。
- `DefaultWebsiteService` 会在安装/修复链路里确保默认网站存在，并在必要时把历史 `default` 站点迁移回 ID `0`。任何站点逻辑都不能把 `0` 过滤掉。
- `Model/Website.php` 在删除前会强拦截 `0/default`，并拒绝物理删除仍有 Store/SalesChannel 引用的普通站；保存前会自动为 URL 补协议；保存后通过进程默认主连接在同一写事务内补种默认 Store/Channel 并登记 namespace generation，失败整体回滚，parser/process/WLS 副作用在物理提交后执行。不同逻辑数据库的 Model Hook 会在业务写前 fail-closed，不支持跨库缓存权威。
- 当前请求命中的网站由 `Observer/DetectWebsite.php` 负责解析。它会把结果写入 `RequestContext`、`ScopeContext` 和 `WebsiteData`。其他模块读取当前站点时，优先取 `WebsiteData`，不要自己重复匹配域名。
- 新建或修改 Website/Domain 后会推进
  `var/runtime/website-parser-sites.version`；WLS Worker 至多 1 秒复核一次，
  版本变化会同时清空 Framework URL parser 与 Websites 进程缓存。新 Host
  因此不再被旧 300 秒进程 TTL 持续判为 404。
- WLS 首页准备仅调用只读 Website 预解析，不安装 Store/Channel、不写 `WebsiteData` 或 Token。正式导航在 FPC 前只调用一次 `ScopeResolver` 并冻结结果。
- Website URL 与 WebsiteDomain 同 rank 命中不同站点返回 409；非法请求路径返回 400，被纳入匹配的非法 Website/Domain/Store 路径或站点引用配置返回 503，不做宽松回退。
- `DetectWebsite` 在 Website 命中后先用 Framework 统一本地化解析器移除货币/语言段，再冻结 Store/Channel；Store URL 前缀会被消费为真实 Router 余量。`__store/__channel` 只是与可信 Host/URI 的一致性断言，不能选择 Store。
- Scope Token 使用 `v1.<kid>.<payload>.<sig>` 和预置 keyring，精确绑定 Host、audience、三段 Scope、store mode 与 context version。请求路径不得临时生成密钥。
- Scope rollout 默认 `off`；`shadow` 只做服务端观察，`allowlist` 只让精确 dev/test 三元组取得权威，`on` 才全量权威。默认 Worker Session JSON 存储仍是单机/受控验证能力；Redis snapshot-CAS 也仅是 dev/test 共享语义探针，生产模式会拒绝启用。多节点 `on` 之前必须接入专用持久 credential store，并完成真实双节点一次消费与故障转移门禁。
- `WebsiteData` 是运行时站点事实来源。它把克隆的 Website 快照和派生缓存存在当前 `RequestContext`，按请求/Fiber 隔离；默认语言、默认货币、已关联语言/货币都应该从这里或其模型读取。
- 后台或 bootstrap 在安装完整 `WebsiteData` 快照前读取本地化信息时，`LocalizationProvider` 只在拥有真实 request id 的当前 `RequestContext` 内复用 language/currency 回退查询，并显式缓存空结果；非请求启动路径不建立进程级缓存。
- URL 本地化兼容货币/语言单段和两种双段顺序，canonical 固定为 `currency -> locale`；后台 area key 必须是 URL 第一段。
- Website 默认时区只写当前 `RequestContext`，不得修改 PHP 进程全局 timezone。`QueryBin` 成功响应的 `scope_meta` 只包含 Scope 身份、locale/currency/timezone 和 context version 等安全字段，不包含 Token、签名、bootstrap ID 或密钥。
- 跨模块与前端调用网站能力时，优先使用已发布的 `w_query('websites', ...)`，不要直接依赖内部服务类。
- 站点选择 Taglib：
  - `<w:websites:website:select>`：站点搜索单选/多选；`allow-empty` 可表示 Global。
  - `<w:websites:store:select>` / `<w:websites:channel:select>`：Store / Channel code 可搜索单选，选项由调用方传入 JSON；空值分别表示 Website 层 / Store 层。
  - 共用渲染器：`Taglib/SearchableCodeSelect.php`。
- 网站表单的语言与货币选项分别读取 I18n `LocaleRepositoryInterface` 和 Currency
  `CurrencyCatalogInterface` 的不可变 DTO；Controller 与模板不得引用对方 ORM Model/Query。
- `WebsiteData::getCurrencies()` 通过 `RuntimeProviderResolver` 获取 Currency Catalog，继续返回
  `code/name/format/symbol/position/rate/status` 数组；无站点限制时只允许全部启用货币，
  有限制时保持网站配置顺序，并继续过滤被禁用的货币。`isCurrencyAllowed()` 在有限制时仍只按
  配置代码判断，在无限制时按启用货币判断，不能把两种语义合并。
- 其他模块只需读取当前网站货币 `code/name` 时，使用
  `Weline\Websites\Api\Localization\WebsiteCurrencyCatalogInterface`；不要跨模块调用 `WebsiteData`。
- 其他模块给网站补关联语言时，只能调用 `Weline\Websites\Api\Localization\WebsiteLanguageAssignmentInterface::ensureAssigned()`：
  它是幂等的**只增不删**契约，用跨方言 upsert（冲突字段 `website_id + local_code`）写入缺失关联，
  不触碰调用方未提交的语言，也不清理其它语言；`website_id=0` 是合法目标。缓存清理登记到
  `TransactionCoordinator` 的 afterCommit，因此不会在业务事务提交前清共享缓存。调用方不得直接写
  `Model/WebsiteLanguage` 或先删后插来"同步"语言集合。
- 网站编辑表单通过 `Weline_Websites::backend::website::form::sections-after` 接收 I18n
  “用户申请语言”面板。只显示当前网站 `ready` 且尚未分配的 locale；逐项/全部加入都走
  `website_language_requests` QueryProvider，重新校验后台会话、`website_edit` ACL、对象 Scope 与
  `grant_version`，再在同一事务内调用 `ensureAssigned()` 并标记申请 `assigned`。
- `CollectCaptchaDomains` 通过 `Weline_Captcha::domains::collect` 发布 Website URL 与活动
  WebsiteDomain；依赖方向固定为 `Websites → Captcha` 事件，Captcha 不引用 Websites。
- `Taglib/BuildSite` 只能调用 `Weline\Component\Api\OffCanvasRendererInterface`；Component
  内部 renderer 负责实例化 OffCanvas Block 并保持 `__init() -> render()` 顺序，Websites
  不得再引用 Component Block 或其模板实现。
- 建站编排、域名购买、证书申请、DNS/CDN 切换都有专门服务和 QueryProvider，不要在控制器里重新拼一条“旁路流程”。
- Store 商品复制页面只能调用 `Weline.Api.resource('product_copy')`；
  `createDraft → preview → commit(request_hash)` 是强制顺序，页面不得用
  Ajax/XHR/fetch 直连 Controller。完整数据契约见
  `app/code/Weline/Product/doc/copy-guide.md`。

## Dependency Inventory

- Acl、Admin、Backend、Component、Currency、Cron、Framework、I18n 和 SystemConfig 是必需依赖：它们共同支撑站点后台、建站组件、语言/货币关联、任务与作用域配置。
- 域名池与建站配置后台接口继承 `Weline\Admin\Api\Controller\BaseController`，只使用
  Admin 发布的后台控制器契约，不跨模块引用 Admin 内部 Controller。
- Ai 和 Server 是可选集成：分别增加 AI 建站和 WLS 证书/本地域名能力，不得成为站点主数据的隐式必需项。
- 跨模块读站点信息必须使用 Websites Api/QueryProvider；不得因 Theme 的可选站点适配而形成 `Websites <-> Theme` 依赖环。
- 列表与计数使用 `Api\Catalog\WebsiteCatalogInterface`，其列表返回不可变 `WebsiteSummary`，不暴露 Website ORM。
- Website 后台的 Store/SalesChannel 嵌套目录由 `WebsiteStoreChannelDirectory` 通过两个 Catalog v1 只读组装；列表搜索使用普通 GET，目录不提供写入口，也不在读取时补种数据。两个 Query 操作固定为 `getStoreCatalogV1(website_id)` 与 `getSalesChannelCatalogV1(store_id)`，只接受唯一参数和 32 位有符号整数 ID；Catalog 在发布子级前会只读复核真实父级存在及 Website 归属。

## 典型开发流程

1. 做站点识别或读取当前站点信息时，先确认是不是应该接 `WebsiteData`。
2. 做站点表结构或站点保存逻辑时，先检查会不会影响默认网站 `0/default` 语义。
3. 做域名、证书、DNS/CDN 相关能力时，优先命中 `Query/WebsitesQueryProvider.php`、`ProvisioningQueryHandler`、对应服务层。
4. 做 AI 建站工作台或主题来源接入时，优先接 `Service/AiWorkbench/*` 与 `Api/*RegistryInterface*`，不要把工作台状态散落到临时表和模板里。

## 常见误区

- 把 `website_id=0` 当成“未选站点”过滤掉。
- 当前请求需要网站信息时，重新手写 host/path 匹配。
- 直接在控制器里调用多模块服务串域名生命周期，而不是走 `w_query('websites', ...)` 或已有编排服务。
- 修改站点 URL 后忘记考虑缓存清理和请求命中缓存刷新。

## 网站写入与 ResourceChange

`add`、`edit`、`quickSave` 和 `deleteDelete` 都使用 Framework `TransactionCoordinator`
包住同一主库写入。保存固定顺序是：

```text
Website 核心 -> Domain -> Currency -> Language -> 两个 start-page SystemConfig
-> website_save_after -> 完整 after 快照 -> revision -> w_changed(ResourceChange v1)
-> 物理提交 -> parser/process/namespace IPC 或混合版本 fallback
```

- `edit` 和 `deleteDelete` 在任何写入前读取 allowlist before 快照。
- Currency、Language、SystemConfig、SEO 或 Geo 的任何失败都会上抛并回滚整单；不再以 warning 当成保存成功。
- start-page SystemConfig 的版本审计 operation 必须符合 `SystemConfigVersion.operation` 的 32 字符上限；前台继承使用稳定码 `website_front_start_page_inherit`，不得以截断方式写入。
- 每个写入入口只生成一个 `website` ResourceChange；快照仅包含核心字段、域名、货币、语言和两个 start-page 配置，不包含密钥或完整请求。
- 删除时 `after=null`，before、previous namespace 和 previous URL 保持完整；`0/default` 仍在 Controller 和 Model 双层禁删。
- Website/Domain/Currency/Language 模型被其他入口单独保存时，也会在业务提交后开独立短事务推进 DB namespace，不会在 commit 前清共享缓存。

## 源码锚点

- `app/code/Weline/Websites/Model/Website.php`
- `app/code/Weline/Websites/Service/DefaultWebsiteService.php`
- `app/code/Weline/Websites/Observer/DetectWebsite.php`
- `app/code/Weline/Websites/Service/ScopeResolver.php`
- `app/code/Weline/Websites/Service/ScopeTokenService.php`
- `app/code/Weline/Websites/Service/ScopeTokenKeyring.php`
- `app/code/Weline/Websites/Service/ScopeKernelRolloutPolicy.php`
- `app/code/Weline/Websites/Service/FrontendWorkerScopeBootstrapResponseService.php`
- `app/code/Weline/Websites/Integration/Framework/FrontendWorkerScopeProvider.php`
- `app/code/Weline/Websites/Data/WebsiteData.php`
- `app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php`
- `app/code/Weline/Websites/Service/ProvisioningQueryHandler.php`
- `app/code/Weline/Websites/Service/WebsiteStoreChannelDirectory.php`
- `app/code/Weline/Websites/Controller/Admin/StoreCopy.php`
- `app/code/Weline/Websites/view/templates/Admin/StoreCopy/wizard.phtml`
