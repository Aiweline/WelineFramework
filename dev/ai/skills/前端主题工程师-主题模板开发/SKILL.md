---
name: 前端主题工程师-主题模板开发
description: Implement Weline theme inheritance, source-template/layout overrides, phtml, storefront sections, and theme-owned CSS/JS. Use when page rendering or a theme contract changes; use the component skill for reusable blocks/widgets and the API skill for browser business requests.
---

# Theme template development

## Load by need

- Start with `app/code/Weline/Theme/doc/AI-INDEX.md` and the owning module's documentation index.
- Use `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md` for fallback/override work, `app/code/Weline/Theme/doc/layout-discovery-guide.md` for layout tracing, and `app/code/Weline/Theme/doc/frontend-section-weline-code.md` for storefront sections.
- Load Theme.js/API docs only when interaction or transport changes.
- Load `ui-ux-pro-max` for new surfaces, substantive redesigns, or explicit UX/accessibility review—not routine template fixes.

## Core contracts

- Edit source templates, never compiled `view/tpl` output.
- The absence of a same-path active-theme file enables fallback to `Weline_Theme`; delete accidental/transparent overrides instead of creating pass-through wrappers.
- Keep the default shell, header, and footer unless the requirement explicitly creates a new shell. Prefer existing Hook, slot, config, Widget, component, or token extension points.
- Public Controllers select layouts. Do not choose normal storefront layouts through URL/query parameters or `theme/frontend/policy`.
- Localized public paths may contain currency, language, or both in either order. Reuse the shared parser; do not duplicate prefix stripping or consult allowed-values during early route parsing.
- Default-theme classes use the established `w-*`/`weline-*` contract and Theme tokens. Do not create a parallel global palette/component system for one site.
- **Area token split is hard**: storefront/shared frontend surfaces use `--weline-theme-*`; backend admin surfaces use `--backend-theme-*`. A component that renders in both areas must switch by an explicit area attribute (for example `[data-*-manager="backend"]`) and must set local heading/text colors from those tokens so `bootstrap-dark` `h1–h6` rules cannot paint light text onto a light fallback card.
- Backend module pages should reuse the Admin shell (`page-title-box` + `card` / `card-body`) instead of inventing a second white card chrome that ignores backend dark mode.
- Storefront `<section>` and `w:slot wrapper="section"` hosts require a non-empty semantic `weline-code`.

## Workflow

1. Trace the rendered page to its real source template, Controller layout, Hook/slot hosts, and fallback chain.
2. Decide whether the desired result is inheritance, an intentional override, or a new extension point.
3. For palette/spacing/surface changes, inspect Theme metadata and tokens before adding CSS.
4. Implement the smallest source-template or theme-asset change in the owning area; keep local assets scoped.
5. Use the Theme/weline-api worker path for business requests and the owning specialist skill for components or API behavior.
6. Validate the actual rendered route, interaction, console, and responsive surface.
7. Update Theme/module documentation only when an inheritance, layout, token, or public usage contract changed.

## Validation

- Confirm no compiled output or unnecessary same-path override remains.
- Confirm public layout selection comes from Controller/business context.
- For localized routing changes, exercise currency-only, language-only, currency/language, and language/currency forms.
- Confirm inherited pages keep the expected shell and scoped assets.
- Confirm palette changes use Theme tokens rather than private global copies.
- For changed sections, run `php bin/w frontend:check-section-code` and inspect semantic uniqueness in rendered output.
