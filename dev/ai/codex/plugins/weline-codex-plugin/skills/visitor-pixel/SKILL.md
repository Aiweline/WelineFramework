---
name: visitor-pixel
description: Visitor pixel skill for Weline storefront markup, taglib hookup, and WeShop analytics event-marker conventions.
version: 1.0.0
---

# Role

This skill owns storefront pixel markup usage for Weline Visitor and the project-specific WeShop analytics marker contract. It keeps frontend templates aligned with the shared `pixel` taglib and avoids ad hoc tracking snippets inside business templates.

# When To Use

- Use for `weline-pixel::...` marker work, visitor tracking markup, pixel-enabled CTA buttons, and storefront analytics event wiring.
- Use for tasks mentioning pixel, visitor pixel, `Weline_Visitor::taglib_pixel`, `WelinePixel`, `add_to_cart`, `add_to_wishlist`, or `begin_checkout`.
- Use when the job is to mark key frontend elements for pixel dispatch rather than to build a provider SDK from scratch.

# Source Material

- `app/code/Weline/Visitor/Taglib/Pixel.php`
- `app/code/Weline/Visitor/view/taglib/js/pixel.phtml`
- `app/code/Weline/Visitor/doc/像素拓展使用指南.md`
- `app/code/Weline/Visitor/doc/event/访客像素标签.md`
- `app/code/WeShop/Analytics/Observer/TaglibPixelGoogleBridge.php`
- `app/code/WeShop/Analytics/Test/Unit/View/DefaultThemePixelMarkerTest.php`

# Responsibilities

- Use the shared `<pixel name="..."/>` taglib instead of duplicating bootstrap logic in templates.
- Mark key clickable storefront elements with the approved `weline-pixel::event_name` class contract.
- Keep event naming consistent with the owning integration in this repo.
- Route provider-specific behavior through `Weline_Visitor::taglib_pixel` observers instead of embedding provider code in page templates.

# Project Contract

- `Weline_Visitor` base docs show kebab-case examples such as `add-to-cart`.
- This repo's WeShop analytics bridge normalizes and expects the storefront markers already used by current templates and tests:
  - `weline-pixel::add_to_cart`
  - `weline-pixel::add_to_wishlist`
  - `weline-pixel::begin_checkout`
- Follow the existing repo convention in WeShop templates unless you are explicitly refactoring both the JS marker parser and the analytics bridge together.

# Workflow

1. Confirm whether the page already includes the shared `<pixel .../>` taglib through layout or theme composition.
2. Find the real clickable CTA element, not only its wrapper or icon.
3. Add the `weline-pixel::event_name` class on the clickable host element.
4. Only mirror the marker onto nested icons or child spans when the current click handling can target that child directly.
5. For new event families, verify the name is compatible with `TaglibPixelGoogleBridge` normalization and downstream provider mapping.
6. If provider-specific custom code is needed, implement it through a `Weline_Visitor::taglib_pixel` observer that writes `pixel_code`.
7. Validate the rendered template and confirm the marker sits on the real interaction point.

# Weline Rules

- Do not paste provider SDK snippets directly into business templates.
- Do not duplicate page-level pixel bootstrap code.
- Do not edit compiled template outputs instead of source templates.
- Keep marker placement minimal and attached to the real CTA.

# Inputs Required

- Theme or module template path.
- The CTA or interaction being tracked.
- The target event name.
- The validation page or route.

# Expected Output

- Source-template updates that add approved pixel markers to key interactive elements.
- Any required observer-level note when provider behavior must be extended through `Weline_Visitor::taglib_pixel`.
- Validation evidence showing the marker exists on the rendered interaction point.

# Validation

- Confirm the marker is on the clickable element the user actually interacts with.
- Confirm the event name matches the repo convention for that storefront flow.
- Confirm no duplicate inline provider bootstrap was introduced.
- Confirm browser-visible validation happened when runtime allows it.

# Constraints

- Do not invent a parallel `data-*` tracking contract when `weline-pixel::...` is the existing standard.
- Do not rename existing WeShop storefront pixel events from underscore to kebab case unless the whole analytics chain is being migrated.
- Do not place provider snippets inside templates that should instead flow through `Weline_Visitor::taglib_pixel`.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.
