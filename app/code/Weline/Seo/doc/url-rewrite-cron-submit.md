# URL rewrite cron submission

SEO URL submission is sourced from the canonical `url_rewrite` table, not from business save events.

## Flow

1. `Weline\Seo\Cron\UrlRewriteSubmitSync` runs every 5 minutes.
2. `UrlRewriteSubmitSyncService` reads public rows from `url_rewrite`, expands them to absolute website URLs, and builds a route fingerprint from `rewrite_id`, `website_id`, `path`, `rewrite`, and final URL.
3. The service compares those fingerprints against `weline_seo_task` records with `task_type=push_urls` and `subject_type=url_rewrite`.
4. Missing fingerprints are written as pending `push_urls` tasks for every active SEO account with URL cron push enabled.
5. `Weline\Seo\Cron\UrlPusher` also runs every 5 minutes and only consumes pending URL push tasks.

The old SEO event path is not registered in `Weline_Seo/etc/event.xml`; product/category save events no longer enqueue SEO URL push tasks directly.
