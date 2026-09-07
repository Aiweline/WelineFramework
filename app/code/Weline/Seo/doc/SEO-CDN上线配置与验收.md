# SEO 与 CDN 上线配置和验收

## 变更到发布结果的流程

网站、商品及 SKU 变更通过已有 `ResourceChange v1` 通知消费者。商品模块提供当前与旧公开 URL；修改 slug 时旧地址与新地址分别处理，未改变的 URL 不重复登记为删除。

SEO 在本地任务表登记 URL 提交与 sitemap 刷新任务。现有 `seo_url_pusher` 每 5 分钟先消费 sitemap 刷新，再消费 URL 提交。没有搜索引擎账户时仍刷新 sitemap。网站、店铺和渠道变更刷新该网站各模块的 sitemap；商品变更只同步 Product 提供器。后台“生成”会先同步最新 Provider 数据。

CDN 接收同一变更通知，按网站的账户和域名绑定向服务商发送清理命令。店铺名称或域名修改会影响整店页面的 SEO Head，清理对应主机或子路径；商品修改清理已绑定域名下的实际新旧 URL。直接 URL 清理请求会报告未绑定的旧域名，已匹配的新域名仍执行清理。

当前框架实际事件派发器即时执行 CDN 观察者，可能处于业务保存事务内。既有 `delivery="async"` 元数据尚未被该派发链分流；不能据此承诺 Outbox 持久投递、事务外调用或异步重试，单独启用全局异步开关也不能补齐这段连接。本次保留现有执行方式。

网站 ID `0`、店铺 ID `0` 与渠道 ID `0` 都是合法默认作用域。店铺和渠道继承所在网站的搜索引擎账户；店铺名称/独立 URL 和当前作用域身份参与 Head 信息解析。渠道没有独立 URL 或 SEO 字段时继承店铺/网站，不能把渠道编码自行当作公开 URL。

## 上线前的公开地址

1. 网站配置填写实际可访问的 HTTPS URL，包含非标准端口和部署子路径。上线不能保留 `localhost` 或测试域名。
2. 域名绑定与网站一致。`www`、其他子域和不同端口分别按实际绑定处理。
3. 检查 `/robots.txt`、`/sitemap.xml` 和其中每个分片都返回正确内容；robots 声明规范 sitemap，不列开发用别名。
4. 已发布、店铺可见的商品进入 sitemap；草稿、下架及未选入店铺的商品不进入。dev/test 模式遵从既有禁止索引规则。
5. 当前 sitemap 发布契约按网站的规范 origin（协议、主机、端口）隔离。同源路径店铺可合入；独立域名店铺不会混进主站 sitemap，应配置对应独立 Website 来发布该域的 sitemap。独立域名商品变更 URL 仍提供给 API/CDN；IndexNow Key 文件也须能在各自实际主机访问。

## SEO 账户

后台入口：**SEO 管理 → SEO 账户**。选择平台、填写字段并保存，再在网站账户配置中启用 URL 自动提交和平台支持的 sitemap 提交。编辑账户时敏感字段留空保留旧凭证，页面不回显密钥。

| 平台 | 配置与验证 | 更新提交方式 |
| --- | --- | --- |
| IndexNow | Key；当前实际主机能公开读取同内容的 UTF-8 Key 文件；Key Location。需先部署公开 TXT，账户验证读取 Key 文件 | 页面新增、修改、删除使用 IndexNow URL API，按主机分组；最多 10,000 URL/批 |
| Bing | 网站验证属性及 API Key；读取提交配额验证 | URL API 最多 500/批；sitemap 使用 JSON `SubmitFeed` |
| 百度 | 资源平台已验证站点与推送 Token。没有无配额消耗的 Token 验证接口，配置检查不能代替真实推送 | 普通收录 API 最多 2,000 URL/批，核对接收数、额度和拒绝 URL；sitemap 在资源平台配置 |
| Google Search Console | 属性 URL（URL-prefix 或 `sc-domain:`）与有该属性权限的服务账户；验证属性可访问 | 通过 Search Console API 提交 sitemap；普通商品不使用 Indexing API |

