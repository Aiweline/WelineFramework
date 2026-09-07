# Weline_Blog

`Weline_Blog` 是 `/blog/*` 命名空间的 Owner 模块：结构化文章（`blog_post`）与 CMS `path_group=blog` 页面 URL 共存、存储分离，Search / SEO / Sitemap 统一经 `BlogArticle` 投影。

## 主要入口

- `BlogArticle` + `BlogContentResolver`：详情与列表唯一对外模型
- `ClaimBlogNamespaceUriBefore` + `CmsUriInterceptSkipInterface`：Blog 显式通知 CMS 勿拦截 `/blog/*`
- `BlogSeoProfileProvider` / `BlogSitemapUrlProvider` / `BlogSearchProvider`：SEO、Sitemap、Search 扩展
- 后台 `blog/backend/post/*`：S1 最小文章 CRUD

Sitemap 提供器按目标 `website_id` 的规范 URL 输出完整 `loc`，保留端口与部署子路径；默认网站 `0` 有效。`BlogArticle.publicUrl` 仍是前端相对路径，仅在 Sitemap 边界补基址，不使用当前其他网站的请求 origin。多语言记录共享同一公开地址且没有独立 Sitemap locale 时，只保留现有 `resolveBySlug` 实际路由对应的文章和原 `url_key`；Post 优先于 CMS，不生成新的语言 URL。定向回归：`php app/code/Weline/Blog/Test/Regression/BlogSitemapOriginRegression.script.php`；运行同步与文件生成验收见 [SEO 开发日志](../../Seo/doc/开发日志.md)。

## 硬边界

- **`GET /blog`**：Blog 列表（`blog_list`），不支持 CMS 独占 exact `/blog` landing
- **`GET /blog/{slug}`**：Blog 主权解析；`cms_page` 由 Blog 内部转发 CMS 渲染链
- **slug 冲突**：同一 `website_id` 下 `blog_post.slug` 与 CMS `identifier=blog/{slug}` fail-closed
- Blog **禁止**直接依赖 `Weline_Cms\Model`；使用 `w_query('cms', ...)`

## 文档

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [需求.md](需求.md)
- [功能现状.md](功能现状.md)
- [开发日志.md](开发日志.md)
