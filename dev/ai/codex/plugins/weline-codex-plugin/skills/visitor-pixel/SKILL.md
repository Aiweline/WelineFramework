---
name: visitor-pixel
description: Visitor pixel skill for Weline storefront markup, taglib hookup, and WeShop analytics event-marker conventions.
version: 1.0.0
---

# Role

This skill owns storefront pixel markup usage for Weline Visitor and the project-specific analytics event-marker contract. It keeps frontend templates aligned with the shared `pixel` taglib, the real frontend event parser, and the module's CTA forwarding rules instead of ad hoc tracking snippets inside business templates.

# When To Use

- Use for `weline-pixel::...` marker work, visitor tracking markup, pixel-enabled CTA buttons, and storefront analytics event wiring.
- Use for tasks mentioning pixel, visitor pixel, `Weline_Visitor::taglib_pixel`, `WelinePixel`, `add_to_cart`, `add_to_wishlist`, or `begin_checkout`.
- Use when the job is to mark key frontend elements for pixel dispatch rather than to build a provider SDK from scratch.

# Source Material

- `app/code/Weline/Visitor/Taglib/Pixel.php`
- `app/code/Weline/Visitor/view/taglib/js/pixel.phtml`
- `app/code/Weline/Visitor/doc/像素拓展使用指南.md`
- `app/code/Weline/Visitor/doc/event/访客像素标签.md`
- `app/code/Weline/Visitor/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml`

# Responsibilities

- Use the shared `<pixel name="..."/>` taglib instead of duplicating bootstrap logic in templates.
- Treat `weline-pixel::event_name` as the module's standard custom pixel-event marker for business interactions.
- Use `weline-pixel::event_name:value` or `data-pixel-value` when the event carries a numeric value.
- Use `data-pixel-event`, `data-visitor-event`, or `data-cta-event` only as explicit CTA/forwarding event names that the Visitor runtime and analytics panel can read.
- Keep event naming consistent with the owning integration in this repo.
- Route provider-specific behavior through `Weline_Visitor::taglib_pixel` observers for enable/name control, not inline page snippets.

# Project Contract

- The real parser in `view/taglib/js/pixel.phtml` walks the DOM upward and extracts the first class starting with `weline-pixel::`, excluding `:value` suffixes.
- Numeric event value resolution is driven by `weline-pixel::event_name:value` first, then `data-pixel-value`, then nearby price/summary selectors.
- The runtime also reads explicit CTA attributes in this order: `data-pixel-event`, `data-visitor-event`, `data-cta-event`, `data-ga-event`.
- `data-ga-event` is legacy compatibility only; new code should not introduce it.
- For standard storefront conversion flows already present in repo templates, keep the underscore event names currently used by source templates, such as:
  - `weline-pixel::add_to_cart`
  - `weline-pixel::add_to_wishlist`
- For generic CTA forwarding with no explicit event name, GA4 fallback uses the configured default CTA event name from SystemConfig.

# Workflow

1. Confirm whether the page already includes the shared `<pixel .../>` taglib through layout or theme composition.
2. Find the real clickable CTA element, not only its wrapper or icon.
3. If you are tracking a real business interaction, add `weline-pixel::event_name` on the clickable host element.
4. If the event needs an amount or similar numeric payload, add `weline-pixel::event_name:value` near the interaction point or provide `data-pixel-value`.
5. Only add `data-pixel-event`, `data-visitor-event`, or `data-cta-event` when you need an explicit CTA/forwarding event name for panel inspection or downstream forwarding.
6. Only mirror markers onto nested icons or child spans when the current click handling can target that child directly.
7. For new event families, verify the name is compatible with Visitor runtime normalization and downstream forwarding rules.
8. If provider-specific behavior is needed, use `Weline_Visitor::taglib_pixel` only for enable/name control; do not rely on `pixel_code` as executable frontend JS.
9. Validate the rendered template and confirm the marker sits on the real interaction point.

# Weline Rules

- Do not paste provider SDK snippets directly into business templates.
- Do not duplicate page-level pixel bootstrap code.
- Do not edit compiled template outputs instead of source templates.
- Keep marker placement minimal and attached to the real CTA.
- Treat `pixel_code` as non-executable in current Visitor runtime.

# Inputs Required

- Theme or module template path.
- The CTA or interaction being tracked.
- The target event name.
- The validation page or route.

# Expected Output

- Source-template updates that add approved pixel markers and value markers to key interactive elements.
- Explicit CTA attributes only when the flow needs them.
- Any required observer-level note when provider behavior must be extended through `Weline_Visitor::taglib_pixel`.
- Validation evidence showing the marker exists on the rendered interaction point.

# Validation

- Confirm the marker is on the clickable element the user actually interacts with.
- Confirm class-based events use `weline-pixel::event_name` and value carriers use `weline-pixel::event_name:value` or `data-pixel-value`.
- Confirm any `data-pixel-event` / `data-visitor-event` / `data-cta-event` usage is deliberate CTA naming rather than a replacement for the standard pixel class marker.
- Confirm the event name matches the repo convention for that storefront flow.
- Confirm no duplicate inline provider bootstrap was introduced.
- Confirm browser-visible validation happened when runtime allows it.

# Constraints

- Do not replace standard custom event markers with `data-pixel-event` when the module expects `weline-pixel::...` for business event discovery.
- Do not introduce `data-ga-event` in new code.
- Do not rename existing storefront pixel events from underscore to kebab case unless the whole analytics chain is being migrated.
- Do not place provider snippets inside templates that should instead flow through `Weline_Visitor::taglib_pixel`.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.
