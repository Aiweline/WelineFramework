# seo::body

## Purpose

Renders SEO body-level markup immediately after the document body starts.

## Implementation

- `view/hooks/seo/body.phtml`

## Contract

- Use this hook only in frontend document body context.
- Implementations should render body-safe markup through `Weline_Seo`.
- The default implementation renders `<w:seo slot="body"/>`.
