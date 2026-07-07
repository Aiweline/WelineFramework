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
- 建站编排、域名购买、证书申请、DNS/CDN 切换都有专门服务和 QueryProvider，不要在控制器里重新拼一条“旁路流程”。

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
