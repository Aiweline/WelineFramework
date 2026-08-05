---
name: visitor-pixel
description: Apply Weline Visitor pixel markup, the shared Pixel Taglib, and storefront analytics event-marker conventions to CTAs and commerce events. Use for weline-pixel markers, Weline_Visitor::taglib_pixel, conversion events, CTA forwarding, or section attribution; not for provider SDK/backend analytics.
---

# Visitor pixel markers

## Contract

- Include the shared `<pixel name="..."/>` Taglib; never paste provider SDK/bootstrap code into business templates.
- Put `weline-pixel::event_name` on the real clickable element. Use `weline-pixel::event_name:value` or `data-pixel-value` for numeric value.
- `data-pixel-event`, `data-visitor-event`, and `data-cta-event` are explicit forwarding names, not replacements for the standard class marker. `data-ga-event` is legacy-only.
- Provider enable/name behavior belongs to `Weline_Visitor::taglib_pixel`; `pixel_code` is non-executable.
- The nearest `<section weline-code="...">` supplies `section_code`, `{code}:{event}`, and `section_source_status`; new/changed storefront sections require a stable semantic code.

## Workflow

1. Inspect the shared parser and owning Visitor documentation for the current event contract.
2. Confirm page-level Taglib inclusion and locate the actual click host.
3. Add the minimum class/value/forwarding marker, preserving established underscore event names such as `add_to_cart`.
4. Validate the rendered interaction, emitted event/value, provider forwarding, and section attribution.
5. When section templates change, run `php bin/w frontend:check-section-code`.

## Validation

- One user action produces the intended event without duplicate bootstrap/dispatch.
- Marker/value is on the interaction element the runtime reads.
- New code introduces neither `data-ga-event` nor executable provider snippets.
- `section_source_status=ok` for newly edited interactions; missing/empty code is a template defect.

Current sources: `app/code/Weline/Visitor/doc/像素拓展使用指南.md`, `app/code/Weline/Visitor/doc/event/访客像素标签.md`, and `app/code/Weline/Visitor/view/taglib/js/pixel.phtml`.
