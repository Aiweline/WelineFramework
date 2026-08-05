---
name: ui-ux-pro-max
description: Searchable UI/UX design intelligence for a new Weline surface, substantive redesign, explicit UX/accessibility critique, design system, dashboard, landing page, or chart decision. Do not load it for routine copy, spacing, or narrow implementation fixes; combine it with the owning frontend skill.
---

# UI/UX design intelligence

This skill turns the bundled design database into constraints for the target Weline surface. Search results are advisory; the owning theme/component skill and current source remain implementation authorities.

## Prerequisite

Use the workspace's configured Python:

```bash
python3 --version
```

If it is unavailable, report the blocker. Do not install system software or a new project stack automatically.

## Workflow

1. Inspect the target source and identify the product type, users, primary task, information hierarchy, visible states, brand direction, accessibility needs, and confirmed frontend stack.
2. For a new surface or substantive redesign, generate a design system:

   ```bash
   python3 dev/ai/skills/ui-ux-pro-max/scripts/search.py "<product industry style keywords>" --design-system -p "<project>"
   ```

   For a narrow accessibility, chart, typography, color, layout, or interaction defect, a targeted domain search is sufficient:

   ```bash
   python3 dev/ai/skills/ui-ux-pro-max/scripts/search.py "<problem keywords>" --domain <ux|web|chart|typography|color|style|landing>
   ```

3. Use `--persist` only when a durable design-system artifact is explicitly in scope. Page overrides belong under the generated `design-system/pages/` hierarchy.
4. Translate useful recommendations into existing Weline Theme tokens, `w-*` components, Taglibs, Hooks, slots, and module-owned CSS/JS. Do not introduce Tailwind, React, a CDN, a second palette, or a parallel component system unless the target already uses it or the user explicitly requests it.
5. Define desktop/tablet/mobile behavior, loading/empty/error/success states, keyboard/focus behavior, and the primary attention path before implementation.
6. Validate the real rendered surface and interaction with the built-in Browser. Check responsive widths, visible hierarchy, contrast, focus order, console state, and the owning frontend/API constraints.

## Optional stack search

Use a stack query only when the user or target source confirms that stack:

```bash
python3 dev/ai/skills/ui-ux-pro-max/scripts/search.py "<keywords>" --stack <confirmed-stack>
```

Supported values include `html-tailwind`, `react`, `nextjs`, `vue`, `svelte`, `swiftui`, `react-native`, `flutter`, `shadcn`, and `jetpack-compose`. Ordinary Weline Theme work has no Tailwind default.

## Quality bar

- The main task/action is obvious in the first scan; secondary controls remain quieter.
- Components use consistent spacing, typography, tokens, icon sizing, and interaction states.
- Hover/focus changes do not shift layout; controls expose appropriate cursor, keyboard, and focus behavior.
- Light/dark contrast and text hierarchy remain legible.
- Responsive layouts avoid horizontal overflow and preserve usable touch targets.
- Loading, empty, error, disabled, and success states explain the next useful action.
- Decorative recommendations never weaken performance, accessibility, i18n, or framework ownership.

Read [references/search-and-quality.md](references/search-and-quality.md) only when domain/stack choices or the expanded review checklist are needed.
