# SEO URL Changed

`Weline_Seo::integration::url_changed` is the high-level URL change notification used before SEO decides what to do.

Business modules should keep their own mental model small:

1. Provide a `SitemapUrlProvider` when the entity should appear in sitemap.
2. Call `SeoUrlChangeService` after the entity changes.
3. Pass website-scoped `targets` when one entity belongs to multiple websites.

`SeoUrlChangeService` marks local/private URLs, emits this event, syncs matching sitemap providers for each target website, and delegates URL push task creation to `UrlSubmitService`.

This event is a fact notification and is dispatched even when the website has no SEO account. Missing account only skips platform processing; sitemap asset sync still uses the Provider.

## Payload

- `targets`: preferred website-scoped URL list. Each target may contain `website_id`, `url`, `previous_url`, and `url_key`.
- `url`: current public URL when `targets` is not provided.
- `previous_url`: previous URL when the slug changed.
- `url_key`: stable URL asset key inside `website_id + scope + module`.
- `scope`: business scope.
- `module`: source module.
- `website_id` or `website_ids`: website identity when `targets` is not provided.
- `subject_type` and `subject_id`: entity identity.
- `action`: `publish`, `upsert`, `unpublish`, `delete`, or `draft`.
- `is_local_url`: true for localhost, loopback, private IP, `.localhost`, `.local`, or `.test`.
- `local_reason`: reason for the local marker.

Local URLs are still notified. Platform handlers can decide whether to submit them.
