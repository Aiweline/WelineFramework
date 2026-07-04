# SitemapUrlProvider 扩展开发指南

`SitemapUrlProvider` 是模块向 SEO sitemap 表提供 URL 的唯一推荐入口。业务模块不直接写 `weline_sitemap_url`，只提供 Provider 和 URL 变更事件；SEO 模块负责增量同步、生成 sitemap 文件和平台提交。

Provider 不能只为后台展示服务。`seo_sitemap_submit` cron 会自动发现所有 `SitemapUrlProvider` 并调用 `getUrlsForWebsite()` 立即拉取 URL；保存、发布、删除事件也会定向同步对应模块和站点的 Provider。因此 Provider 必须始终能按站点返回当前有效 URL。

## Provider 放置位置

模块 Provider 放在：

```text
app/code/{Vendor}/{Module}/extends/module/Weline_Seo/SitemapUrlProvider/{Name}Provider.php
```

Provider 实现 `Weline\Seo\Interface\SitemapUrlProviderInterface`，推荐继承 `Weline\Seo\Provider\AbstractSitemapUrlProvider`。

## 返回数据契约

`getUrlsForWebsite(int $websiteId): array` 返回当前站点仍应收录的 URL。每条 URL 至少包含：

```php
[
    'url_key' => 'product-123',
    'loc' => '/product/example.html',
    'lastmod' => '2026-07-03',
    'changefreq' => 'daily',
    'priority' => '0.8',
    'metadata' => [
        'page_type' => 'product',
        'images' => [
            ['loc' => 'https://example.com/media/product.jpg', 'title' => 'Product image'],
        ],
        'alternates' => [
            ['hreflang' => 'en-US', 'href' => 'https://example.com/en/product/example.html'],
        ],
    ],
]
```

规则：

- `url_key` 必须在同一 `website_id + scope + module` 下稳定唯一。
- 同一个 `url_key` 可以出现在多个站点；slug/path 不是全局唯一键。
- 一个业务实体属于多个站点时，Provider 必须在每个站点调用中分别返回该实体 URL。
- `loc` 可以是站点内相对路径或同源绝对 URL。
- 商品、分类、文章等实体建议使用 `{type}-{id}`，例如 `product-123`。
- slug 型页面也必须提供稳定 key，例如 `blog-hello-world`。
- Provider 只返回当前有效 URL；下架、删除、noindex 页面不返回，SEO 同步会把旧记录标记为停用。

## 同步与生成

- 后台：`SEO管理 > Sitemap管理` 中点击“同步所有 Provider”。
- Cron：`seo_sitemap_submit` 会先自动发现并执行所有 Provider，再生成 sitemap 文件并提交给已绑定平台。
- 即时事件：`SeoUrlChangeService` 会按 target 的 `website_id` 调用对应模块 Provider 做定向同步。
- URL 表：数据写入 `weline_sitemap_url`，唯一键为 `website_id + scope + module + url_key`。

## 即时索引提交

推荐设计是：业务模块在保存、发布、下架、删除后直接调用 `SeoUrlChangeService`。这样业务模块不需要知道平台账号、URL Push 能力、本地域名规则、sitemap 增量同步或任务去重。

以 CMS 为例：

- `Weline_Cms` 保存页面后构建站点级 `targets`。
- `PageService` 直接调用 `SeoUrlChangeService::notify()`。
- SEO 统一完成 `url_changed` 通知、URL Push 任务创建、Provider 增量同步和本地域名标记。

其他模块接入建议：

1. 在 `extends/module/Weline_Seo/SitemapUrlProvider/` 写 Provider。Provider 只返回当前有效 URL；是否收录由 Provider 自己决定。
2. 保存、发布、下架、删除后调用 `SeoUrlChangeService::notify()`。
3. 一个实体属于多个站点时，传 `targets`，不要把多个站点折叠成一个 URL。

```php
$seoUrlChangeService->notify([
    'scope' => 'product',
    'module' => 'WeShop_Product',
    'sitemap_module' => 'WeShop_Product',
    'subject_type' => 'product',
    'subject_id' => $productId,
    'action' => 'upsert',
    'targets' => [
        [
            'website_id' => $websiteId,
            'url_key' => 'product-' . $productId,
            'url' => $canonicalUrl,
            'previous_url' => $oldCanonicalUrl,
        ],
    ],
]);
```

`SeoUrlChangeService` 会：

- 派发 `Weline_Seo::integration::url_changed`，让其他 SEO 应用也能接收。
- 标记本地域名、私网 IP、`.localhost`、`.local`、`.test` URL。
- 调用 `UrlSubmitService`，仅在站点绑定账号、开启 URL Push、平台支持即时索引时创建 `push_urls` 异步任务。
- 调用 `SitemapUrlSyncService` 定向同步对应模块和站点的 Provider，更新 `weline_sitemap_url`。

低层入口仍保留：如果模块已经非常明确只想提交 URL Push，可以直接调用：

```php
$urlSubmitService->requestSubmit('/product/example.html', 'product', [
    'website_id' => $websiteId,
    'module' => 'WeShop_Product',
    'subject_type' => 'product',
    'subject_id' => $productId,
    'action' => 'upsert',
]);
```

多站点实体也可以使用低层 target 入口：

```php
$urlSubmitService->requestTargets([
    ['website_id' => 1, 'url' => 'https://a.example.com/product/example.html', 'url_key' => 'product-123'],
    ['website_id' => 2, 'url' => 'https://b.example.com/product/example.html', 'url_key' => 'product-123'],
], 'product', [
    'module' => 'WeShop_Product',
    'subject_type' => 'product',
    'subject_id' => 123,
    'action' => 'upsert',
]);
```

或派发低层事件：

```php
$eventsManager->dispatch('Weline_Seo::integration::url_submit_request', [
    'url' => '/product/example.html',
    'scope' => 'product',
    'website_id' => $websiteId,
    'module' => 'WeShop_Product',
    'subject_type' => 'product',
    'subject_id' => $productId,
    'action' => 'upsert',
]);
```

低层提交只创建 `SeoTask::TASK_TYPE_PUSH_URLS`，实际平台 API 由 `seo_url_pusher` cron 异步处理。未绑定账号、未开启 URL Push、平台不支持即时接口时不会创建任务；这不是错误，表示事件已通知到 SEO，但没有可执行的平台处理者。
