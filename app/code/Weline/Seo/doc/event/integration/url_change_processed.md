# SEO URL Change Processed

`Weline_Seo::integration::url_change_processed` is emitted after `SeoUrlChangeService` finishes its decisions.

The event includes the normalized URL change payload plus `result`, containing:

- `notified`: whether the high-level notification was emitted.
- `submit`: `UrlSubmitService` result, including skipped unbound accounts or created task counts.
- `sitemap`: Provider sync stats for the affected module.
- `is_local_url` and `local_reason`: local/private URL marker.

This event is useful for diagnostics and for SEO apps that need to react after sitemap sync and URL push task routing.
