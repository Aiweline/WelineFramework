# URL rewrite cron submission

SEO URL submission is sourced from business change events first. The canonical `url_rewrite` table is still scanned as a fallback so missed public rewrites can be submitted later.

## Flow

1. `Weline\Seo\Cron\UrlRewriteSubmitSync` runs every 5 minutes.
2. `UrlRewriteSubmitSyncService` reads immutable public rows through `Weline\UrlManager\Api\Rewrite\UrlRewriteDirectoryInterface`, resolves the target website through `SeoWebsiteDirectory`, expands relative rewrites to absolute website URLs, and builds a route fingerprint from `rewrite_id`, source `website_id`, target website, `path`, `rewrite`, and final URL. Seo never injects the UrlManager ORM Model.
3. The service compares route fingerprints and URL fingerprints against existing `weline_seo_task` records with `task_type=push_urls`. Tasks created by `UrlSubmitService` are also considered, so the fallback cron does not duplicate URLs already queued by product/page save events.
4. Missing fingerprints are written as pending `push_urls` tasks only for SEO accounts bound to the resolved website through `weline_seo_website_account`.
5. `Weline\Seo\Cron\UrlPusher` also runs every 5 minutes and only consumes pending URL push tasks.

## Website resolution rules

- Explicit `url_rewrite.website_id = 0`: submit only for the system default website. Zero is a valid website ID, never a missing/global marker.
- Explicit positive `url_rewrite.website_id`: submit only for that business website.
- Only a legacy row whose website ID field is genuinely absent or `null` may expand a relative rewrite to every website with a base URL.
- Absolute rewrite URL: match the URL host to a website; when website ID is explicit, the matched website must be that exact ID. Unmatched hosts are skipped.
- The account-level switch `SeoAccount.enable_cron_push_urls`, website binding switch `SeoWebsiteAccount.enable_url_push`, and platform capability `supports_url_push` must all be enabled before a URL push task is created.
- Catalog-only platforms such as DuckDuckGo, Brave, Qwant, Ecosia, Startpage and similar discovery-only engines do not create URL push tasks. They rely on sitemap/robots discovery.

Business modules that already know the changed canonical URL should call `Weline\Seo\Service\UrlSubmitService::requestSubmit()` or dispatch `Weline_Seo::integration::url_submit_request`. The cron path is only a safety net.
