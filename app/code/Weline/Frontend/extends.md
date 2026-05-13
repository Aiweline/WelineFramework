# Weline Frontend Extension Points

`Weline_Frontend` exposes optional head extension points so modules can provide
page title data and title formatting policy without making Frontend depend on
Theme or SEO.

- `HeadContextProvider`: adds raw title context such as `seo_title`, `meta_title`, `site_name`, or `current_page`.
- `HeadPolicyProvider`: adjusts formatting policy such as separator, site-name position, homepage title mode, and pagination label.
