---
name: 前端主题工程师-组件与页面构建
description: Build reusable Weline blocks, Taglibs, widgets, DataTables, storefront sections, and page assemblies. Use for component/page composition, website-to-template conversion, or visitor-marker integration; use the theme skill for template-only styling and the Taglib catalog before hand-building framework controls.
---

# Components and page composition

## Load by need

- Start with `app/code/Weline/Theme/doc/AI-INDEX.md` and the owning module's `doc/AI-INDEX.md`.
- Load `framework-taglib-catalog` for selectors, controls, or Taglib work.
- Load `visitor-pixel` only for tracking markers or provider forwarding.
- Load `ui-ux-pro-max` for a new surface, substantive redesign, or explicit UX/accessibility review—not routine composition.

## Contracts

- Choose the correct Block, Widget, Taglib, Hook, slot, partial, or page assembly boundary; do not replace a registered protocol with raw HTML.
- Reuse official Taglibs. When adding one, follow `dev/ai/skills/framework-taglib-catalog/tag-development.md` and update `dev/ai/skills/framework-taglib-catalog/tag-catalog.md`.
- Scope CSS and JS to the component root and use the Theme/weline-api worker path for business requests.
- When a reusable surface ships in both frontend and backend, bind colors to area tokens (`--weline-theme-*` vs `--backend-theme-*`) and force headings/buttons to use the component text token. Do not rely on inherited `body`/`h1–h6` colors alone.
- Trace Hook/Taglib output through the real host and registration metadata rather than inferring from surrounding chrome.
- Storefront `<section>` and `w:slot wrapper="section"` hosts must carry a non-empty semantic `weline-code`.

## Workflow

1. Identify the owning module/theme, component type, host, registration path, states, and validation page.
2. Define the primary information/action and empty/error state before implementation.
3. Implement the smallest registered component with scoped assets.
4. Verify host injection, grouping/scope attributes, request path, and any tracking marker at the rendered interaction point.
5. Validate the real page and stateful interaction.

## Validation

- Confirm loader metadata and paths resolve the component through the real page flow.
- Confirm scoped assets, visible hierarchy, empty/error guidance, and absence of duplicate tracking/bootstrap logic.
- Confirm business requests use the Theme/weline-api path.
- For changed storefront sections, run `php bin/w frontend:check-section-code` and inspect semantic `weline-code` in rendered output.
- Use collection/bootstrap evidence for registry changes when no runtime is available; do not claim hot-reload proof without WLS.
