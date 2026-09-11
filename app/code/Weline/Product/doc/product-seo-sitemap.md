# 商品 Sitemap 与 URL 变更

`extends/module/Weline_Seo/SitemapUrlProvider/ProductSitemapUrlProvider.php` 实现
`Weline\Seo\Interface\SitemapUrlProviderInterface`，scope 为 `product`，module 为
`Weline_Product`。SEO 的 Provider 发现、定向同步和 cron 均可取得已发布商品页。

`Service/ProductSitemapUrlService` 使用 Product/Offer 发布状态与 StoreProduct /
StoreOffer 选品判断当前公开 URL，站点与店铺来源经公开 Catalog Interface 读取。
Store 的独立入口优先，否则继承 Website URL；保留配置的协议、端口和路径。
仅启用且 active 的 normal Store 参与公开 URL；dev/test Store 的 SEO Head 已有
强制 noindex 规则，因此不进入 sitemap 或搜索提交 URL 集合。
地址与前台详情一致，读取 Store → Website 覆盖后的 `source_slug`，再取 `slug`，
没有合法 slug 时使用 `/product/{id}`。多个 Offer 对应同一商品页，重复地址只输出一次。

SEO 发布器当前要求一个 Website sitemap 内的所有 URL 与 Website canonical URL
协议、主机和有效端口一致。商品 Provider 因而只收录该同源范围，包括同源路径店铺；
独立域名 Store 仍出现在商品变更 `impact` 中供 API/CDN 使用，但不混入主站 sitemap。
独立域名若需独立 sitemap，须按现有单源发布契约归属独立 Website；此实现不声称
同一 Website 的跨域 Store sitemap 已支持。公开运行应配置实际 `Website.url`；
Product 读取配置值，不从其他网站或后台请求推断地址。localhost 占位的 sitemap
发布改写由 SEO 统一处理，事件地址的实时环境验收仍需主任务确认。

商品修改复用 `product_search_projection` ResourceChange，维持现有
`product.search_projection_changed.v1`、`target_type`、`target_id` 与事件水位：

- `impact.namespaces`：`website/{code}/catalog`，供 Framework `cache_namespace` bump，使店面详情页 FPC 键代次 miss。
- `impact.urls`：修改完成后的公开绝对 URL；商品或店铺选品下架时可为空。
- `impact.previous_urls`：修改开始前的公开绝对 URL，供旧 slug 清理/删除提交。
- `after.store_ids`：公开 URL 快照对应的店铺 ID；Store mutation 继续保留
  `after.store_id` 与 `scope_kind=store`。Website/Store ID `0` 合法。
- 后台保存由同一 coordinator 包住 EAV、选品与 Product 行更新，内层同商品
  Repository mutation 复用外层事件，旧 slug 在写属性之前捕获。
- 事务 `afterCommit`：`StorefrontCatalogCacheCoordinator::notifyCatalogChanged` +
  `ProductStorefrontCacheInvalidator` 清本地进程 FPC/router/WLS；CDN/SEO 仍由
  `resource_changed` 接收方处理，禁止控制器手写 CDN purge。

不新增跨模块直连或另一套商品通知。SEO/CDN 各自监听现有事件执行衍生动作。

开发验证：`ProductSearchProjectionServiceIntegrationTest` 使用临时 SQLite
验证发布、canonical slug、改名、Store 选品与下架；`ProductPublicUrlChangeTest`
验证完整保存的单次事件与前后 URL。真实 Provider 发现、sitemap 刷新及后台保存
验收由实际 WLS 环境执行，不能用这些测试替代。
