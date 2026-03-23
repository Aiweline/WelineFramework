# Task: pagebuilder blog publish cache

- Task ID: 2026-03-23-1059-pagebuilder-blog-publish-cache
- Started: 2026-03-23 10:59
- Status: completed
- Owner: Codex
- Source: codex: user report for GuoLaiRen PageBuilder/Blog publish/cache/E2E

## Goal

- 修复 PageBuilder 站点下博客文章“已发布但站点博客页不显示”的问题。
- 确保博客新增/编辑/发布/删除后会清理对应站点前台缓存，并尝试清理 CDN。
- 通过真实页面 smoke / 浏览器验证闭环，且不留下测试脏数据。

## Scope

- In scope:
- `GuoLaiRen_Blog` 后台发布链路、AI 发文链路的站点缓存/CDN 失效。
- `GuoLaiRen_PageBuilder` 各 style `blog-list.phtml` 对 runtime `blog_posts` 的消费逻辑。
- 真实站点 `/blog` 页面验证与临时数据清理。
- Out of scope:
- 新增完整后台登录态 E2E 用例。
- 重构 Blog Query Provider 的数据库缓存机制。

## Constraints

- 仓库工作树很脏，只改本任务相关文件。
- 手工编辑必须使用 `apply_patch`。
- 用户允许“全站缓存清理也行”，并要求 CDN 一并处理。

## Related Plans

- None yet.

## Related Files

- `app/code/GuoLaiRen/Blog/Service/BlogSiteCacheInvalidator.php`
- `app/code/GuoLaiRen/Blog/Controller/Backend/Post.php`
- `app/code/GuoLaiRen/Blog/Cron/AiPublish.php`
- `app/code/GuoLaiRen/Blog/test/Unit/Service/BlogSiteCacheInvalidatorTest.php`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/ludo-empire/components/content/blog-list.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/rummy-royal/components/content/blog-list.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/poker-arena/components/content/blog-list.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
