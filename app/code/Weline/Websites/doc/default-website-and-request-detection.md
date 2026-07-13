# 默认网站与请求命中约定

## 1. 默认网站不是空站点

默认网站固定语义：

- `website_id = 0`
- `code = default`

这里的 `0` 是合法主键，不是“无值”。所有涉及网站作用域、主题目标、URL 解析、CMS、SEO、Visitor、配置作用域的逻辑，都必须把它当有效站点参与查询和保存。

## 2. 默认网站维护入口

默认网站维护由：

- `app/code/Weline/Websites/Service/DefaultWebsiteService.php`

统一负责。它会：

- 确保 `default` 站点存在。
- 在历史数据错位时把 `default` 站点迁回 `website_id=0`。
- 同步默认货币、语言、本地域名。
- 变更后清理网站缓存。

所以开发时不要：

- 额外造一个“系统站点初始化器”。
- 在业务代码里手改默认网站 ID。

## 3. 请求命中入口

请求命中在：

- `app/code/Weline/Websites/Observer/DetectWebsite.php`

它会根据：

- 网站 URL
- 绑定域名
- host/path
- 本地域名保留规则

解析当前请求命中的网站，并把结果写到：

- `RequestContext`
- `ScopeContext`
- `WebsiteData`

本地 WLS 的标准项目入口 `p<8位十六进制>.(weline.test|local.test|weline.localhost)` 会绑定系统默认网站 `0/default`，并保留当前 HTTP/HTTPS、Host 与非默认端口。该规则是严格 Host 契约：`www.p...`、错误长度、其他后缀和非 HTTP(S) URL 都不会触发默认站点映射。

请求级命中缓存会忽略不参与站点选择的 query/fragment；标准项目 Host 的所有 path 共用同一站点身份。普通绑定域名仍保留 path 以支持 `sub_path`，但 WLS 进程缓存有固定 256 项上限，随机 URI 不得让常驻内存线性增长。站点保存或域名变更后仍须通过现有清理/epoch 链路使缓存失效。

## 4. 读取当前站点

其他模块要读当前站点时，优先用：

- `WebsiteData::getWebsite()`
- `WebsiteData::getWebsiteId()`
- `WebsiteData::getCode()`
- `WebsiteData::getDefaultCurrency()`
- `WebsiteData::getDefaultLanguage()`

不要每个模块都重复跑一遍域名识别。

## 5. QueryProvider 入口

跨模块调用网站和域名能力，优先查：

- `php bin/w query:help websites`
- `app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php`

尤其是：

- 域名购买
- 注册商账号管理
- DNS 修改
- 编排状态
- 域名池

这些都不应该散落成控制器里的私有流程。

只读部署工具若只需要默认/首个有效网站 URL，使用 `Weline\Websites\Api\DefaultWebsiteUrl::resolve()`；该 Api 会正确保留合法的 `website_id=0`，调用方不得跨模块读取 Website Model。