HTTP 202 的 IndexNow 结果表示已接受、Key 验证待完成；不会当成“已收录”，也不会因此反复推送。批次部分接受会保留明细，重试不重复发送已确认接受的 URL。百度只返回接收数量而无法识别具体接受项时，保留待核对提示，避免整批重复消耗额度。

## CDN 账户和域名

后台入口：**CDN 管理 → 账户管理 → 域名管理**。先保存服务商账户，再绑定实际网站、公开域名及服务商的资源 ID。Cloudflare 填 API Token 与 Zone ID；Token 应具有目标 Zone 的 Cache Purge 权限。多个网站共用 Zone 时仍按各自网站的域名/URL匹配。

账户“测试连接”验证 Token 与目标 Zone 的读取结果；这不等于已验证清理权限。最终需要对一条属于该网站的公开 URL 执行清缓存，看到平台返回成功及请求 ID，并从线上请求观察内容已更新。编辑账户时密码/Token 留空保留原值。

## 当前验收记录（2026-09-05）

- 实际开发环境：[网站首页](https://p05113ef3.test.weline.com:9555/)。运行库有默认网站及两个既有测试网站；SEO 账户数 **0**、CDN 账户数 **0**。
- 修改前实际 `/robots.txt` 同时列出带端口主域、无端口主域、127.0.0.1 和 localhost；sitemap 仅首页，已发布商品未进入。对应回归已先复现。
- 已完成正常模块升级，退出码 0；原项目 Cron 已恢复并核对，WLS 已重载。未手改 generated。
- 真实 PostgreSQL 账户验收通过：SEO 后台服务保存、空密钥编辑保留、默认网站 0 绑定/解绑；CDN 发布 query 保存、空密钥编辑保留。临时禁用账户已清理，未调用外部平台。
- 真实 HTTP：首页和商品详情均只有一组 title、description、canonical；robots 只声明 `https://p05113ef3.test.weline.com:9555/sitemap.xml`。
- 真实商品事件 `59a035e3517f0da536a9cc774ecfe69c` 经既有 coordinator 发布，Product/Offer/EAV 哈希前后一致；登记的 `sitemap_refresh` 任务 1 已经真实 TaskProcessor 消费，2.362 秒达到 `done`，无外部账户也完成发布。
- 全站最终生成成功、错误数 0：共 **241 个 URL**（首页 1、商品 79、博客 161），4 个文件；后续刷新任务 2 为 `done`。通过真实 HTTPS 逐个读取 sitemap 分片，241 个 URL 全部使用正确规范 origin。
- Blog 原有相对地址及中英文共享路径已在 Provider 边界修复：同一公开 URL 只保留实际页面路由对应记录，保留稳定 `url_key`，不改变前端语言 URL。
- 冷写入性能：首次模型事件扫描反复加载模块声明，实测单条 SitemapUrl 保存耗时 412.168 秒；修复为同次扫描复用声明后，新进程同操作 2.890 秒。两次临时记录均清理。
- 定向回归：SEO 9 tests / 31 assertions；Product/Websites 6 tests / 114 assertions；三个 API 契约脚本、CDN 13 案例通过。真实 sitemap 生成及任务终态结果在开发日志追加。
- 当前没有真实平台凭证，尚未执行外部账户验证、搜索引擎提交或线上 CDN 清理，不能据本地测试宣称平台已接收。
- Chrome Weline 配置可只读识别，但页面控制超时；性能修复后的再次读取仍报告调试器未连接。后台点击验收须在浏览器控制恢复后补齐。

## 平台官方依据

- [IndexNow 协议](https://www.indexnow.org/documentation)
- [Bing Webmaster JSON API](https://learn.microsoft.com/en-us/bingwebmaster/)
- [百度普通收录推送](https://ziyuan.baidu.com/college/articleinfo?id=267&page=2)
- [Google Search Console sitemap 提交](https://developers.google.com/webmaster-tools/v1/sitemaps/submit)
- [Google Indexing API 适用范围](https://developers.google.com/search/apis/indexing-api/v3/using-api)
- [Cloudflare 缓存清理 API](https://developers.cloudflare.com/api/resources/cache/methods/purge/)
