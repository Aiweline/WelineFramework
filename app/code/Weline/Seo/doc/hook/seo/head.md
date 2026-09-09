# seo::head

## Purpose

Compatibility extension point for additional head-safe SEO markup.

## Implementation

- `view/hooks/seo/head.phtml`

## Contract

- Use this hook only in frontend document head context.
- Core SEO tags (`title` / `description` / `canonical` / `hreflang` / OG / JSON-LD) are rendered **once** by `Weline_Frontend::templates/public/head.phtml` through `<w:seo slot="head"/>`.
- The default `Weline_Seo` hook implementation must **not** emit another `<w:seo slot="head"/>`. Doing so double-renders the SEO head group; hook/FPC output caches can also bypass `HeadRenderer` request-level dedupe.
- Other modules may register additive `seo::head` hooks for head-safe extras only.
- Layouts may keep `<w:hook>seo::head</w:hook>` as the extension point.
