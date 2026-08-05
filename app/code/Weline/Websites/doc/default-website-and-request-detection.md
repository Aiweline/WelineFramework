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
- 默认站语言至少包含 `zh_Hans_CN` 与 `en_US`，供官方首页（`Weline_Index`）语言切换器使用；默认语言仍为 `zh_Hans_CN`。
- 默认站点服务中的原始 SQL 必须使用模型解析后的 `Website::getTable()` 物理表名；`getOriginTableName()` 不含项目表前缀，不能直接用于 SQL。
- `Website::save()` 将显式的 `website_id=0` 声明为 ORM 持久身份；编辑走 UPDATE，保存后模型 ID 仍为 0，不会把 affected-row 数误当成新主键。
- 变更使用同一主库事务，推进 `website/default` 与
  `global/websites-registry` namespace generation；parser/process/WLS 副作用只在物理提交后执行。

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

请求级命中缓存会忽略不参与站点选择的 query/fragment；标准项目 Host 的所有 path 共用同一站点身份。普通绑定域名仍保留 path 以支持 `sub_path`，但 WLS 进程缓存有固定 256 项上限，随机 URI 不得让常驻内存线性增长。站点保存或域名变更后由 `global/websites-registry` namespace 使共享命中数据失效，提交后再更新 Url parser 版本并清理当前进程快照。漏掉 IPC 时，后续请求仍以 DB generation/@clock 为正确性权威。

### 3.1 Website 命中优先级

Website 候选同时来自 `Website.url` 和启用的 `WebsiteDomain` 绑定，按统一规则决定：

1. 精确 Host 高于单层 `www.` 别名；别名只允许在最前面增删一个 `www.`，不做递归或任意子域归一化。标准 `p<8位十六进制>` 项目 Host 不接受 `www.` 别名。
2. Host 级别相同时，选择最长的规范路径。路径必须命中完整段边界：`/shop` 可命中 `/shop` 和 `/shop/catalog`，不能命中 `/shopping`。
3. `Website.url` 与 `WebsiteDomain` 也参与同一排名。如果同一 Host 精确度、同一路径长度命中了不同 Website，请求以 HTTP `409` 拒绝，不依赖数据库返回顺序偷选一个。

规范化还是失败边界：请求路径含重复分隔符、点段、非法百分号编码、编码后的分隔符或控制字节时返回 HTTP `400`；被纳入匹配的持久化 Website/Domain/Store URL 路径或站点引用出现非法配置时返回 HTTP `503`。这两类错误不得通过截断、宽松解码或回退其他站点继续请求。

### 3.2 只读预解析与导航 Scope 冻结

- WLS 首页路由准备阶段通过 `StorefrontWebsiteContextResolverInterface` 只读解析 Website。解析器只查询并返回不可变 Website 上下文，不安装 Store/Channel、不写 `WebsiteData`、不签发 Token，也不修改进程全局时区。
- 真正导航在 Framework `App` 进入 FPC 查找前调用 `StorefrontScopeInstallerInterface`：Website、Store、Channel 和规范路由余量在同一次安装中完成。已冻结身份只允许重入读取，任何不同身份的二次改写都必须拒绝。
- Website 默认时区只写入当前 `RequestContext`。代码不得为站点请求调用 `date_default_timezone_set()` 或修改其他进程级时区状态，避免 WLS 长生命 Worker 串请求。

## 3.1 仅本地化前缀的首页根

前台路径若剥掉货币/语言段后剩余为空（例如 `/`、`/en_US`、`/USD/zh_Hans_CN`，或带站点路径前缀的 `/{websitePrefix}/en_US`），与空路径同属首页根：

- `Weline\Framework\Runtime\WlsRuntime` 的 `isRootRequestUri` / `isWebsiteRootRequestUri` 会触发 start-page 映射，并尽量保留当前 URI 上的本地化前缀。
- `Weline\Framework\Router\Core::isFrontendRootRequest()` 同样按「无 area + remaining 为空」判定，避免语言切换器生成的 `/en_US` 冷路由 404。

## 4. 读取当前站点

其他模块要读当前站点时，优先用：

- `WebsiteData::getWebsite()`
- `WebsiteData::getWebsiteId()`
- `WebsiteData::getCode()`
- `WebsiteData::getDefaultCurrency()`
- `WebsiteData::getDefaultLanguage()`

不要每个模块都重复跑一遍域名识别。`WebsiteData::setWebsite()` 会克隆已命中模型，并把站点快照及语言/货币缓存存入当前 `RequestContext`；请求或 Fiber 切换时与 Scope 字段一起清理，不使用进程级静态站点快照。

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
