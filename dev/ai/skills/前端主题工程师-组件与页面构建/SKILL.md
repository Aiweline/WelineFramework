---
name: 前端主题工程师-组件与页面构建
description: Frontend theme engineer skill for blocks, taglibs, widgets, and page assembly patterns.
version: 1.1.2
---

# Role

This skill builds frontend components and page assembly units such as blocks, taglibs, widgets, and reusable page sections. It keeps rendering behavior consistent with Weline component and theme conventions.

# When To Use

- Use for blocks, taglibs, widgets, DataTable rendering, visitor-tracking markup, and website-to-template conversion.
- Use for keywords such as component, widget, taglib, block, `w:widget`, `w:d-table`, website clone, and page section.
- Use when the task is to build or refactor reusable page pieces rather than only restyle existing templates.
- For browser-visible UI, layout, responsive, empty/loading/error states, usability, or visual polish work, automatically load `dev/ai/skills/ui-ux-pro-max/SKILL.md` and use it for design-system guidance before implementation, even when the user does not mention UI/UX or the skill by name.

# Source Material

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `app/code/Weline/Theme/doc/AI-INDEX.md`
- `app/code/Weline/Theme/doc/README.md`
- `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`
- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/部件开发指南.md`
- `app/code/Weline/Theme/doc/widget-slot-attributes.md`
- `app/code/Weline/Widget/doc/开发指南.md`
- `app/code/Weline/Taglib/doc/README.md`

# Responsibilities

- Build reusable rendering units with proper framework registration and naming.
- Keep component CSS and JS self-contained and scoped.
- Manage user attention deliberately: make the primary function, next action, or decision point visually obvious within the first scan, while keeping secondary controls quieter.
- Integrate tracking or download interaction patterns without duplicating page-level behavior.
- Trace visible UI injection through the real hook host or taglib host instead of inferring from visible header/footer chrome alone.
- Route component request interactions through Theme `theme.js` and the built-in `weline-api` worker chain.

# Workflow

1. Identify whether the task is a block, taglib, widget, or page-conversion request.
2. Read `dev/ai/diagrams/08-module-docs-index.txt`, `app/code/Weline/Theme/doc/AI-INDEX.md`, and the owning module's `doc/AI-INDEX.md`, then read the matching source skill material and confirm the expected directory layout.
3. For browser-visible UI work, always run or equivalently execute the `ui-ux-pro-max` design-system search and translate its output into Weline-safe visual constraints.
4. Define the attention path before coding: primary information, primary action, secondary actions, empty/error state action, and what should not compete for attention.
5. Implement the component with the correct registration path, template path, and metadata.
6. Scope CSS and JS to the component root and prefer local project assets or inline extraction-friendly assets.
7. For taglibs or hook-driven UI, verify the final contract at the host level: where the hook is rendered, how the JS is triggered, and which attributes define grouping or scope.
8. For tracking-related UI, use the approved pixel-marking pattern instead of custom duplicate tracking code.
9. Validate on the rendered page, including interactions if the component is stateful.

# Weline Rules

- Do not use JavaScript `alert`, `confirm`, or `prompt`.
- Do not hardcode user-facing text.
- Use i18n for user-facing text.
- Do not add `declare(strict_types=1)` inside `.phtml`.
- Keep component CSS and JS scoped and avoid polluting global state.
- Do not add direct frontend requests with `fetch`, `XMLHttpRequest`, `$.ajax`, axios, or equivalent helpers; declare/register component JS behavior and call the built-in `weline-api` through `theme.js` so requests run through the worker path.
- Prefer small, isolated, testable UI changes.
- Do not edit generated component registries such as collected taglib output; regenerate them from source definitions.
- When designing reusable cascades, prefer the smallest stable contract. If one grouping key already defines scope, do not keep redundant attributes alive.
- If the visible flow is owned by a scheduler or queue build path, use that owned path for final verification; do not replace whole-flow acceptance with a shortcut run that bypasses the real orchestration boundary.

# Inputs Required

- The component type, owning module or theme, and target page region.
- Expected rendering, interaction, and configuration behavior.
- Any related tracking constraints.
- Validation route or page.

# Expected Output

- A registered component, widget, or taglib unit in the correct structure.
- Scoped styles and scripts that support the component safely.
- Validation evidence showing the rendered or interactive result.

# Validation

- Confirm the component can be reached through the real page flow.
- Confirm the page or component has a clear visual priority: the main function/action is immediately noticeable, secondary actions do not visually overpower it, and empty/error states still point to the next useful action.
- Confirm JS and CSS are locally scoped and do not require forbidden browser dialogs.
- Confirm interactive requests use the Theme `theme.js` / `weline-api` worker route instead of direct browser-side HTTP calls.
- Confirm tracking markup or download hooks do not double-report events.
- Confirm component metadata and paths match the framework loader expectations.
- If WLS is not running, use collection/bootstrap smoke checks for registry-sensitive components before claiming runtime hot-reload proof.

# Constraints

- Do not replace a component contract with raw HTML if registration is required.
- Do not load third-party CDN assets casually for self-contained components.
- Do not duplicate page-level pixel dispatch logic inside business templates.
- Do not edit generated outputs instead of source component files.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.
