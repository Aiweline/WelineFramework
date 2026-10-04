# 挂载入口的原生 XML Sitemap

`/sitemap.xml` 按已解析 Website code 读取其 canonical 文件，文件中的正式域名可能与当前 WebsiteDomain 挂载入口不同。原先只调用 `rewriteLoopbackOriginsInXml()`，正式域名不会转换；例如正式 `https://daocharms.com/sitemaps/...` 对应本机 `https://p05113ef3.test.weline.com/daocharms` 时，原生同源校验拒绝并返回 XML 503。

`SitemapProtocolRenderer::readCanonicalSitemap()` 与既有 `renderFile()` 同样复用 `SeoWebsiteDirectory::rewriteAllOriginsInXml()`，把当前 Website 文件的 loc / alternate href 映射到真实 serve base，再保留原 XML 结构、数量、大小和同源校验。正式配置 URL 和磁盘 canonical 文件不变；挂载与正式域名都继续由原生 resolver 决定。

最小验证：挂载 `/robots.txt` 的 Sitemap URL 可访问；该 `/sitemap.xml` 返回 HTTP 200 application/xml，根为 sitemapindex，全部 loc 归当前挂载；按索引已有产品分片 loc 请求，分片 HTTP 200 urlset，商品 loc 仍归同一挂载。禁止伪造 XML、放宽同源校验或在主题重复生成 Schema。

XML 分片的静态协议入口可能没有完整商城页面上下文。分片文件已经由 `websiteCode` 指定所属网站，renderer 使用 SeoWebsiteDirectory 的公开 Websites Query 目录选出真实 owner，再由其有效 WebsiteDomain public origins 与原始 Host/路径作最长路径边界匹配，保留挂载路径。匹配复用 directory 的 urlParts/pathOwnsUrl；不根据硬编码商店名称拼路径，不改请求 Scope，也不绕同源校验。非该网站有效挂载时保留原 serveBaseUrl 行为。

主索引和数据库 fallback 的 `serveBaseUrl()` 同样优先调用 `requestPublicBaseUrlForWebsite()`；不能只在分片调用。仅依赖 currentBaseUrl 的当前上下文可退成裸 Host，导致匿名 sitemap index 的 loc 丢挂载。原始请求优先 WELINE_ORIGIN_REQUEST_URI，再 ORIGIN_REQUEST_URI，再 REQUEST_URI；选择真实 WebsiteDomain 边界匹配结果，未匹配时才保留既有配置 fallback。匿名验证必须显式无 Cookie 请求同一 HTTPS443 字面 URL，并继续访问索引中的实际分片链接。
