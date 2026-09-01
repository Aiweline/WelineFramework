# Weline_Blog

`Weline_Blog` 是 `/blog/*` 命名空间的 Owner 模块：结构化文章（`blog_post`）与 CMS `path_group=blog` 页面 URL 共存、存储分离，Search / SEO / Sitemap 统一经 `BlogArticle` 投影。

## 主要入口

- `BlogArticle` + `BlogContentResolver`：详情与列表唯一对外模型
- `ClaimBlogNamespaceUriBefore` + `CmsUriInterceptSkipInterface`：Blog 显式通知 CMS 勿拦截 `/blog/*`
- `BlogSeoProfileProvider` / `BlogSitemapUrlProvider` / `BlogSearchProvider`：SEO、Sitemap、Search 扩展
- 后台 `blog/backend/post/*`：S1 最小文章 CRUD

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
