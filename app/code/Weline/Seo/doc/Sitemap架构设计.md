# SEO 多语言 Sitemap 与后台管理架构

> 状态：当前实施设计（2026-07-23）
>
> 风险：`HIGH`
>
> 原始计划：`/Users/weline/.cursor/plans/seo多语言sitemap_1e2d5e8a.plan.md`
>
> 原始计划 SHA-256：`dfa4eb9c5ef0f80a419b6a60625bab4784ff15aa59621f5095bd41c2ad163f28`

## 1. 责任边界

`Weline_Seo` 是 Sitemap 的单一 owner：

- Provider 只返回 URL 快照，不写 SEO 表、不生成 XML。
- `SitemapUrlSyncService` 是 `weline_sitemap_url` 的唯一写入者。
- `AtomicSitemapPublisher` 是 canonical 和平台 Sitemap 文件的唯一发布者。
- SEO 账户只决定提交平台，不决定 canonical 能否生成。
- `/sitemap.xml` 只返回当前站点 canonical 索引，不聚合平台副本。

PageBuilder 等可选模块只依赖两个公开边界：

- `Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider`
- `Weline\Seo\Api\Url\UrlChangeNotifierInterface`

若业务模块拥有站点前台首页 `/`，额外实现：

- `Weline\Seo\Api\Sitemap\FrontendHomeOwnerInterface`

## 2. URL 数据身份

URL 持久化和内存身份统一为五元组：

```text
(website_id, scope, module, url_key, locale)
```

`SitemapUrl` 的 locale 字段为 `varchar(32) NOT NULL DEFAULT ''`：

- `locale=''` 是未声明语言维度的 legacy/default 桶，不等于某个站点默认语言。
- 非空 locale 必须解析为已安装且已启用的 I18n 规范代码。
- 新快照的 `url_key` trim 后必须非空；历史 nullable 字段不在本轮收紧。
- `lastmod` 缺失时写 `NULL`，XML 中省略；不得伪造为生成当天。
- `website_id=0 / code=default` 是合法的系统默认站。全部站点只由 `all_sites=true`、显式 ID 列表或 nullable 参数表达。

Provider URL 行：

```php
[
    'url_key' => 'page-123',
    'locale' => 'en_US', // 可省略，省略后规范为 ''
    'loc' => 'https://example.com/en_US/about',
    'lastmod' => '2026-07-23T10:00:00+08:00', // 可省略
    'changefreq' => 'monthly',
    'priority' => '0.8',
    'metadata' => ['alternates' => []],
]
```

## 3. Provider 快照同步

`SitemapUrlSyncService` 的单个 provider+website 处理顺序：

1. 以 `(module, scope, website_id)` 的 SHA-256 命名获取非阻塞跨进程文件锁。
2. 调用 Provider 获得整体快照。
3. 在任何写入之前完整规范化与校验。
4. 在同一连接事务中读 existing、upsert/恢复当前行、停用孤儿行。
5. 提交后释放锁。

以下任一情况使整个快照零写入：

- 非数组行、缺少 key/loc、字段超长、控制字符。
- locale 非法或未安装/未启用。
- 重复五元组、同 locale 重复规范 URL。
- 跨源 URL、非 HTTP(S)、credentials、fragment、省略协议或 URL 过长。
- 无效 `lastmod/changefreq/priority/metadata`。

空数组是合法权威快照，会停用该 provider+website 的所有可管理 active 行。历史 NULL/空
`url_key` 只进入人工清理报告，不猜测身份。

`action=refresh` 在 URL target 校验之前单独处理：按 module+website 去重同步，不建 URL Push 任务、
不生成 XML，结果显式返回 `retryable` 和 `generation_pending`。

## 4. Canonical 文件发布

每个站点始终可生成账户无关的 canonical 集合：

```text
pub/sitemaps/{website_code}/canonical/sitemap.xml
pub/sitemaps/{website_code}/canonical/sitemap_{provider}_{locale}_{sequence}_{hash}.xml
```

- 输入按 `module + scope + locale` 分桶，再按完整身份和规范 URL 稳定排序。
- 文件 token 是可读 slug + 原值 hash；空 locale 显示 `default`。
- shard 文件名包最终 XML bytes SHA-256，同样数据不产生新文件。
- 标准上限是每个 urlset/index 50,000 条、50 MiB 未压缩 XML；平台只能收紧。
- 预检发现跨 Provider 重复 canonical URL、非同源 URL 或任何越界数据时零发布。

发布顺序：

1. 持有 website+target 非阻塞锁，先根据 old/new index hash 恢复未完成 journal。
2. 读取旧 `sitemap.xml` 真实引用，物化完整 URL 快照。
3. 在临时目录写入并解析验证所有 shard 与候选 index。
4. 将不可变 shard 移入目标目录。
5. 以 temp+rename+flush 原子写 cleanup journal。
6. 最后原子替换固定 `sitemap.xml`。
7. 只清理“旧 index 曾引用且新 index 不再引用”的 shard，然后删 journal。

