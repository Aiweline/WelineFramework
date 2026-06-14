# seo::footer

## Purpose

Renders SEO footer-level markup before the document body closes.

## Implementation

- `view/hooks/seo/footer.phtml`

## Contract

- Use this hook only in frontend footer or body-end context.
- Implementations should render footer-safe markup through `Weline_Seo`.
- The default implementation renders `<w:seo slot="footer"/>`.
