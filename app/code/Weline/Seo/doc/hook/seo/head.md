# seo::head

## Purpose

Renders SEO metadata and structured data for the document head.

## Implementation

- `view/hooks/seo/head.phtml`

## Contract

- Use this hook only in frontend document head context.
- Implementations should render head-safe markup through `Weline_Seo`.
- The default implementation renders `<w:seo slot="head"/>`.