当前 index hash 既不等于 journal old hash 也不等于 new hash 时 fail-closed，不删除无法证明归属的文件。

## 5. 协议入口

`SitemapProtocolRenderer` 先读取 `canonical/sitemap.xml`，检查 XML、namespace、条目数、字节数和同源引用。

文件不存在时，只有全部 active DB URL 可在一个合法且未超限的 urlset 中完整输出才允许 fallback；
任何非法行、重复 loc 或超限都返回 HTTP 503，不截断为部分 Sitemap。

## 6. SEO 后台管理

后台业务写操作统一走后台认证 QueryProvider：

```javascript
const seo = await Weline.Api.resource('seo_admin');
```

ACL 按菜单 source 分组：

| 界面 | 操作 | source |
|---|---|---|
| Sitemap | `syncSitemapUrls` / `generateSitemaps` / `submitSitemaps` | `Weline_Seo::sitemap_management` |
| 账户 | `saveAccount` / `syncAccountStats` | `Weline_Seo::seo_account` |
| 站点绑定 | `saveWebsiteBindings` / `saveWebsiteConfig` / `unbindWebsite` | `Weline_Seo::website_account` |

QueryProvider 不调用 Controller；Controller 仅保留 GET 渲染和无脚本 fallback，业务逻辑在 `Service/Admin`。

Sitemap 首页是纯读取页，打开时不生成 XML。同步、生成、提交是三个独立动作，必须传
`website_ids` 或 `all_sites=true`；`website_ids=[0]` 仅表示默认站。

界面只消费 publisher manifest/read model，不根据文件名反解业务身份。站点卡片按真实发布关系展示
`当前站点 /sitemap.xml → canonical/平台索引 → locale + module + scope → XML shard` 树；主入口、目标索引和
每个 shard 都提供一键复制。主入口由站点配置 URL 构建；系统默认站若仍是 `http://localhost` /
`127.0.0.1` 占位，则改用当前请求解析出的项目入口（如 `https://p{hash}.weline.test:{port}`），
不得把任意后台代理 Host 覆盖到已配置真实域名的普通站点。
每层明确展示覆盖 URL 数、下级索引/分片数；每个 shard 独立展示 manifest locale、文件名、URL 数、字节数和内容更新时间，
空 locale 必须标记为“默认桶（locale 未声明）”，不得伪装成已知具体语言。
树同时声明 50,000 URL/50 MiB 标准上限，实际拆分和计数以 publisher manifest 为准。
数据库中已存在但当前 manifest 尚无 shard 的语言桶必须继续显示，并明确标记为“待生成语言文件”。
账户响应脱敏，凭据不回显；
“未绑定账户”显示为“可生成，尚未提交”。浏览器业务请求不得使用 fetch/XHR/axios。

`weline_sitemap_url.locale` 是五元组身份的一部分。若库表缺该列，`seo_admin.syncSitemapUrls` /
`generateSitemaps` 会写入失败；用迁移
`Setup/Db/Migration/add_sitemap_url_locale_20260724-v1.0.1.php` 幂等补齐列与
`idx_unique_url_key_locale`。后台前端 `seo-admin.js` 对 bin-query 业务失败会展开
`errors` / `error_messages`，避免只显示摘要或协议层误导信息。

## 7. PageBuilder 合作契约

- 只返回 `Page::STATUS_PUBLISHED` 页面。
- 同一页面所有语言使用 `url_key=page-{id}`，locale 单独返回。
- `Page.locales` 仅在 NULL/空字符串时生成 `locale=''` legacy 桶。
- 非空 locales 必须是合法、非空、不重复的规范列表；非默认语言必须存在当前前台可见 `LocalDescription`。
- 首页路由固定 `/`；其他页面必须有合法站内 handle/canonical route。
- 实现 `FrontendHomeOwnerInterface`：对 `pagebuilder-*` / `pagebuilder_*` 站点码，以及已发布 `TYPE_HOME` 页面所在站点声明拥有 `/`。
- `Weline_Index` HomePageProvider 不得再为已声明站点写入 `module=Weline_Index` 的首页行；定向同步 PageBuilder 时会顺带清理该回退首页。
- 使用 `LocalizedUrlBuilderInterface`，不手写语言/货币前缀。
- 已发布 `seo_profile` 的 noindex 语言不进入 Sitemap。
- 发布、下架、删除或语言就绪后发一次 `action=refresh`；失败记录可重试错误，不回滚页面发布。

## 8. 验证门禁

