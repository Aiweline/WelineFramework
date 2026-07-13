# Weline_Websites 模块文档

## 开发前先读

1. `app/code/Weline/Websites/doc/AI-INDEX.md`
2. `app/code/Weline/Websites/doc/default-website-and-request-detection.md`
3. `app/code/Weline/Websites/doc/WebsiteData类使用文档.md`
4. 涉及主题目标、建站工作台时，同时读 `app/code/Weline/Theme/doc/AI-INDEX.md`

## 模块定位

`Weline_Websites` 不只是“网站 CRUD 模块”。它同时承担：

- 网站主数据：网站、域名、语言、货币、时区、scope。
- 请求命中：根据 URL/域名把当前请求绑定到某个网站。
- 默认网站兜底：维护系统安装默认站点 `website_id=0 / code=default`。
- 域名注册与编排：注册商、DNS、证书、生命周期、域名池。
- AI 建站工作台支撑：Provider、草稿、产物、事件流与主题来源注册。

## 核心约定

- 系统默认网站固定是 `website_id=0`、`code=default`。这是有效站点，不是空值、未选择或异常 ID。
- `DefaultWebsiteService` 会在安装/修复链路里确保默认网站存在，并在必要时把历史 `default` 站点迁移回 ID `0`。任何站点逻辑都不能把 `0` 过滤掉。
- `Model/Website.php` 在删除前会强拦截删除默认网站；保存前会自动为 URL 补协议；保存后会清网站缓存、提升解析版本并清理进程内命中缓存。
- 当前请求命中的网站由 `Observer/DetectWebsite.php` 负责解析。它会把结果写入 `RequestContext`、`ScopeContext` 和 `WebsiteData`。其他模块读取当前站点时，优先取 `WebsiteData`，不要自己重复匹配域名。
- `WebsiteData` 是运行时站点事实来源。默认语言、默认货币、已关联语言/货币都应该从这里或其模型读取。
- 跨模块与前端调用网站能力时，优先使用已发布的 `w_query('websites', ...)`，不要直接依赖内部服务类。
- 网站表单的语言与货币选项分别读取 I18n `LocaleRepositoryInterface` 和 Currency
  `CurrencyCatalogInterface` 的不可变 DTO；Controller 与模板不得引用对方 ORM Model/Query。
- `WebsiteData::getCurrencies()` 通过 `RuntimeProviderResolver` 获取 Currency Catalog，继续返回
  `code/name/format/symbol/position/rate/status` 数组；无站点限制时只允许全部启用货币，
  有限制时保持网站配置顺序，并继续过滤被禁用的货币。`isCurrencyAllowed()` 在有限制时仍只按
  配置代码判断，在无限制时按启用货币判断，不能把两种语义合并。
- 其他模块只需读取当前网站货币 `code/name` 时，使用
  `Weline\Websites\Api\Localization\WebsiteCurrencyCatalogInterface`；不要跨模块调用 `WebsiteData`。
- `Taglib/BuildSite` 只能调用 `Weline\Component\Api\OffCanvasRendererInterface`；Component
  内部 renderer 负责实例化 OffCanvas Block 并保持 `__init() -> render()` 顺序，Websites
  不得再引用 Component Block 或其模板实现。
- 建站编排、域名购买、证书申请、DNS/CDN 切换都有专门服务和 QueryProvider，不要在控制器里重新拼一条“旁路流程”。

## Dependency Inventory

- Acl、Admin、Backend、Component、Currency、Cron、Framework、I18n 和 SystemConfig 是必需依赖：它们共同支撑站点后台、建站组件、语言/货币关联、任务与作用域配置。
- 域名池与建站配置后台接口继承 `Weline\Admin\Api\Controller\BaseController`，只使用
  Admin 发布的后台控制器契约，不跨模块引用 Admin 内部 Controller。
- Ai 和 Server 是可选集成：分别增加 AI 建站和 WLS 证书/本地域名能力，不得成为站点主数据的隐式必需项。
- 跨模块读站点信息必须使用 Websites Api/QueryProvider；不得因 Theme 的可选站点适配而形成 `Websites <-> Theme` 依赖环。
- 列表与计数使用 `Api\Catalog\WebsiteCatalogInterface`，其列表返回不可变 `WebsiteSummary`，不暴露 Website ORM。

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

## 源码锚点

- `app/code/Weline/Websites/Model/Website.php`
- `app/code/Weline/Websites/Service/DefaultWebsiteService.php`
- `app/code/Weline/Websites/Observer/DetectWebsite.php`
- `app/code/Weline/Websites/Data/WebsiteData.php`
- `app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php`
- `app/code/Weline/Websites/Service/ProvisioningQueryHandler.php`
