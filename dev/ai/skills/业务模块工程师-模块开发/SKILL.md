---
name: 业务模块工程师-模块开发
description: Business module engineer skill for module structure, controllers, menus, setup flows, and bounded feature delivery.
version: 1.1.2
---

# Role

This skill builds or modifies business modules in WelineFramework. It handles module structure, controllers, menus, route-aware setup, and bounded feature work while staying within module ownership instead of changing framework internals.

# When To Use

- Use for new module work, backend pages, frontend feature modules, registration files, and bounded feature delivery.
- Use for keywords such as module development, controller, menu, register, setup, feature module, and backend page.
- Use when the task belongs to one business module rather than shared framework internals.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/module-development/SKILL.md`
- `dev/ai/skills/weline-framework-core/SKILL.md`
- `dev/ai/skills/community-module/SKILLS-CONSOLIDATED.md`

# Responsibilities

- Build module-local functionality with clean structure and ownership.
- Keep controller, view, menu, and setup flows aligned inside the same module boundary.
- Route cross-module reads through supported interfaces instead of ad hoc coupling.
- For frontend module interactions, register JS behavior for Theme `theme.js` / built-in `weline-api` execution instead of issuing direct browser-side requests.
- Keep feature changes small enough to validate directly.
- Carry user-requested fake/demo data work through the real import or sync step when the request clearly includes execution, not just source edits.

# Workflow

1. Read `AI-ENTRY.md`, the diagrams, the owning module docs, and then the relevant skills.
2. Confirm the target module, area, and feature boundary.
3. Implement the required controller, view, menu, model, or env updates inside the owning module.
4. Run setup or route refresh commands only when the module change requires them.
5. If the task changes fake-data providers, seeders, or other import-backed module assets and the user asks to "导入" or otherwise execute the flow, run the module's import/sync command instead of stopping at code edits.
6. Add unit, route-level, import, or data-surface checks appropriate to the feature.
7. Update the module README when the bug or feature behavior changed materially.
8. Return focused evidence for the changed feature path.

# Weline Rules

- Do not edit `generated/` directly.
- Do not use `routes.xml`.
- Do not hardcode user-facing text.
- Use i18n for user-facing text.
- Keep module boundaries intact.
- Frontend module scripts must not use direct `fetch`, `XMLHttpRequest`, `$.ajax`, axios, or equivalent helpers; request interactions must go through Theme `theme.js` and the built-in `weline-api` worker chain.
- Update module README after fixing bugs.
- When data is written successfully but the page still looks unchanged, check the module's template source and page/controller cache path before assuming the provider or database write failed.

# Inputs Required

- Target module name and owning business context.
- Requested feature behavior and UI or API surface.
- Any affected routes, menus, models, or setup changes.
- Expected validation path for the module.

# Expected Output

- A module-local implementation aligned with Weline module structure.
- Any required setup or route refresh evidence.
- Updated module README when the change alters user-visible behavior or fix status.

# Validation

- Run `php bin/w setup:upgrade` when schema or module setup changed.
- Run `php bin/w setup:upgrade --route` when controller routing changed.
- Run focused HTTP or unit checks on the changed module path.
- Confirm user-facing text is externalized through i18n.
- Confirm frontend request interactions are registered through the theme API path and not implemented as direct browser-side HTTP calls.
- When the task is data-import driven, prove the result with the import command output plus either database evidence or the visible module surface that consumes the imported data.

# Constraints

- Do not escalate a module-local task into a framework-core refactor without reason.
- Do not directly couple to unrelated module internals for convenience.
- Do not leave feature changes unverified after setup-sensitive work.
- Do not write detailed fix reports into the repository root.
- Do not stop at provider edits when the user explicitly asked for the import to be run.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

