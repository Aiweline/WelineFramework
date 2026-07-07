---
name: 前端主题工程师-主题模板开发
description: Frontend theme engineer skill for theme structure, file-convention inheritance, source-template editing, layout-safe styling, and Weline view-layer conventions.
version: 1.1.3
---

# Role

This skill owns theme-level template work, source-template editing, layout-aware styling, and view-layer conventions in WelineFramework. It ensures theme changes are made in source templates, follow theme structure, and remain compatible with the framework compiler.

# When To Use

- Use for theme directories, template overrides, layout files, source-template fixes, and theme CSS or JS organization.
- Use for keywords such as theme, template, phtml, layout, partial, override, `view/theme`, and `view/tpl`.
- Use when the work changes how a page or theme renders rather than how a backend rule behaves.
- For browser-visible UI, layout, responsive, empty/loading/error states, usability, or visual polish work, automatically load `dev/ai/skills/ui-ux-pro-max/SKILL.md` and use it for design-system guidance before implementation, even when the user does not mention UI/UX or the skill by name.

# Source Material

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `app/code/Weline/Theme/doc/AI-INDEX.md`
- `app/code/Weline/Theme/doc/README.md`
- `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/layout-discovery-guide.md`
- `app/code/Weline/Theme/view/theme/README.md`
- `app/code/Weline/Theme/doc/Theme.js使用指南.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`

# Responsibilities

- Edit source templates instead of compiled template outputs.
- Keep frontend and backend theme areas separated.
- Organize layout, partial, widget, and asset changes in the expected theme structure.
- Keep styles and scripts scoped, reusable, and consistent with theme tokens.
- Keep frontend request interactions routed through Theme `theme.js` and the built-in `weline-api` worker chain.

# Theme Inheritance Contract

- `Weline_Theme` default theme is the foundation for every site theme. A newly created empty theme must render like the default `Weline_Theme` theme through the normal theme fallback chain.
- Theme development is file-convention based: to override a default layout, partial, component, or asset, create the same relative path in the active theme, such as `app/design/Vendor/theme/frontend/partials/header/default.phtml` for `Weline_Theme/view/theme/frontend/partials/header/default.phtml`.
- If the active theme does not provide that same-path file, the resolver must fall back to the `Weline_Theme` default file. Deleting an unnecessary active-theme override is the correct way to restore default behavior.
- Do not create pass-through wrapper templates whose only purpose is to call `fetchModuleThemeHtml('Weline_Theme::...')` for inheritance. The absence of the active-theme file is the inheritance mechanism; a transparent delegate file is still an override and must be deleted.
- Only keep active-theme files that intentionally change output. For light branding or business content, prefer the existing Hook, slot, config, page-content, or asset extension point over copying an entire layout or partial.
- Layout shell ownership stays with `Weline_Theme` unless the requirement explicitly asks for a new shell. Site themes should not copy or rewrite page-wide `homepage`, `product_list`, `product`, `cart`, `checkout`, header, or footer structures just to inherit them.
- Header/footer partials belong to the default Theme shell. Do not replace them with standalone Tailwind/brand-specific whole partials unless the task explicitly asks for a new shell; customize logo, navigation, search, account, cart, announcement, brand, links, and copyright through the corresponding `Weline_Theme` partial Hooks or slots.
- `@hook-solo` may replace a content slot such as homepage/product-list/product content, but must not replace the page shell, header, footer, `<html>`, or `<body>`.
- `meta.showHeader` and `meta.showFooter` must remain true on public pages that inherit the default shell. Set them false only for an explicit headless/embedded page requirement, never as a workaround for a broken theme override.
- When a same-path override is genuinely required, copy or author only the needed source template in the active theme path and keep it aligned with the default theme contract, including slots, hooks, meta parameters, tokens, and i18n.
- Commerce themes must inventory existing `Weline_Cart`, `Weline_Checkout`, `Weline_Customer`, `Weline_Payment`, and `Weline_Shipping` surfaces before adding site code. Use those modules through inheritance, Hook, slot, SystemConfig, QueryProvider, or published interfaces; do not rebuild cart, checkout, payment, account, order, or shipping flows inside a supplier/site module unless a confirmed generic core gap is being upgraded.

# Theme Layout Selection Boundary

- Public URL routing must not choose Theme layouts with URL/query parameters such as `layout_type`, `page_type`, `layout_option`, or by routing normal storefront pages into `theme/frontend/policy`.
- A public page Controller owns layout selection through the framework controller layout setting, for example `protected ?string $layoutType = 'homepage.default';`, `product_list.default`, or `product.default`.
- Special layout variants, such as product detail layout changes, must be selected by Controller logic, events/observers, configuration, or business context. Never select them by matching arbitrary URL layout parameters.
- Theme preview/editor URLs may use preview-specific policy parameters. Do not copy that preview mechanism into production storefront routing.

# Default Theme CSS Contract