- schema 升级只在隔离数据库副本执行，验证 locale 默认值、五列 unique、历史 NULL key 和默认站。
- 验证同 key 多语言、legacy Provider、非法快照零写、删语言停用和锁冲突可重试。
- 验证无账户站点、默认站、普通站 canonical 生成；同数据重生成无 churn。
- 模拟 index 切换前后失败，确认 old/new 集合始终完整且 journal 可恢复。
- 检查 PHP 语法、`git diff --check`、浏览器请求扫描、`query:help seo_admin`、XML 与真实 HTTP 200/503 语义。
- 不新增或修改 test、fixture 或 E2E spec。

## 9. 参考协议

- [Sitemaps.org 协议](https://www.sitemaps.org/protocol.html)
- [Google Sitemap 构建说明](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap)
- [Google 大型 Sitemap 限制](https://developers.google.com/search/docs/crawling-indexing/sitemaps/large-sitemaps)

## 10. 当前实现兼容说明

- locale 有效性读取公开 `LocaleCatalogInterface` 的已安装启用目录，再通过
  `LocaleRepositoryInterface` 兼容别名；未知代码不会降级为默认语言。
- `SeoTransactionRunner` 优先使用当前框架事务协调器，并兼容站点仓仍在过渡期的旧事务上下文；
  两条路径都在同一数据库连接上执行完整快照事务。
- 历史数据库中的站内相对 URL 只在 publisher 读取旧数据时按站点 canonical origin 规范化；
  新 Provider 快照仍必须直接提供完整、同源的 HTTP(S) URL。
- 协议入口以当前请求解析 website 身份；普通站点仍以配置 URL 作为 canonical origin，
  避免测试端口或反向代理 Host 污染已配置真实域名。系统默认站若配置仍是 localhost
  占位，则公开 Sitemap/robots 地址改用当前请求解析出的项目入口。
- 新快照拒绝相对 `loc`；核心首页 Provider 已改为配置站点的绝对首页 URL，首页与 CMS Provider
  在没有真实更新时间时都传 `NULL`，不会继续沿用生成当天。
- 公开 Provider 基类保留无状态构造器，兼容旧 Provider 显式调用 `parent::__construct()`；QiPai
  检出的 `GuoLaiRen_Blog` 真实调用方通过旧 namespace 的无写入 shim 过渡，旧数据库 writer 未恢复。
- Sitemap、账户和站点绑定的浏览器写操作全部由静态 `seo-admin.js` 调用认证的
  `seo_admin` 资源；传统 Controller POST 只委托相同的 `Service/Admin` 服务。
- 后台「站点各域名 Sitemap 地址」通过 `SeoWebsiteDirectory::listPublicOrigins()` 枚举：
  先取 canonical `Website.url`（`effectivePublicBaseUrl()`：真实配置 URL 优先；默认站
  localhost 占位回退到当前项目 Host+端口），再合并该站全部活跃 `WebsiteDomain` 绑定；
  按 scheme+host+port+path 去重后，每个 origin 展示独立的 `{base}/sitemap.xml` 复制/打开入口。
  `robots.txt` 同步为每个公开 origin 输出一行 `Sitemap:`。已生成 manifest 中的 loopback
  索引/分片 URL 在后台展示时同样改写为当前项目入口，重新生成后会写入真实 origin。
  canonical 文件内容的同源校验仍只认站点配置 origin，不按别名域名拆分生成。
- `/sitemap.xml` 与 `/sitemaps/{code}/{target}/*.xml` 在协议输出时会把历史 localhost `<loc>`
  改写为当前项目 origin，再做同源校验；避免默认站占位 URL 导致 503 `<error>`。
- 生成物落在 `pub/sitemaps/**`，但 WLS 不得把 `/sitemaps/**`（以及根路径
  `sitemap.xml` / `robots.txt`）当静态文件直出：`StaticRequestBypassDecider` 必须把它们
  交给框架，走 `ProtocolRouteRewrite` → `SitemapProtocolRenderer`，否则磁盘里的
  localhost `<loc>` 会原样暴露给爬虫与验收请求。
- 若曾被静态直出并带上 `Cache-Control: public, max-age=604800`，托管 Nginx 边缘微缓存
  可能继续 HIT 旧 localhost 正文；修复旁路后需清空
  `var/server/nginx-*-runtime-*/cache`（或带 Cookie/`proxy_cache_bypass`）再验收。
- 后台语言桶读模型以 `weline_sitemap_url` 活跃行为准；旧 canonical manifest 中已无活跃
  URL 的 module/scope（例如被 FrontendHomeOwner 认领后的 `Weline_Index`）不再展示。
- 核心实现先落在框架仓，再逐文件合并到 QiPai；QiPai 自有 SEO 优化扩展和其它在途 WIP 保持不变。
