# Progress - pagebuilder blog publish cache

- 2026-03-23 10:59 Created the task workspace.
- 2026-03-23 20:20 Confirmed the real frontend bug was not only publish/cache: the active `ludo-empire` PageBuilder `blog-list.phtml` was hardcoded with demo posts and ignored runtime `blog_posts`.
- 2026-03-23 20:25 Confirmed `PageRenderService` already assigns `blog_posts`, `blog_categories`, and `recent_posts`; the render pipeline had data, but the style component did not consume it.
- 2026-03-23 20:30 Recorded and kept the earlier cache-chain implementation: backend blog create/edit/publish/delete/batch flows plus AI cron now call `BlogSiteCacheInvalidator`, which clears app/router cache and purges enabled CDN domains for affected site IDs.
- 2026-03-23 20:40 Reworked `ludo-empire`, `rummy-royal`, and `poker-arena` blog-list templates so live pages prefer runtime `blog_posts`, preview/editor mode can still fall back to sample posts, and empty live sites now render an explicit empty state instead of fake demo content.
- 2026-03-23 20:48 Syntax validation passed for all three touched PageBuilder templates.
- 2026-03-23 20:50 Live smoke verification on `http://127.0.0.1:18081/blog` confirmed the temporary published post `Codex Cache Smoke 20260323202032` became visible after cache invalidation.
- 2026-03-23 20:53 Browser-level Playwright smoke confirmed the article card was rendered as a clickable link on the live page.
- 2026-03-23 20:55 Deleted temporary post data, invalidated site 1 again, and confirmed the page returned to the empty-state view.
- 2026-03-23 20:56 Direct PostgreSQL verification confirmed site 1 has `0` published posts and the temporary smoke title no longer exists.
- 2026-03-23 20:57 Re-ran focused PHPUnit for `BlogSiteCacheInvalidatorTest` and `PageBuilderUrlCacheInvalidatorTest`; assertions passed, raw exit code remained non-zero only because PHPUnit reports the existing “No code coverage driver available” warning.