- Shared default-theme classes MUST use the `w-` prefix. This prefix means the class is owned by the Weline default theme CSS contract, similar to utility-first systems that expose framework-owned classes through a predictable namespace.
- Canonical default components are `w-btn`, `w-card`, `w-panel`, `w-widget`, `w-stat-card`, `w-total-card`, `w-metric-card`, `w-form-control`, `w-form-select`, `w-table`, `w-badge`, `w-alert`, `w-pagination`, `w-dropdown`, `w-modal`, `w-loading`, and `w-toolbar`, with modifier classes such as `w-btn-primary`, `w-btn-outline`, `w-badge-success`, `w-alert-danger`, `w-table-striped`, `w-modal-lg`, and size classes such as `w-btn-sm` / `w-btn-lg`.
- Canonical default utilities are `w-text-*`, `w-bg-*`, `w-border*`, `w-rounded*`, `w-shadow*`, and `w-focus-ring`. Use these when the intent is theme-level styling rather than module-owned BEM styling.
- Do not introduce new unprefixed global component classes for theme-owned defaults. Existing unprefixed classes such as `.btn`, `.card`, `.form-control`, `.table`, `.modal`, `.dropdown-menu`, and `.loading-component` may remain only as compatibility aliases or JS hooks for older templates and Bootstrap-style backend pages; new source templates must include the `w-*` class whenever they rely on default theme styling.
- Theme CSS MUST define tokens before component rules. Frontend uses `--weline-theme-*`; backend uses `--backend-theme-*`. Component variables such as `--weline-component-*` / `--backend-component-*` must reference those theme tokens instead of becoming a second independent system.
- Required token categories: brand/status color roles (`primary`, `on-primary`, `secondary`, `success`, `warning`, `danger`, `info`), subtle backgrounds, subtle borders, text roles, surface roles, border width/style/color, radius scale, shadow scale, focus ring, control heights, and transition timings.
- Component rules must consume tokens for border width, border style, border color, radius, text color, surfaces, shadows, focus rings, and state colors. Avoid hardcoded `1px`, hex colors, radius values, or shadow values inside reusable components unless the value is first exposed as a theme token.
- When adding markup in source templates, prefer `w-*` classes for default-theme primitives and use module-specific BEM classes only for business-specific structure or bespoke visuals.

# Workflow

1. Read `AI-ENTRY.md`, `dev/ai/diagrams/08-module-docs-index.txt`, `app/code/Weline/Theme/doc/AI-INDEX.md`, and the owning module's `doc/AI-INDEX.md` before inspecting source templates.
2. If the symptom appears in `view/tpl`, trace it back to the real source template before editing.
3. For browser-visible UI work, always run or equivalently execute the `ui-ux-pro-max` design-system search and translate its output into Weline-safe visual constraints.
4. Before editing an active-theme layout or partial, decide whether the desired result is default inheritance or a real override. If the desired result is inheritance, remove the active-theme file instead of adding a pass-through delegate.
5. For public page layout changes, inspect the Controller layout setting and relevant Hook/slot hosts before touching routing or theme shell files.
6. Implement the minimal source-template or theme-asset change in the owning theme area.
7. Keep layout-specific CSS or JS with the owning template instead of moving everything into global theme assets.
8. Use static template tags where possible and keep PHP in templates to the minimum necessary.
9. Validate through the rendered page or the closest route-level check.
10. Record affected template paths and any required theme documentation updates.

# Weline Rules

- Prefer diagrams and module docs before reading source code.
- Do not edit compiled `view/tpl` outputs directly.
- Do not add `declare(strict_types=1)` inside `.phtml`.
- Do not hardcode user-facing text.
- Use i18n for user-facing text.
- Do not use JavaScript `alert`, `confirm`, or `prompt`.
- Do not add direct frontend requests with `fetch`, `XMLHttpRequest`, `$.ajax`, axios, or equivalent helpers in theme templates or module scripts; declare/register JS behavior and call the built-in `weline-api` through `theme.js` so requests run through the worker path.
- For station-internal business APIs, use `Weline.Api.resource('provider')`, `Weline.Api.graph()`, or `Weline.Api.stream()`; do not handwrite `/api/framework/query-bin` or REST business URLs in templates.
- Do not preserve an active-theme override file when its intended behavior is identical to `Weline_Theme`; absence of the file is the correct inheritance state.
- Do not route public storefront pages to `theme/frontend/policy` or pass layout identity through URL/query parameters to get a Theme layout.

# Inputs Required

- The rendered page, theme, and source template path.
- Whether the task affects frontend or backend theme area.
- Expected visual or structural outcome.
- Validation route or page path.

# Expected Output

- A source-template or theme-level change in the correct directory.
- Scoped asset updates that match theme structure.
- Validation evidence from the real rendered surface.

# Validation

- Confirm the edit was applied to source templates, not `view/tpl` output.
- Confirm unnecessary active-theme same-path overrides were removed so fallback can reach `Weline_Theme`.
- Confirm public page layouts are selected by Controller or business context, not by URL/query layout parameters.
- Confirm inherited pages render the default Theme header/footer unless an explicit no-shell requirement exists.
- Confirm the page renders correctly on the intended route.
- Confirm styles and scripts stay scoped to the relevant theme surface.
- Confirm interactive requests use the Theme `theme.js` / `weline-api` worker route instead of direct browser-side HTTP calls.
- Confirm user-facing text remains externalized for translation.

# Constraints

- Do not maintain compiled template outputs by hand.
- Do not mix frontend and backend theme concerns in one asset path.
- Do not place layout-specific styling into unrelated global assets without need.
- Do not introduce broad visual side effects when a local template fix is sufficient.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.
