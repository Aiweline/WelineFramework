---
name: 业务模块工程师-模块开发
description: Build or modify a bounded Weline business module, including registration, controllers, menus, setup flow, backend pages, and module-owned features. Use when ownership stays in one business module; use framework-core skills for shared infrastructure and frontend skills for theme-only rendering.
---

# Business module development

## Boundary

- Keep controllers, views, menus, setup, services, and data ownership inside the owning module.
- Use published contracts for cross-module collaboration; do not promote a module-local need into framework core without a shared defect.
- Browser business interactions use the Theme/weline-api chain; do not add direct browser HTTP clients.
- If the user explicitly requests import/sync execution, completion includes running the owning command, not only editing its provider.

## Workflow

1. Read the module's `doc/AI-INDEX.md` and only the linked material relevant to the feature.
2. Confirm the module, area, public behavior, data owner, routes, menus, and setup impact.
3. Implement the smallest module-owned change.
4. Refresh setup or routes only when their source changed.
5. Validate through the closest route, data, command, Browser, import, or focused test surface.
6. Update owning documentation only when a public setting, interface, behavior contract, or usage workflow changed.

## Module-specific checks

- A successful data write with unchanged UI may indicate stale template/controller/cache output; check that path before rewriting the provider.
- For import-backed work, prove both command completion and the stored or visible consumer result.
- For browser operations, prefer `const Api = await Weline.Api.resource('provider')` followed by the declared operation.
- Report the exact changed surface and decisive evidence; do not create fix-status diaries.
