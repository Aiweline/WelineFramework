# Result - pagebuilder blog publish cache

## Outcome

- Completed.
- Fixed the real user-facing display bug: PageBuilder style blog-list templates were not consistently consuming runtime `blog_posts`, so published site-bound blog articles could be absent from the website even though Blog data and page routing were correct.
- Added blog publish/edit/delete/batch/AI-cron cache invalidation so site blog/page/router cache is cleared and CDN purge is attempted whenever published blog content changes.
- Confirmed the AI keyword behavior requested by the user:
- Trend-source mode tries to avoid reusing previously used keywords.
- Fallback profile-keyword mode can reuse keywords after the available keyword pool is exhausted.

## Changed Files

- `app/code/GuoLaiRen/Blog/Service/BlogSiteCacheInvalidator.php`
- `app/code/GuoLaiRen/Blog/Controller/Backend/Post.php`
- `app/code/GuoLaiRen/Blog/Cron/AiPublish.php`
- `app/code/GuoLaiRen/Blog/test/Unit/Service/BlogSiteCacheInvalidatorTest.php`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/ludo-empire/components/content/blog-list.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/rummy-royal/components/content/blog-list.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/style/poker-arena/components/content/blog-list.phtml`

## Verification

- `php -l app/code/GuoLaiRen/PageBuilder/view/templates/style/ludo-empire/components/content/blog-list.phtml`
  Result: passed.
- `php -l app/code/GuoLaiRen/PageBuilder/view/templates/style/rummy-royal/components/content/blog-list.phtml`
  Result: passed.
- `php -l app/code/GuoLaiRen/PageBuilder/view/templates/style/poker-arena/components/content/blog-list.phtml`
  Result: passed.
- `php vendor/bin/phpunit --colors=never app/code/GuoLaiRen/Blog/test/Unit/Service/BlogSiteCacheInvalidatorTest.php`
  Result: 2 tests / 18 assertions passed; PHPUnit exit code non-zero only because of the environment warning `No code coverage driver available`.
- `php vendor/bin/phpunit --colors=never app/code/GuoLaiRen/PageBuilder/test/Unit/Helper/PageBuilderUrlCacheInvalidatorTest.php`
  Result: 2 tests / 12 assertions passed; same existing PHPUnit warning about missing coverage driver.
- `http://127.0.0.1:18081/blog` live smoke
  Result: after creating a temporary published post for `site_id=1` and calling `BlogSiteCacheInvalidator->invalidateSiteIds([1])`, the page returned `200`, `Website-Id: 1`, and rendered a clickable card for `Codex Cache Smoke 20260323202032`.
- Playwright browser smoke on `http://127.0.0.1:18081/blog`
  Result: snapshot showed the article card and link `/blog/codex-cache-smoke-20260323202032` in the live DOM.
- Cleanup verification
  Result: after deleting the temporary post and invalidating site 1 again, the page rendered the empty-state message and PostgreSQL confirmed `select count(*) from m_guolairen_blog_post where status=1 and site_id=1;` => `0`.

## Remaining Risks

- No authenticated backend browser-flow E2E was added in this task; backend publish/edit/delete path coverage relies on focused unit tests plus live cache/display smoke.
- The framework database cache can return stale CLI SELECT results immediately after writes/deletes; for final truth we relied on the live page response plus direct PostgreSQL queries.

## Next Resume Step

- If the user wants deeper regression protection, add authenticated backend E2E around blog publish/remove flows and a small integration test that renders `blog-list` with assigned `blog_posts`.
