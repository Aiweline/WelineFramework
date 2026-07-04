# SEO URL Submit Request

`Weline_Seo::integration::url_submit_request` is the low-level event for modules that already know the changed public URL and want SEO to enqueue URL push tasks.

Recommended higher-level integrations should prefer calling `SeoUrlChangeService`, because it also syncs sitemap Provider assets. Use this low-level event only when the caller intentionally wants URL push task creation.

## Payload

- `targets`: preferred website-scoped URL list. Each target may contain `website_id`, `url`, `previous_url`, and `url_key`.
- `url` or `urls`: public URL or URL list when `targets` is not provided.
- `scope`: business scope, such as `cms_page`, `product`, `blog`.
- `website_id`: website ID when known.
- `website_ids`: multiple website IDs when one business entity belongs to multiple websites.
- `module`: source module.
- `subject_type` and `subject_id`: entity identity.
- `action`: `upsert`, `publish`, `unpublish`, or `delete`.

The SEO module checks site bindings and platform capabilities before creating `push_urls` tasks. If no platform is bound, no task is created.
